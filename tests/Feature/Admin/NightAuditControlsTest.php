<?php

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\NightAudit;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\BookingLedgerSync;
use App\Services\NightAudits;
use App\Services\NightAuditSettings;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    // 11:30 on 02/10 in India.
    $this->travelTo(CarbonImmutable::parse('2026-10-02 06:00:00', 'UTC'));

    $this->property = Property::factory()->create(['timezone' => 'Asia/Kolkata']);
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['room_category_id' => $this->category->id, 'name' => '101']);
    $this->settings = app(NightAuditSettings::class);
});

function controlsBooking(Property $property, RoomCategory $category, Room $room, string $checkIn, string $checkOut, BookingStatus $status = BookingStatus::Confirmed): Booking
{
    $booking = $property->bookings()->create([
        'booking_ref_id' => 'NC-'.fake()->unique()->numerify('####'), 'source' => 'direct', 'type' => 'walk_in', 'status' => $status,
        'check_in' => $checkIn, 'check_out' => $checkOut,
        'room_total' => 1000, 'discount' => 0, 'taxes' => 0, 'total' => 1000, 'outstanding' => 1000,
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
    ]);
    $booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $room->id, 'meal_plan' => 'EP', 'adults' => 1, 'rate_per_night' => 1000]);

    return $booking;
}

function controlsPayment(Property $property, string $utc, array $attributes = []): Payment
{
    return Payment::create([
        'property_id' => $property->id, 'type' => 'receipt', 'status' => PaymentStatus::Received, 'guest_name' => 'Guest',
        'payment_mode' => 'Cash', 'total_txn_amount' => 500, 'booking_source' => 'Direct',
        'payment_creation_date' => $utc, 'payment_transaction_date' => $utc,
        ...$attributes,
    ]);
}

function closeDay(Property $property, string $date): void
{
    NightAudit::factory()->create(['property_id' => $property->id, 'business_date' => $date]);
}

test('with the freeze on, a stay that ended on a closed day can no longer be changed', function () {
    $this->settings->save($this->property, ['freeze_past_dated_data' => true]);
    closeDay($this->property, '2026-10-01');
    $ended = controlsBooking($this->property, $this->category, $this->room, '2026-09-29', '2026-10-01');

    $this->actingAs($this->admin)
        ->patch(route('admin.bookings.updateStatus', [$this->property, $ended]), ['status' => 'cancelled'])
        ->assertSessionHas('error', 'Business dates up to 01/10/2026 are closed by the night audit and frozen.');

    expect($ended->fresh()->status)->toBe(BookingStatus::Confirmed);

    // A guest still in house past a closed day (an overstay) can still be checked out.
    $overstay = controlsBooking($this->property, $this->category, Room::factory()->create(['room_category_id' => $this->category->id]), '2026-09-29', '2026-10-01', BookingStatus::CheckedIn);
    $this->actingAs($this->admin)
        ->patch(route('admin.bookings.updateStatus', [$this->property, $overstay]), ['status' => 'checked_out'])
        ->assertSessionMissing('error');

    expect($overstay->fresh()->status)->toBe(BookingStatus::CheckedOut);
});

test('with the freeze on, nothing new is dated into a closed day', function () {
    $this->settings->save($this->property, ['freeze_past_dated_data' => true]);
    closeDay($this->property, '2026-10-01');

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), ['check_in' => '2026-10-01', 'check_out' => '2026-10-03'])
        ->assertSessionHas('error');

    // A payment backdated to 23:30 on 01/10 in India, the closed day.
    $this->actingAs($this->admin)
        ->post(route('admin.databases.payments.store', ['property' => $this->property->id]), ['payment_transaction_date' => '2026-10-01T23:30:00+05:30', 'guest_name' => 'Late', 'payment_mode' => 'Cash', 'total_txn_amount' => 100])
        ->assertSessionHas('error');

    expect(Payment::query()->where('guest_name', 'Late')->exists())->toBeFalse();
});

test('with the freeze on, a payment dated on a closed day can\'t be cancelled; one dated today can', function () {
    $this->settings->save($this->property, ['freeze_past_dated_data' => true]);
    closeDay($this->property, '2026-10-01');
    // 01/10 23:59 and 02/10 00:01 in India.
    $closed = controlsPayment($this->property, '2026-10-01 18:29:00');
    $open = controlsPayment($this->property, '2026-10-01 18:31:00');

    $this->actingAs($this->admin)->post(route('admin.databases.payments.cancel', $closed))->assertSessionHas('error');
    $this->actingAs($this->admin)->post(route('admin.databases.payments.cancel', $open))->assertSessionHas('success');

    expect($closed->fresh()->status)->toBe(PaymentStatus::Received)
        ->and($open->fresh()->status)->toBe(PaymentStatus::Cancelled);
});

test('without the freeze, closed days can still be changed', function () {
    closeDay($this->property, '2026-10-01');
    $payment = controlsPayment($this->property, '2026-10-01 10:00:00');

    $this->actingAs($this->admin)->post(route('admin.databases.payments.cancel', $payment))->assertSessionHas('success');

    expect($payment->fresh()->status)->toBe(PaymentStatus::Cancelled);
});

test('with payment reconciliation on, the day only closes once its payments are reconciled', function () {
    $this->settings->save($this->property, ['payment_reconciliation' => true, 'current_rollover_date' => '2026-10-01']);
    $payment = controlsPayment($this->property, '2026-10-01 10:00:00');
    controlsPayment($this->property, '2026-10-02 10:00:00'); // Next day: not part of 01/10.

    $this->actingAs($this->admin)
        ->get(route('admin.nightAudits.edit', $this->property))
        ->assertInertia(fn ($page) => $page->where('paymentReconciliation', true)->has('paymentsToReconcile', 1)->where('paymentsToReconcile.0.id', $payment->id));

    $audit = app(NightAudits::class)->run($this->property, CarbonImmutable::parse('2026-10-01'));
    expect($audit->status)->toBe(NightAudit::FAILED)
        ->and($audit->error)->toBe('1 payment(s) of 01/10/2026 are not reconciled yet.');

    $this->actingAs($this->admin)
        ->post(route('admin.nightAudits.reconcile', $this->property), ['payment_ids' => [$payment->id], 'reconciled' => true])
        ->assertSessionHas('success', '1 payment(s) reconciled.');

    expect($payment->fresh()->reconciled_by)->toBe($this->admin->id)
        ->and(app(NightAudits::class)->run($this->property, CarbonImmutable::parse('2026-10-01'))->status)->toBe(NightAudit::COMPLETED);
});

test('only the property\'s own payments can be reconciled', function () {
    $other = controlsPayment(Property::factory()->create(), '2026-10-01 10:00:00');

    $this->actingAs($this->admin)
        ->post(route('admin.nightAudits.reconcile', $this->property), ['payment_ids' => [$other->id], 'reconciled' => true])
        ->assertSessionHasErrors('payment_ids.0');

    expect($other->fresh()->reconciled_at)->toBeNull();
});

test('admin pages warn the configured minutes before an automatic rollover', function () {
    $this->settings->save($this->property, ['rollover_type' => 'automated', 'current_rollover_date' => '2026-10-01', 'default_rollover_time' => '02:00', 'pre_rollover_notification' => 30]);
    controlsBooking($this->property, $this->category, $this->room, '2026-10-01', '2026-10-03');
    $notices = fn () => $this->actingAs($this->admin)->get(route('admin.nightAudits.edit', $this->property))->inertiaProps('nightAuditNotices');

    // 01:20 in India: not yet.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 19:50:00', 'UTC'));
    expect($notices())->toBe([]);

    // 01:40: 20 minutes to go.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 20:10:00', 'UTC'));
    expect($notices())->toBe([[
        'property_id' => $this->property->id,
        'property_name' => $this->property->name,
        'business_date' => '2026-10-01',
        'rollover_at' => '2026-10-01T20:30:00Z',
        'pending_arrivals' => 1,
        'unreconciled_payments' => null,
        'open_pos_bills' => null,
    ]]);

    // Night audit off: no notice.
    $this->settings->save($this->property, ['rollover_type' => 'off']);
    expect($notices())->toBe([]);
});

test('the night audit re-syncs a folio ledger that fell behind its booking and says so', function () {
    $booking = controlsBooking($this->property, $this->category, $this->room, '2026-10-01', '2026-10-03', BookingStatus::CheckedIn);
    // A total changed without the model events (as when a sync fails) leaves the ledger behind.
    Booking::query()->whereKey($booking->id)->update(['total' => 1500]);

    expect(app(BookingLedgerSync::class)->isOutOfStep($booking->fresh()))->toBeTrue();

    $audit = app(NightAudits::class)->run($this->property, CarbonImmutable::parse('2026-10-02'));

    expect($audit->status)->toBe(NightAudit::COMPLETED)
        ->and($audit->summary['warnings'])->toContain("Folio ledger was out of step and has been re-synced for booking(s) {$booking->booking_ref_id}.")
        ->and($booking->fresh()->outstandingBalance())->toEqual(1500.0)
        ->and(app(BookingLedgerSync::class)->isOutOfStep($booking->fresh()))->toBeFalse();
});

test('a folio ledger that cannot be re-synced keeps the day open', function () {
    $booking = controlsBooking($this->property, $this->category, $this->room, '2026-10-01', '2026-10-03', BookingStatus::CheckedIn);
    $this->partialMock(BookingLedgerSync::class, fn ($mock) => $mock->shouldReceive('isOutOfStep')->andReturnTrue());

    $audit = app(NightAudits::class)->run($this->property, CarbonImmutable::parse('2026-10-02'));

    expect($audit->status)->toBe(NightAudit::FAILED)
        ->and($audit->error)->toContain("booking(s) {$booking->booking_ref_id} does not match the booking");
});

test('a ledger in step adds no night audit warning', function () {
    controlsBooking($this->property, $this->category, $this->room, '2026-10-01', '2026-10-03', BookingStatus::CheckedIn);

    $audit = app(NightAudits::class)->run($this->property, CarbonImmutable::parse('2026-10-02'));

    expect($audit->status)->toBe(NightAudit::COMPLETED)
        ->and($audit->summary['warnings'])->toBe([]);
});
