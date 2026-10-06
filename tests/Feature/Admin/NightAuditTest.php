<?php

use App\Enums\BookingStatus;
use App\Enums\SubBookingStatus;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\NightAudit;
use App\Models\Payment;
use App\Models\Property;
use App\Models\PropertyCancellationRule;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
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

/**
 * @param  array<string, mixed>  $attributes
 */
function auditBooking(Property $property, RoomCategory $category, Room $room, string $checkIn, string $checkOut, array $attributes = []): Booking
{
    $nights = CarbonImmutable::parse($checkIn)->diffInDays(CarbonImmutable::parse($checkOut));
    $booking = $property->bookings()->create([
        'booking_ref_id' => 'NA-'.fake()->unique()->numerify('####'), 'source' => 'direct', 'type' => 'walk_in', 'status' => BookingStatus::Confirmed,
        'check_in' => $checkIn, 'check_out' => $checkOut,
        'room_total' => 1000 * $nights, 'discount' => 0, 'taxes' => 0, 'total' => 1000 * $nights, 'outstanding' => 1000 * $nights,
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        ...$attributes,
    ]);
    $booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $room->id, 'meal_plan' => 'EP', 'adults' => 1, 'rate_per_night' => 1000]);

    return $booking;
}

function arrivalDayPolicy(Property $property, float $penaltyPercent): void
{
    PropertyCancellationRule::create([
        'property_id' => $property->id,
        'start_time_stamp' => now()->subYear(),
        'end_time_stamp' => now()->addYear(),
        'rules' => [
            ['day_from_checkin' => 7, 'penalty_percent' => 0],
            ['day_from_checkin' => 1, 'penalty_percent' => $penaltyPercent],
        ],
    ]);
}

test('the settings page saves the rollover configuration and logs what changed', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.nightAuditSettings.edit', $this->property))
        ->assertInertia(fn ($page) => $page
            ->component('admin/night-audit-settings/Edit')
            ->where('settings.rollover_type', 'off')
            ->where('settings.current_rollover_date', '2026-10-02')
            ->has('rolloverTypes', 2));

    $this->actingAs($this->admin)
        ->put(route('admin.nightAuditSettings.update', $this->property), [
            'rollover_type' => 'automated', 'current_rollover_date' => '2026-10-01', 'default_rollover_time' => '02:00',
            'excess_payment_tolerance' => 5, 'validate_excess_payment' => true, 'room_rollover_start_date' => '2026-09-01',
        ])
        ->assertSessionHas('success');

    expect($this->settings->get($this->property))
        ->rollover_type->toBe('automated')
        ->default_rollover_time->toBe('02:00')
        ->excess_payment_tolerance->toBe(5.0)
        ->validate_excess_payment->toBeTrue();

    $this->actingAs($this->admin)
        ->get(route('admin.nightAuditSettings.edit', $this->property))
        ->assertInertia(fn ($page) => $page->where('activityLogs', fn ($logs): bool => collect($logs)->contains('action', 'Rollover Types changed from Night Audit Off to Automated Night Audit')));
});

test('the settings reject a bad rollover type, time or a date too far ahead', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.nightAuditSettings.update', $this->property), [
            'rollover_type' => 'sometimes', 'current_rollover_date' => '2026-10-10', 'default_rollover_time' => '25:00', 'excess_payment_tolerance' => 1,
        ])
        ->assertSessionHasErrors(['rollover_type', 'current_rollover_date', 'default_rollover_time']);
});

test('the automated night audit closes a day only once the rollover time has passed in the property\'s timezone', function () {
    $this->settings->save($this->property, ['rollover_type' => 'automated', 'current_rollover_date' => '2026-10-01', 'default_rollover_time' => '02:00']);
    $newYork = Property::factory()->create(['timezone' => 'America/New_York']);
    $this->settings->save($newYork, ['rollover_type' => 'automated', 'current_rollover_date' => '2026-10-01', 'default_rollover_time' => '02:00']);

    // 01:59 on 02/10 in India (20:29 UTC on 01/10).
    $this->travelTo(CarbonImmutable::parse('2026-10-01 20:29:00', 'UTC'));
    $this->artisan('bookings:night-audit')->assertSuccessful();
    expect(NightAudit::query()->count())->toBe(0);

    // 02:01 in India: 01/10 closes there, but it's still 16:31 on 01/10 in New York.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 20:31:00', 'UTC'));
    $this->artisan('bookings:night-audit')->assertSuccessful();

    $audit = NightAudit::query()->sole();
    expect($audit->property_id)->toBe($this->property->id)
        ->and($audit->business_date->toDateString())->toBe('2026-10-01')
        ->and($audit->status)->toBe(NightAudit::COMPLETED)
        ->and($audit->run_by)->toBeNull()
        ->and($this->settings->currentRolloverDate($this->property)->toDateString())->toBe('2026-10-02');

    // 02:00 EDT on 02/10 in New York is 06:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-02 06:01:00', 'UTC'));
    $this->artisan('bookings:night-audit')->assertSuccessful();
    expect($newYork->nightAudits()->sole()->business_date->toDateString())->toBe('2026-10-01');
});

test('with the night audit off nothing closes on its own', function () {
    $this->settings->save($this->property, ['current_rollover_date' => '2026-09-25']);

    $this->artisan('bookings:night-audit')->assertSuccessful();

    expect(NightAudit::query()->count())->toBe(0);
});

test('an arrival that never came becomes a no-show charged the arrival-day penalty, and its other nights are released', function () {
    arrivalDayPolicy($this->property, 50);
    $booking = auditBooking($this->property, $this->category, $this->room, '2026-10-01', '2026-10-04');
    $unavailableLater = fn (): array => $this->actingAs($this->admin)
        ->getJson(route('admin.bookings.roomAvailability', ['property' => $this->property, 'check_in' => '2026-10-02', 'check_out' => '2026-10-04']))
        ->assertOk()
        ->json('unavailable_room_ids');
    expect($unavailableLater())->toContain($this->room->id);

    $audit = app(NightAudits::class)->run($this->property, CarbonImmutable::parse('2026-10-01'));

    $booking->refresh();
    $stay = $booking->rooms()->sole();
    expect($audit->status)->toBe(NightAudit::COMPLETED)
        ->and($booking->status)->toBe(BookingStatus::NoShow)
        ->and($stay->status)->toBe(SubBookingStatus::NoShow)
        ->and($stay->check_out_date->toDateString())->toBe('2026-10-02')
        ->and((float) $booking->total)->toBe(1500.0)
        ->and($booking->check_out->toDateString())->toBe('2026-10-02')
        ->and($booking->outstandingBalance())->toBe(1500.0)
        ->and($audit->summary['no_shows'][0])->toMatchArray(['booking_ref_id' => $booking->booking_ref_id, 'room' => '101', 'stay_amount' => 3000, 'penalty_percent' => 50, 'fee' => 1500])
        ->and($audit->summary['report']['no_show_revenue'])->toEqual(1500);

    // The room is free again from the second night.
    expect($unavailableLater())->not->toContain($this->room->id);
});

test('without a cancellation policy a no-show is not charged and the audit says so', function () {
    $booking = auditBooking($this->property, $this->category, $this->room, '2026-10-01', '2026-10-03');

    $audit = app(NightAudits::class)->run($this->property, CarbonImmutable::parse('2026-10-01'));

    expect($booking->fresh()->status)->toBe(BookingStatus::NoShow)
        ->and((float) $booking->fresh()->total)->toBe(0.0)
        ->and($audit->summary['warnings'])->toBe(['No cancellation policy applies, so no-shows were not charged.']);
});

test('arrivals before the room rollover start date are left alone', function () {
    $this->settings->save($this->property, ['room_rollover_start_date' => '2026-10-01']);
    $old = auditBooking($this->property, $this->category, $this->room, '2026-09-20', '2026-09-21');

    app(NightAudits::class)->run($this->property, CarbonImmutable::parse('2026-10-01'));

    expect($old->fresh()->status)->toBe(BookingStatus::Confirmed);
});

test('guests in house past their departure are listed as overstays, not changed', function () {
    $booking = auditBooking($this->property, $this->category, $this->room, '2026-09-29', '2026-10-01');
    $booking->rooms()->sole()->update(['status' => SubBookingStatus::CheckedIn]);
    $booking->forceFill(['status' => BookingStatus::CheckedIn])->saveQuietly();

    $audit = app(NightAudits::class)->run($this->property, CarbonImmutable::parse('2026-10-01'));

    expect($audit->summary['overstays'])->toHaveCount(1)
        ->and($audit->summary['overstays'][0])->toMatchArray(['booking_ref_id' => $booking->booking_ref_id, 'room' => '101', 'check_out_date' => '2026-10-01'])
        ->and($booking->rooms()->sole()->status)->toBe(SubBookingStatus::CheckedIn)
        ->and($booking->fresh()->check_out->toDateString())->toBe('2026-10-01');
});

test('the day-end report counts occupancy, ADR, RevPAR and the day\'s receipts in property time', function () {
    $booking = auditBooking($this->property, $this->category, $this->room, '2026-09-30', '2026-10-02');
    $booking->rooms()->sole()->update(['status' => SubBookingStatus::CheckedIn]);
    $booking->forceFill(['status' => BookingStatus::CheckedIn])->saveQuietly();

    $receipt = fn (string $utc, string $mode, float $amount) => Payment::create([
        'property_id' => $this->property->id, 'type' => 'receipt', 'status' => 'Received', 'guest_name' => 'Guest',
        'payment_mode' => $mode, 'total_txn_amount' => $amount, 'booking_source' => 'Direct',
        'payment_creation_date' => $utc, 'payment_transaction_date' => $utc,
    ]);
    $receipt('2026-09-30 18:31:00', 'Cash', 500);   // 01/10 00:01 in India
    $receipt('2026-10-01 18:29:00', 'UPI', 300);    // 01/10 23:59 in India
    $receipt('2026-10-01 18:31:00', 'Cash', 999);   // 02/10 in India: next day

    $audit = app(NightAudits::class)->run($this->property, CarbonImmutable::parse('2026-10-01'));
    $report = $audit->summary['report'];
    $available = Room::query()->whereHas('roomCategory', fn ($query) => $query->where('property_id', $this->property->id))->count();

    expect($report['rooms_available'])->toBe($available)
        ->and($report['rooms_sold'])->toBe(1)
        ->and($report['room_revenue'])->toEqual(1000)
        ->and($report['adr'])->toEqual(1000)
        ->and($report['revpar'])->toEqual(round(1000 / $available, 2))
        ->and($report['occupancy_percent'])->toEqual(round(100 / $available, 2))
        ->and($report['in_house'])->toBe(1)
        ->and($report['receipts_total'])->toEqual(800)
        ->and($report['receipts_by_mode'])->toBe([
            ['mode' => 'Cash', 'count' => 1, 'amount' => 500],
            ['mode' => 'UPI', 'count' => 1, 'amount' => 300],
        ]);
});

test('running a closed day again changes nothing', function () {
    arrivalDayPolicy($this->property, 50);
    $booking = auditBooking($this->property, $this->category, $this->room, '2026-10-01', '2026-10-04');
    $audits = app(NightAudits::class);

    $first = $audits->run($this->property, CarbonImmutable::parse('2026-10-01'));
    $second = $audits->run($this->property, CarbonImmutable::parse('2026-10-01'));

    expect($second->id)->toBe($first->id)
        ->and(NightAudit::query()->count())->toBe(1)
        ->and((float) $booking->fresh()->total)->toBe(1500.0);
});

test('the front desk runs the night audit for the current business date, but not for a day that hasn\'t started', function () {
    $this->settings->save($this->property, ['current_rollover_date' => '2026-10-01']);

    $this->actingAs($this->admin)
        ->get(route('admin.nightAudits.edit', $this->property))
        ->assertInertia(fn ($page) => $page->where('currentRolloverDate', '2026-10-01')->where('canRunNow', true)->where('automated', false));

    $this->actingAs($this->admin)->post(route('admin.nightAudits.store', $this->property))->assertSessionHas('success');

    $audit = NightAudit::query()->sole();
    expect($audit->run_by)->toBe($this->admin->id)
        ->and($audit->business_date->toDateString())->toBe('2026-10-01');

    // Today (02/10) can be closed early; then 03/10 hasn't started.
    $this->actingAs($this->admin)->post(route('admin.nightAudits.store', $this->property))->assertSessionHas('success');
    $this->actingAs($this->admin)->post(route('admin.nightAudits.store', $this->property))->assertSessionHasErrors('business_date');

    expect(NightAudit::query()->count())->toBe(2);

    $this->actingAs($this->admin)
        ->get(route('admin.nightAudits.show', [$this->property, $audit]))
        ->assertInertia(fn ($page) => $page->component('admin/night-audits/Show')->where('audit.business_date', '2026-10-01'));
});

test('"Validate Excess Payment" stops the night audit while a booking is overpaid beyond the tolerance', function () {
    $this->settings->save($this->property, ['validate_excess_payment' => true, 'excess_payment_tolerance' => 1]);
    $booking = auditBooking($this->property, $this->category, $this->room, '2026-10-01', '2026-10-02', ['status' => BookingStatus::CheckedIn]);
    $payment = Payment::create([
        'property_id' => $this->property->id, 'booking_id' => $booking->id, 'type' => 'receipt', 'status' => 'Received', 'guest_name' => 'Guest',
        'payment_mode' => 'Cash', 'total_txn_amount' => 1500, 'tagged_amount' => 1500, 'booking_source' => 'Direct',
        'payment_creation_date' => now(), 'payment_transaction_date' => now(),
    ]);
    expect($booking->fresh()->outstandingBalance())->toBe(-500.0);

    $audit = app(NightAudits::class)->run($this->property, CarbonImmutable::parse('2026-10-01'));

    expect($audit->status)->toBe(NightAudit::FAILED)
        ->and($audit->error)->toContain($booking->booking_ref_id)
        ->and($this->settings->currentRolloverDate($this->property)->toDateString())->toBe('2026-10-02');

    // Within the tolerance it closes.
    $this->settings->save($this->property, ['excess_payment_tolerance' => 600]);
    expect(app(NightAudits::class)->run($this->property, CarbonImmutable::parse('2026-10-01'))->status)->toBe(NightAudit::COMPLETED)
        ->and($payment->exists)->toBeTrue();
});
