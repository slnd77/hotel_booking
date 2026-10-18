<?php

use App\Models\Booking;
use App\Models\ChargeCode;
use App\Models\Currency;
use App\Models\Guest;
use App\Models\Language;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BookingLedgerSync;
use App\Services\GuestLedger;
use App\Services\PaymentReceipts;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create();
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    Room::factory()->count(3)->create(['room_category_id' => $this->category->id]);

    // Within 7 days of check-in: 50% of what is taken off; earlier: free.
    $this->property->cancellationRules()->create([
        'start_time_stamp' => now()->subYear(),
        'end_time_stamp' => now()->addYear(),
        'rules' => [['day_from_checkin' => 7, 'penalty_percent' => 50], ['day_from_checkin' => 999999, 'penalty_percent' => 0]],
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function changePayload(RoomCategory $category, string $ref, int $rooms, array $overrides = []): array
{
    return [
        'booking_ref_id' => $ref,
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => now()->addDays(2)->toDateString(),
        'check_out' => now()->addDays(4)->toDateString(),
        'status' => 'confirmed',
        'discount' => 0,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
        'rooms' => array_fill(0, $rooms, ['room_category_id' => $category->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]),
        ...$overrides,
    ];
}

/**
 * A two-room booking paid in full.
 */
function paidBooking(Property $property, RoomCategory $category, string $ref = 'CHG-1'): Booking
{
    test()->actingAs(test()->admin)->post(route('admin.bookings.store', $property), changePayload($category, $ref, 2))->assertSessionHasNoErrors();
    $booking = Booking::query()->where('booking_ref_id', $ref)->sole();

    app(PaymentReceipts::class)->record($property->id, ['booking_id' => $booking->id, 'guest_name' => 'Guest', 'payment_mode' => 'Cash', 'total_txn_amount' => (float) $booking->total], 'Staff');
    app(BookingLedgerSync::class)->sync($booking);

    expect($booking->refresh()->outstandingBalance())->toEqual(0.0);

    return $booking;
}

test('taking a room off charges the policy on it and refunds the rest of the credit', function () {
    $booking = paidBooking($this->property, $this->category);
    $total = (float) $booking->total;
    $takenOff = $total / 2;
    $charge = round($takenOff * 0.5, 2);

    $this->actingAs($this->admin)
        ->put(route('admin.bookings.update', [$this->property, $booking]), changePayload($this->category, 'CHG-1', 1, [
            'apply_cancellation_charge' => true,
            'credit_action' => 'refund',
            'refund_mode' => 'Cash',
            'refund_reference' => 'CASH-OUT-1',
        ]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'cancellation charge '.number_format($charge, 2).' (50%)') && str_contains($message, 'refund'));

    $refund = Payment::query()->where('type', 'refund')->sole();

    expect(Transaction::query()->whereHas('chargeCode', fn ($query) => $query->where('code', ChargeCode::CANCELLATION))->sole()->amount)->toEqual($charge)
        ->and((float) $refund->total_txn_amount)->toEqual(round($takenOff - $charge, 2))
        ->and($refund->payment_ref_id)->toBe('CASH-OUT-1')
        ->and($booking->refresh()->outstandingBalance())->toEqual(0.0);
});

test('a waived charge leaves the whole credit, which can be kept as an advance on the receipt', function () {
    $booking = paidBooking($this->property, $this->category);
    $takenOff = (float) $booking->total / 2;

    $this->actingAs($this->admin)
        ->put(route('admin.bookings.update', [$this->property, $booking]), changePayload($this->category, 'CHG-1', 1, [
            'apply_cancellation_charge' => false,
            'credit_action' => 'advance',
        ]))
        ->assertSessionHasNoErrors();

    $receipt = Payment::query()->where('type', 'receipt')->where('total_txn_amount', '>', 0)->sole();

    expect(Transaction::query()->whereHas('chargeCode', fn ($query) => $query->where('code', ChargeCode::CANCELLATION))->exists())->toBeFalse()
        ->and((float) $receipt->untagged_amount)->toEqual($takenOff)
        ->and((float) $receipt->tagged_amount)->toEqual($takenOff)
        ->and($booking->refresh()->outstandingBalance())->toEqual(0.0);
});

test('the credit can move to another booking of the property', function () {
    $booking = paidBooking($this->property, $this->category);
    $takenOff = (float) $booking->total / 2;
    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), changePayload($this->category, 'CHG-2', 1, [
        'check_in' => now()->addDays(10)->toDateString(),
        'check_out' => now()->addDays(12)->toDateString(),
    ]));
    $other = Booking::query()->where('booking_ref_id', 'CHG-2')->sole();
    $otherOwed = $other->outstandingBalance();

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.edit', [$this->property, $booking]))
        ->assertInertia(fn ($page) => $page->where('changeSettlement.transfer_targets', fn ($targets): bool => collect($targets)->pluck('booking_ref_id')->all() === ['CHG-2']));

    $this->actingAs($this->admin)
        ->put(route('admin.bookings.update', [$this->property, $booking]), changePayload($this->category, 'CHG-1', 1, [
            'apply_cancellation_charge' => false,
            'credit_action' => 'transfer',
            'transfer_booking_id' => $other->id,
        ]))
        ->assertSessionHasNoErrors();

    expect($booking->refresh()->outstandingBalance())->toEqual(0.0)
        ->and($other->refresh()->outstandingBalance())->toEqual(round($otherOwed - $takenOff, 2));
});

test('the credit can move to the guest ledger and pay a later booking of the guest', function () {
    $guest = Guest::factory()->create(['property_id' => $this->property->id]);
    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), changePayload($this->category, 'CHG-1', 2, ['guest_ids' => [$guest->id]]))->assertSessionHasNoErrors();
    $booking = Booking::query()->where('booking_ref_id', 'CHG-1')->sole();
    app(PaymentReceipts::class)->record($this->property->id, ['booking_id' => $booking->id, 'guest_name' => 'Guest', 'payment_mode' => 'Cash', 'total_txn_amount' => (float) $booking->total], 'Staff');
    app(BookingLedgerSync::class)->sync($booking);
    $takenOff = (float) $booking->total / 2;

    $this->actingAs($this->admin)
        ->put(route('admin.bookings.update', [$this->property, $booking]), changePayload($this->category, 'CHG-1', 1, [
            'guest_ids' => [$guest->id],
            'apply_cancellation_charge' => false,
            'credit_action' => 'guest_ledger',
        ]))
        ->assertSessionHasNoErrors();

    $ledger = app(GuestLedger::class);

    expect($booking->refresh()->outstandingBalance())->toEqual(0.0)
        ->and($ledger->balance($this->property->id, $guest))->toEqual($takenOff);

    // A later booking of the guest uses part of it from the booking page.
    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), changePayload($this->category, 'CHG-3', 1, [
        'guest_ids' => [$guest->id],
        'check_in' => now()->addDays(20)->toDateString(),
        'check_out' => now()->addDays(21)->toDateString(),
    ]));
    $later = Booking::query()->where('booking_ref_id', 'CHG-3')->sole();
    $owed = $later->outstandingBalance();

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.show', [$this->property, $later]))
        ->assertInertia(fn ($page) => $page
            ->where('guestLedger.balance', fn ($balance): bool => (float) $balance === $takenOff)
            ->where('guestLedger.booking_owed', fn ($value): bool => (float) $value === $owed)
            // Add Payment offers paying from the ledger, on the booking page and in the edit form.
            ->where('guestLedgerBalance', fn ($balance): bool => (float) $balance === $takenOff));

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.edit', [$this->property, $later]))
        ->assertInertia(fn ($page) => $page->where('billing.guestLedgerBalance', fn ($balance): bool => (float) $balance === $takenOff));

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.guestLedger.apply', [$this->property, $later]), ['amount' => 500])
        ->assertSessionHas('success');

    expect($later->refresh()->outstandingBalance())->toEqual(round($owed - 500, 2))
        ->and((float) $later->outstanding)->toEqual(round($owed - 500, 2))
        ->and($ledger->balance($this->property->id, $guest))->toEqual(round($takenOff - 500, 2));

    // Never more than the ledger holds or the booking owes.
    $this->actingAs($this->admin)
        ->post(route('admin.bookings.guestLedger.apply', [$this->property, $later]), ['amount' => 999999])
        ->assertSessionHas('error');
});

test('a refund without a mode is refused before anything is saved', function () {
    $booking = paidBooking($this->property, $this->category);

    $this->actingAs($this->admin)
        ->put(route('admin.bookings.update', [$this->property, $booking]), changePayload($this->category, 'CHG-1', 1, ['credit_action' => 'refund']))
        ->assertSessionHasErrors('refund_mode');

    expect($booking->rooms()->count())->toBe(2);
});

test('the edit form gets the saved bill, the balance and the policy for a change made today', function () {
    $booking = paidBooking($this->property, $this->category);

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.edit', [$this->property, $booking]))
        ->assertInertia(fn ($page) => $page
            ->where('changeSettlement.saved_bill', fn ($bill): bool => (float) $bill === (float) $booking->total)
            ->where('changeSettlement.balance', fn ($balance): bool => (float) $balance === 0.0)
            ->where('changeSettlement.days_before', 2)
            ->where('changeSettlement.tiers.0.days', 7)
            ->where('changeSettlement.tiers.0.percent', fn ($percent): bool => (float) $percent === 50.0));
});
