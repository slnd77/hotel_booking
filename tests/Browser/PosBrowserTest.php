<?php

use App\Enums\OutletBillStatus;
use App\Enums\OutletType;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Outlet;
use App\Models\OutletBill;
use App\Models\OutletGuestOrder;
use App\Models\OutletItem;
use App\Models\OutletPricePlan;
use App\Models\OutletTable;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->property = Property::factory()->create(['timezone' => 'UTC']);
    $this->outlet = Outlet::factory()->create(['property_id' => $this->property->id, 'name' => 'Bistro', 'type' => OutletType::FoodAndBeverages, 'qr_ordering' => true]);
    $this->soup = OutletItem::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'Tomato Soup']);
    $plan = OutletPricePlan::factory()->create(['outlet_id' => $this->outlet->id]);
    $plan->prices()->create(['outlet_item_id' => $this->soup->id, 'price' => 100]);
    $this->table = OutletTable::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'T7', 'pos_x' => 1, 'pos_y' => 0]);
});

test('a table is billed from the floor plan, split, and settled between two payers', function () {
    $this->actingAs($this->admin);

    $page = visit(route('admin.pos.show', [$this->property, 'outlet' => $this->outlet->id]))
        ->assertSee('Green: free')
        ->click("@pos-table-{$this->table->id}")
        ->click('Tomato Soup')
        ->click('Tomato Soup')
        ->click('Open bill')
        ->assertSee('opened.');

    $bill = OutletBill::query()->sole();
    expect($bill->outlet_table_id)->toBe($this->table->id);
    $line = $bill->items()->sole();

    $page->click('@split-bill')
        ->click("@split-more-{$line->id}")
        ->click('@split-submit')
        ->assertSee('split: the moved items are on bill')
        ->click('Settle')
        ->type('@split-ways', '2')
        ->click('@split-evenly')
        ->assertSee('Paid 100.00 of 100.00')
        ->click('@settle-submit')
        ->assertSee('settled.')
        ->assertNoJavaScriptErrors();

    $new = OutletBill::query()->where('split_from_id', $bill->id)->sole();

    expect($new->status)->toBe(OutletBillStatus::Settled)
        ->and(Payment::query()->where('collection_source', 'POS')->pluck('guest_name')->all())->toBe(['Guest 1', 'Guest 2']);
});

test('a guest orders from the table QR code and the order is accepted on the POS', function () {
    visit(route('table-order.show', $this->table->qrToken()))
        ->assertSee('Table T7')
        ->click('Add')
        ->click('@send-order')
        ->assertSee('Your order has been sent.')
        ->assertSee('Waiting for the staff')
        ->assertNoJavaScriptErrors();

    $order = OutletGuestOrder::query()->sole();
    $this->actingAs($this->admin);

    visit(route('admin.pos.show', [$this->property, 'outlet' => $this->outlet->id]))
        ->assertSee('1 QR order(s) waiting')
        ->click("@accept-order-{$order->id}")
        ->assertSee('added to bill')
        ->assertNoJavaScriptErrors();

    expect($order->refresh()->status)->toBe(OutletGuestOrder::ACCEPTED);
});
