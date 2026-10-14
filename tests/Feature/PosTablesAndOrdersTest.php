<?php

use App\Enums\OutletBillStatus;
use App\Enums\OutletType;
use App\Enums\PaymentStatus;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Outlet;
use App\Models\OutletBill;
use App\Models\OutletGuestOrder;
use App\Models\OutletItem;
use App\Models\OutletKot;
use App\Models\OutletPricePlan;
use App\Models\OutletTable;
use App\Models\OutletTaxRate;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use App\Services\OutletBills;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->property = Property::factory()->create();
    $this->outlet = Outlet::factory()->create(['property_id' => $this->property->id, 'name' => 'Bistro', 'type' => OutletType::FoodAndBeverages]);
    $tax = OutletTaxRate::factory()->create(['outlet_id' => $this->outlet->id, 'rate' => 5]);
    $this->soup = OutletItem::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'Soup', 'outlet_tax_rate_id' => $tax->id]);
    $this->bread = OutletItem::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'Bread']);
    $plan = OutletPricePlan::factory()->create(['outlet_id' => $this->outlet->id]);
    $plan->prices()->createMany([['outlet_item_id' => $this->soup->id, 'price' => 200], ['outlet_item_id' => $this->bread->id, 'price' => 50]]);
    $this->table = OutletTable::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'T1']);
    $this->actingAs($this->admin);
});

/**
 * An open bill for table T1: 2 soups (200 + 5% each) and 4 breads (50 each), sent to the kitchen.
 */
function planTableBill(object $test): OutletBill
{
    $test->post(route('admin.pos.bills.store', [$test->property, $test->outlet]), [
        'outlet_table_id' => $test->table->id,
        'items' => [['outlet_item_id' => $test->soup->id, 'quantity' => 2], ['outlet_item_id' => $test->bread->id, 'quantity' => 4]],
    ])->assertSessionHasNoErrors();

    $bill = OutletBill::query()->latest('id')->firstOrFail();
    $test->post(route('admin.pos.bills.kots.store', [$test->property, $bill]))->assertSessionHasNoErrors();

    return $bill->refresh();
}

test('tables are added, placed on the floor plan and each gets its ordering QR code', function () {
    $this->post(route('admin.outlets.tables.store', [$this->property, $this->outlet]), ['name' => 'T2', 'seats' => 6, 'area' => 'Terrace', 'shape' => 'round', 'status' => true])->assertSessionHasNoErrors();
    $this->post(route('admin.outlets.tables.store', [$this->property, $this->outlet]), ['name' => 'T2', 'seats' => 2, 'shape' => 'square'])->assertSessionHasErrors('name');

    $t2 = OutletTable::query()->where('name', 'T2')->sole();
    $this->put(route('admin.outlets.tables.layout', [$this->property, $this->outlet]), ['tables' => [['id' => $t2->id, 'pos_x' => 3, 'pos_y' => 1]]])->assertSessionHasNoErrors();

    expect($t2->refresh())->pos_x->toBe(3)->pos_y->toBe(1)->area->toBe('Terrace');

    $qr = $this->get(route('admin.outlets.tables.qr', [$this->property, $this->outlet, $t2]))->assertOk();
    expect($qr->headers->get('X-Order-Url'))->toBe(route('table-order.show', $t2->refresh()->qr_token));

    $this->get(route('admin.outlets.show', [$this->property, $this->outlet]))
        ->assertInertia(fn ($page) => $page->has('tables', 2)->where('outlet.qr_ordering', false));
});

test('a bill opened from a table carries it, and moves to another table', function () {
    $bill = planTableBill($this);
    $t2 = OutletTable::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'T2']);

    expect($bill)->outlet_table_id->toBe($this->table->id)->table_number->toBe('T1');

    $this->get(route('admin.pos.show', [$this->property, 'outlet' => $this->outlet->id]))
        ->assertInertia(fn ($page) => $page->where('tables.0.open_bill_ids', [$bill->id])->where('tables.1.open_bill_ids', []));

    $this->post(route('admin.pos.bills.moveTable', [$this->property, $bill]), ['outlet_table_id' => $t2->id])->assertSessionHasNoErrors();

    expect($bill->refresh())->outlet_table_id->toBe($t2->id)->table_number->toBe('T2');
});

test('splitting a bill moves items, their kitchen tickets and a share of the discount to a new bill', function () {
    $bill = planTableBill($this);
    $bill = app(OutletBills::class)->setItems($bill, [['outlet_item_id' => $this->soup->id, 'quantity' => 2], ['outlet_item_id' => $this->bread->id, 'quantity' => 4]], 60);
    $soupLine = $bill->items->firstWhere('outlet_item_id', $this->soup->id);
    $breadLine = $bill->items->firstWhere('outlet_item_id', $this->bread->id);

    // Moving everything isn't a split.
    $this->post(route('admin.pos.bills.split', [$this->property, $bill]), ['items' => [['id' => $soupLine->id, 'quantity' => 2], ['id' => $breadLine->id, 'quantity' => 4]]])
        ->assertSessionHasErrors('items');

    $this->post(route('admin.pos.bills.split', [$this->property, $bill]), ['items' => [['id' => $soupLine->id, 'quantity' => 1], ['id' => $breadLine->id, 'quantity' => 2]]])
        ->assertSessionHasNoErrors();

    $new = OutletBill::query()->where('split_from_id', $bill->id)->sole();
    $bill->refresh();

    // 600 billed, 60 off: the 300 moved takes 30 of the discount.
    expect($new)->outlet_table_id->toBe($this->table->id)->status->toBe(OutletBillStatus::Open)
        ->and((float) $new->sub_total)->toBe(300.0)
        ->and((float) $new->discount)->toBe(30.0)
        ->and((float) $bill->sub_total)->toBe(300.0)
        ->and((float) $bill->discount)->toBe(30.0)
        // The kitchen has the moved items on the new bill; nothing is pending or printed twice.
        ->and(app(OutletBills::class)->sentToKitchen($new)->pluck('quantity', 'name')->all())->toBe(['Soup' => 1, 'Bread' => 2])
        ->and(app(OutletBills::class)->sentToKitchen($bill)->pluck('quantity', 'name')->all())->toBe(['Soup' => 1, 'Bread' => 2])
        ->and(OutletKot::query()->whereNull('printed_at')->where('notes', 'like', 'Moved%')->count())->toBe(0);

    $this->post(route('admin.pos.bills.kots.store', [$this->property, $new]))->assertSessionHasErrors('bill');
});

test('a bill is settled with payments split between payers, and voiding cancels them all', function () {
    $bill = planTableBill($this);
    // 400 + 20 tax + 200 = 620.

    $this->post(route('admin.pos.bills.settle', [$this->property, $bill]), ['payments' => [
        ['payer' => 'Asha', 'payment_mode' => 'Cash', 'amount' => 300],
        ['payer' => 'Ravi', 'payment_mode' => 'UPI', 'amount' => 300],
    ]])->assertSessionHasErrors('payments');

    $this->post(route('admin.pos.bills.settle', [$this->property, $bill]), ['payments' => [
        ['payer' => 'Asha', 'payment_mode' => 'Cash', 'amount' => 310],
        ['payer' => 'Ravi', 'payment_mode' => 'UPI', 'amount' => 310, 'reference' => 'UPI-9'],
    ]])->assertSessionHasNoErrors();

    $payments = Payment::query()->where('collection_source', 'POS')->orderBy('id')->get();

    expect($bill->refresh()->status)->toBe(OutletBillStatus::Settled)
        ->and($payments->pluck('guest_name')->all())->toBe(['Asha', 'Ravi'])
        ->and($payments->sum('total_txn_amount'))->toEqual(620)
        ->and($bill->subFolio->balance())->toBe(0.0);

    $this->post(route('admin.pos.bills.void', [$this->property, $bill]), ['reason' => 'Wrong table'])->assertSessionHasNoErrors();

    expect(Payment::query()->where('collection_source', 'POS')->pluck('status')->unique()->all())->toBe([PaymentStatus::Cancelled]);
});

test('a guest orders from the table QR code and staff accept it onto the table\'s bill', function () {
    $token = $this->table->qrToken();
    auth()->logout();

    // Ordering is off until the outlet turns it on.
    $this->post(route('table-order.store', $token), ['items' => [['outlet_item_id' => $this->soup->id, 'quantity' => 1]]])->assertSessionHasErrors('items');

    $this->outlet->update(['qr_ordering' => true]);

    $this->get(route('table-order.show', $token))
        ->assertInertia(fn ($page) => $page->component('TableOrder/Show')->where('table', 'T1')->where('ordering', true)->has('items', 2));

    // Prices come from the price plan, whatever the page could send.
    $this->post(route('table-order.store', $token), ['guest_name' => 'Meera', 'notes' => 'No onions', 'items' => [['outlet_item_id' => $this->soup->id, 'quantity' => 2, 'unit_price' => 1]]])
        ->assertSessionHasNoErrors();

    $order = OutletGuestOrder::query()->sole();
    expect($order)->status->toBe(OutletGuestOrder::PENDING)->and((float) $order->total)->toBe(400.0);

    $this->get(route('table-order.show', $token))->assertInertia(fn ($page) => $page->where('orders.0.status', 'pending'));

    $this->actingAs($this->admin);
    $this->get(route('admin.pos.show', [$this->property, 'outlet' => $this->outlet->id]))->assertInertia(fn ($page) => $page->where('guestOrders.0.table', 'T1'));

    $this->post(route('admin.pos.guestOrders.accept', [$this->property, $order]), ['send_kot' => true])->assertSessionHasNoErrors();

    $bill = OutletBill::query()->where('outlet_table_id', $this->table->id)->sole();

    expect($order->refresh())->status->toBe(OutletGuestOrder::ACCEPTED)->outlet_bill_id->toBe($bill->id)
        ->and((float) $bill->sub_total)->toBe(400.0)
        ->and($bill->kots()->sole()->notes)->toBe('QR order: No onions');

    // A second order adds to the same open bill.
    $second = OutletGuestOrder::query()->create(['property_id' => $this->property->id, 'outlet_id' => $this->outlet->id, 'outlet_table_id' => $this->table->id, 'status' => 'pending', 'items' => [['outlet_item_id' => $this->bread->id, 'name' => 'Bread', 'quantity' => 1, 'unit_price' => 50]], 'total' => 50]);
    $this->post(route('admin.pos.guestOrders.accept', [$this->property, $second]))->assertSessionHasNoErrors();
    expect((float) $bill->refresh()->sub_total)->toBe(450.0);

    $third = OutletGuestOrder::query()->create(['property_id' => $this->property->id, 'outlet_id' => $this->outlet->id, 'outlet_table_id' => $this->table->id, 'status' => 'pending', 'items' => [], 'total' => 0]);
    $this->post(route('admin.pos.guestOrders.reject', [$this->property, $third]), ['reason' => 'Kitchen closed'])->assertSessionHasNoErrors();
    expect($third->refresh())->status->toBe(OutletGuestOrder::REJECTED)->reject_reason->toBe('Kitchen closed');
});

test('a table can only have a few orders waiting at once', function () {
    $this->outlet->update(['qr_ordering' => true]);
    $token = $this->table->qrToken();

    foreach (range(1, 5) as $ignored) {
        $this->post(route('table-order.store', $token), ['items' => [['outlet_item_id' => $this->bread->id, 'quantity' => 1]]])->assertSessionHasNoErrors();
    }

    $this->post(route('table-order.store', $token), ['items' => [['outlet_item_id' => $this->bread->id, 'quantity' => 1]]])
        ->assertSessionHasErrors(['items' => 'This table already has orders waiting for the staff. Please wait for them to be confirmed.']);
});
