<?php

use App\Enums\SubBookingStatus;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\BookingAddons;
use App\Services\StayTimeCharges;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    // UTC, so reported times read as the property's local times. Check-in 12:00, check-out 11:00 (the policy defaults).
    $this->property = Property::factory()->create(['timezone' => 'UTC']);
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    Room::factory()->create(['room_category_id' => $this->category->id]);

    $this->travelTo(now()->utc()->setTime(18, 0));
    $this->actingAs($this->admin);

    $this->put(route('admin.properties.stayTimeCharges.update', $this->property), [
        'early_check_in' => ['enabled' => true, 'tiers' => [
            ['time' => '09:00', 'type' => 'percent', 'value' => 50],
            ['time' => '06:00', 'type' => 'percent', 'value' => 100],
        ]],
        'late_check_out' => ['enabled' => true, 'tiers' => [
            ['time' => '13:00', 'type' => 'percent', 'value' => 25],
            ['time' => '15:00', 'type' => 'fixed', 'value' => 800],
        ]],
        'grace_minutes' => 30,
        'tax_rate' => 12,
        'sac_hsn_code' => '996311',
    ])->assertSessionHasNoErrors();
});

function stayChargeBooking(string $checkIn, string $checkOut): Booking
{
    test()->post(route('admin.bookings.store', test()->property), [
        'booking_ref_id' => 'EARLY-'.uniqid(),
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'status' => 'confirmed',
        'discount' => 0,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
        'rooms' => [['room_category_id' => test()->category->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
    ])->assertSessionHasNoErrors();

    return Booking::query()->latest('id')->firstOrFail();
}

function reportedAt(string $time): string
{
    return now()->utc()->setTimeFromTimeString($time)->toIso8601String();
}

test('the property saves its tiers sorted by time', function () {
    $settings = app(StayTimeCharges::class)->settings($this->property->fresh());

    expect(collect($settings['early_check_in']['tiers'])->pluck('time')->all())->toBe(['06:00', '09:00'])
        ->and($settings['grace_minutes'])->toBe(30)
        ->and($settings['tax_rate'])->toBe(12.0);
});

test('an early check-in adds the tier\'s charge to the booking and undoing the check-in removes it', function () {
    $booking = stayChargeBooking(now()->toDateString(), now()->addDays(2)->toDateString());
    $room = $booking->subBookings()->sole();

    // Before 09:00 but not before 06:00: 50% of the 1000 night rate, plus 12% tax.
    $this->getJson(route('admin.bookings.stayTimeCharges.preview', [$this->property, $booking, 'kind' => 'early_check_in', 'reported_at' => reportedAt('07:15')]))
        ->assertOk()
        ->assertJsonPath('charges.0.amount', 500)
        ->assertJsonPath('total', 560)
        ->assertJsonPath('standard_time', '12:00');

    $this->patch(route('admin.bookings.rooms.stay', [$this->property, $booking, $room]), ['status' => 'checked_in', 'reported_at' => reportedAt('07:15')])->assertSessionHas('success');

    $line = BookingAddon::query()->sole();
    expect($line->added_from)->toBe('early_check_in')
        ->and($line->sub_booking_id)->toBe($room->id)
        ->and((float) $line->total)->toBe(560.0)
        ->and($line->sac_hsn_code)->toBe('996311')
        ->and((float) $booking->fresh()->addons_total)->toBe(560.0)
        ->and($line->transactions()->sum('amount'))->toEqual(560);

    $this->patch(route('admin.bookings.rooms.stay', [$this->property, $booking, $room]), ['status' => 'booked'])->assertSessionHas('success');

    expect(BookingAddon::query()->count())->toBe(0)
        ->and((float) $booking->fresh()->addons_total)->toBe(0.0);
});

test('arriving within the grace period or after check-in time costs nothing', function () {
    $booking = stayChargeBooking(now()->toDateString(), now()->addDays(2)->toDateString());

    $this->patch(route('admin.bookings.updateStatus', [$this->property, $booking]), ['status' => 'checked_in', 'reported_at' => reportedAt('11:40')])->assertSessionHas('success');

    expect($booking->subBookings()->sole()->status)->toBe(SubBookingStatus::CheckedIn)
        ->and(BookingAddon::query()->count())->toBe(0);
});

test('a late check-out takes the latest tier passed; a waived one is only logged', function () {
    $booking = stayChargeBooking(now()->subDays(2)->toDateString(), now()->toDateString());
    $this->patch(route('admin.bookings.updateStatus', [$this->property, $booking]), ['status' => 'checked_in', 'reported_at' => now()->subDays(2)->setTime(14, 0)->toIso8601String()])->assertSessionHas('success');

    // 16:30 is past the 15:00 tier: a fixed 800 plus tax.
    $this->patch(route('admin.bookings.updateStatus', [$this->property, $booking]), ['status' => 'checked_out', 'reported_at' => reportedAt('16:30')])->assertSessionHas('success');

    $line = BookingAddon::query()->sole();
    expect($line->added_from)->toBe('late_check_out')
        ->and((float) $line->amount)->toBe(800.0)
        ->and((float) $line->total)->toBe(896.0);

    // Undo the check-out: the charge comes off; checking out again with the charge waived adds nothing.
    $this->patch(route('admin.bookings.updateStatus', [$this->property, $booking]), ['status' => 'checked_in'])->assertSessionHas('success');
    expect(BookingAddon::query()->count())->toBe(0);

    $this->patch(route('admin.bookings.updateStatus', [$this->property, $booking]), ['status' => 'checked_out', 'reported_at' => reportedAt('13:30'), 'waive_stay_time_charges' => true])->assertSessionHas('success');

    expect(BookingAddon::query()->count())->toBe(0)
        ->and(ActivityLog::query()->where('action', 'like', 'Late check-out charge of 280 waived%')->exists())->toBeTrue();
});

test('leaving before the departure date is not a late check-out', function () {
    $booking = stayChargeBooking(now()->subDay()->toDateString(), now()->addDays(2)->toDateString());
    $room = $booking->subBookings()->sole();
    $this->patch(route('admin.bookings.rooms.stay', [$this->property, $booking, $room]), ['status' => 'checked_in', 'reported_at' => now()->subDay()->setTime(14, 0)->toIso8601String()]);

    $this->patch(route('admin.bookings.rooms.stay', [$this->property, $booking, $room]), ['status' => 'checked_out', 'reported_at' => reportedAt('17:00')])->assertSessionHas('success');

    expect($room->fresh()->check_out_date->toDateString())->toBe(now()->toDateString())
        ->and(BookingAddon::query()->count())->toBe(0);
});

test('waiving the charge needs the permission, and catalogue add-on changes leave the charge alone', function () {
    $clerk = User::factory()->create();
    $clerk->assignRole(Role::findOrCreate('Desk', 'web'));
    $clerk->givePermissionTo(Permission::findOrCreate('reservation.check_in', 'web'));

    $booking = stayChargeBooking(now()->toDateString(), now()->addDays(2)->toDateString());
    $room = $booking->subBookings()->sole();

    $this->actingAs($clerk)->patch(route('admin.bookings.rooms.stay', [$this->property, $booking, $room]), ['status' => 'checked_in', 'reported_at' => reportedAt('05:00'), 'waive_stay_time_charges' => true])->assertForbidden();

    $this->actingAs($clerk)->patch(route('admin.bookings.rooms.stay', [$this->property, $booking, $room]), ['status' => 'checked_in', 'reported_at' => reportedAt('05:00')])->assertSessionHas('success');

    // Before 06:00: a full night.
    expect((float) BookingAddon::query()->sole()->amount)->toBe(1000.0);

    app(BookingAddons::class)->sync($booking->fresh(), []);

    expect(BookingAddon::query()->count())->toBe(1);
});
