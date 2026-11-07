<?php

use App\Enums\BookingStatus;
use App\Enums\FolioStatus;
use App\Enums\OutletBillStatus;
use App\Enums\OutletType;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\ChargeCode;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Outlet;
use App\Models\OutletBill;
use App\Models\OutletItem;
use App\Models\OutletPricePlan;
use App\Models\OutletTaxRate;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create();
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id]);

    $this->outlet = Outlet::factory()->create(['property_id' => $this->property->id, 'name' => 'Cafe', 'type' => OutletType::FoodAndBeverages]);
    $taxRate = OutletTaxRate::factory()->create(['outlet_id' => $this->outlet->id, 'rate' => 5]);
    $this->coffee = OutletItem::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'Coffee', 'outlet_tax_rate_id' => $taxRate->id]);
    $this->cake = OutletItem::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'Cake']);
    $plan = OutletPricePlan::factory()->create(['outlet_id' => $this->outlet->id]);
    $plan->prices()->createMany([['outlet_item_id' => $this->coffee->id, 'price' => 100], ['outlet_item_id' => $this->cake->id, 'price' => 250]]);
});

function inHouseBooking(Property $property, RoomCategory $category, BookingStatus $status = BookingStatus::CheckedIn): Booking
{
    // In one transaction, like the booking form: the ledger syncs once it commits.
    return DB::transaction(function () use ($property, $category, $status): Booking {
        $booking = Booking::create([
            'property_id' => $property->id,
            'booking_ref_id' => 'INHOUSE1',
            'check_in' => now()->toDateString(),
            'check_out' => now()->addDays(2)->toDateString(),
            'status' => $status,
            'room_total' => 2000,
            'total' => 2000,
            'outstanding' => 2000,
            'room_bill_to' => 'to_guest',
            'extras_bill_to' => 'to_guest',
        ]);
        $booking->rooms()->create(['room_category_id' => $category->id, 'meal_plan' => 'EP', 'adults' => 2, 'rate_per_night' => 1000]);

        return $booking;
    })->refresh();
}

/**
 * Opens a bill of 2 coffees (100 + 5% tax each) and a cake (250, no tax): 450 + 10 tax.
 */
function openCafeBill($test): OutletBill
{
    $test->actingAs($test->admin)
        ->post(route('admin.pos.bills.store', [$test->property, $test->outlet]), [
            'guest_name' => 'Asha',
            'items' => [['outlet_item_id' => $test->coffee->id, 'quantity' => 2], ['outlet_item_id' => $test->cake->id, 'quantity' => 1]],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.pos.show', [$test->property, 'outlet' => $test->outlet->id]));

    return OutletBill::query()->latest('id')->firstOrFail();
}

test('a bill is priced from the price plan with the item tax, and a discount lowers the tax too', function () {
    $bill = openCafeBill($this);

    expect($bill->bill_number)->toBe('1')
        ->and($bill->status)->toBe(OutletBillStatus::Open)
        ->and($bill->business_date->toDateString())->toBe($this->property->today())
        ->and((float) $bill->sub_total)->toBe(450.0)
        ->and((float) $bill->tax_total)->toBe(10.0)
        ->and((float) $bill->total)->toBe(460.0)
        ->and($bill->items)->toHaveCount(2);

    $this->actingAs($this->admin)
        ->put(route('admin.pos.bills.update', [$this->property, $bill]), [
            'discount' => 45,
            'items' => [['outlet_item_id' => $this->coffee->id, 'quantity' => 2], ['outlet_item_id' => $this->cake->id, 'quantity' => 1]],
        ])
        ->assertSessionHasNoErrors();

    $bill->refresh();
    expect((float) $bill->discount)->toBe(45.0)
        ->and((float) $bill->tax_total)->toBe(9.0)
        ->and((float) $bill->total)->toBe(414.0);

    // An item of another outlet, or one with no price today, can't be billed.
    $other = OutletItem::factory()->create(['outlet_id' => $this->outlet->id, 'name' => 'Unpriced']);
    $this->actingAs($this->admin)
        ->put(route('admin.pos.bills.update', [$this->property, $bill]), ['items' => [['outlet_item_id' => $other->id, 'quantity' => 1]]])
        ->assertSessionHasErrors('items.0.outlet_item_id');
});

test('posting a bill to a checked-in room charges its folio, and a payment taken with it is credited there', function () {
    $booking = inHouseBooking($this->property, $this->category);
    $room = $booking->rooms()->sole();
    $bill = openCafeBill($this);

    expect((float) $booking->outstanding)->toBe(2000.0);

    $this->actingAs($this->admin)
        ->post(route('admin.pos.bills.postToRoom', [$this->property, $bill]), [
            'sub_booking_id' => $room->id,
            'payment' => ['payment_mode' => 'Cash', 'amount' => 160],
        ])
        ->assertSessionHasNoErrors();

    $bill->refresh();
    $window = $bill->subFolio;

    expect($bill->status)->toBe(OutletBillStatus::Posted)
        ->and($bill->booking_id)->toBe($booking->id)
        ->and($window->folio->owner->is($room))->toBeTrue()
        ->and($bill->transactions()->pluck('amount', 'charge_code_id')->map(fn ($amount): float => (float) $amount)->all())->toBe([
            ChargeCode::system(ChargeCode::POS_FNB)->id => 450.0,
            ChargeCode::system(ChargeCode::POS_FNB_TAX)->id => 10.0,
        ]);

    $payment = $bill->payment;
    expect($payment->status)->toBe(PaymentStatus::Received)
        ->and((float) $payment->total_txn_amount)->toBe(160.0)
        ->and($payment->collection_source)->toBe('POS')
        ->and($payment->transactions()->sole()->sub_folio_id)->toBe($window->id)
        // Room 2000 + bill 460 − payment 160.
        ->and((float) $booking->refresh()->outstanding)->toBe(2300.0);

    // A posted bill is closed.
    $this->actingAs($this->admin)
        ->put(route('admin.pos.bills.update', [$this->property, $bill]), ['items' => [['outlet_item_id' => $this->coffee->id, 'quantity' => 1]]])
        ->assertSessionHasErrors('bill');
});

test('a bill can only be posted to a checked-in room of the property', function () {
    $booking = inHouseBooking($this->property, $this->category, BookingStatus::Confirmed);
    $bill = openCafeBill($this);

    $this->actingAs($this->admin)
        ->post(route('admin.pos.bills.postToRoom', [$this->property, $bill]), ['sub_booking_id' => $booking->rooms()->sole()->id])
        ->assertSessionHasErrors('sub_booking_id');

    $otherBooking = inHouseBooking(Property::factory()->create(), $this->category);

    $this->actingAs($this->admin)
        ->post(route('admin.pos.bills.postToRoom', [$this->property, $bill]), ['sub_booking_id' => $otherBooking->rooms()->sole()->id])
        ->assertNotFound();

    expect($bill->refresh()->status)->toBe(OutletBillStatus::Open);
});

test('a bill settled at the outlet has its own settled folio holding the sale and the payment', function () {
    $bill = openCafeBill($this);

    $this->actingAs($this->admin)
        ->post(route('admin.pos.bills.settle', [$this->property, $bill]), ['payment_mode' => 'UPI', 'reference' => 'UPI-42'])
        ->assertSessionHasNoErrors();

    $bill->refresh();
    $folio = $bill->folio;

    expect($bill->status)->toBe(OutletBillStatus::Settled)
        ->and($folio->status)->toBe(FolioStatus::Settled)
        ->and($folio->balance())->toBe(0.0)
        ->and($bill->payment->payment_mode->value)->toBe('UPI')
        ->and((float) $bill->payment->total_txn_amount)->toBe(460.0)
        ->and($bill->payment->booking_id)->toBeNull();
});

test('voiding a posted bill reverses its charges and cancels its payment', function () {
    $booking = inHouseBooking($this->property, $this->category);
    $bill = openCafeBill($this);

    $this->actingAs($this->admin)
        ->post(route('admin.pos.bills.postToRoom', [$this->property, $bill]), ['sub_booking_id' => $booking->rooms()->sole()->id, 'payment' => ['payment_mode' => 'Cash', 'amount' => 460]])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->post(route('admin.pos.bills.void', [$this->property, $bill]), ['reason' => 'Wrong room'])
        ->assertSessionHasNoErrors();

    $bill->refresh();

    expect($bill->status)->toBe(OutletBillStatus::Void)
        ->and($bill->void_reason)->toBe('Wrong room')
        ->and($bill->payment->status)->toBe(PaymentStatus::Cancelled)
        ->and($bill->transactions()->get()->sum(fn ($entry): float => $entry->transaction_type->value === 'debit' ? (float) $entry->amount : -(float) $entry->amount))->toBe(0.0)
        ->and((float) $booking->refresh()->outstanding)->toBe(2000.0);
});

test('voiding a settled bill needs the undo settle permission', function () {
    $bill = openCafeBill($this);
    $this->actingAs($this->admin)->post(route('admin.pos.bills.settle', [$this->property, $bill]), ['payment_mode' => 'Cash']);

    $cashier = User::factory()->create();
    $cashier->assignRole(Role::findOrCreate('Front Office', 'web'));
    $cashier->givePermissionTo([Permission::findOrCreate('pos.post_transaction', 'web'), Permission::findOrCreate('pos.cancel_order', 'web')]);

    $this->actingAs($cashier)
        ->post(route('admin.pos.bills.void', [$this->property, $bill]), ['reason' => 'Mistake'])
        ->assertForbidden();

    $this->actingAs($this->admin)
        ->post(route('admin.pos.bills.void', [$this->property, $bill]), ['reason' => 'Mistake'])
        ->assertSessionHasNoErrors();

    expect($bill->refresh()->status)->toBe(OutletBillStatus::Void)
        ->and($bill->folio->balance())->toBe(0.0)
        ->and(Payment::query()->findOrFail($bill->payment_id)->status)->toBe(PaymentStatus::Cancelled);
});

test('the POS page lists the outlet items with today\'s price and the in-house rooms', function () {
    inHouseBooking($this->property, $this->category);

    $this->actingAs($this->admin)
        ->get(route('admin.pos.show', $this->property))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/pos/Show')
            ->where('outletId', $this->outlet->id)
            ->where('items.0.price', fn ($price): bool => in_array((float) $price, [100.0, 250.0], true))
            ->has('inHouseRooms', 1)
            ->where('inHouseRooms.0.booking_ref_id', 'INHOUSE1'));
});
