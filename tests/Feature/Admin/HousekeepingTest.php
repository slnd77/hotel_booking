<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create();
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['room_category_id' => $this->category->id, 'name' => '101']);

    // Booking Settings → Housekeeping: Clean, Dirty (check-out default), Inspected.
    $field = MetaField::query()->firstOrCreate(['key' => 'booking_settings_housekeeping'], ['key_label' => 'Housekeeping', 'status' => true]);
    $this->property->metaFieldValues()->create(['meta_fields_key_id' => $field->id, 'enabled' => true, 'data' => json_encode(['statuses' => [
        ['name' => 'Clean', 'color' => '#e3e3e0', 'display_order' => 1, 'default_on_check_out' => false],
        ['name' => 'Dirty', 'color' => '#f53003', 'display_order' => 2, 'default_on_check_out' => true],
        ['name' => 'Inspected', 'color' => '#16a34a', 'display_order' => 3, 'default_on_check_out' => false],
    ]])]);
});

/**
 * @param  list<array<string, mixed>>  $statuses
 */
function housekeepingStatusesAre(Property $property, array $statuses): void
{
    $field = MetaField::query()->where('key', 'booking_settings_housekeeping')->firstOrFail();
    $property->metaFieldValues()->where('meta_fields_key_id', $field->id)->update(['data' => json_encode(['statuses' => $statuses])]);
}

function arrivingBooking(Property $property, RoomCategory $category, Room $room): Booking
{
    $booking = $property->bookings()->create([
        'booking_ref_id' => 'HK-2', 'source' => 'direct', 'type' => 'walk_in', 'status' => BookingStatus::Confirmed,
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(),
        'room_total' => 1000, 'discount' => 0, 'taxes' => 0, 'total' => 1000, 'outstanding' => 0,
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
    ]);
    $booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $room->id, 'meal_plan' => 'EP', 'adults' => 1, 'rate_per_night' => 1000]);

    return $booking;
}

function checkedInBooking(Property $property, RoomCategory $category, Room $room): Booking
{
    $booking = $property->bookings()->create([
        'booking_ref_id' => 'HK-1', 'source' => 'direct', 'type' => 'walk_in', 'status' => BookingStatus::Confirmed,
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(),
        'room_total' => 1000, 'discount' => 0, 'taxes' => 0, 'total' => 1000, 'outstanding' => 0,
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
    ]);
    $booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $room->id, 'meal_plan' => 'EP', 'adults' => 1, 'rate_per_night' => 1000]);

    test()->actingAs(test()->admin)->patch(route('admin.bookings.updateStatus', [$property, $booking]), ['status' => 'checked_in'])->assertSessionHas('success');

    return $booking;
}

test('the smart chart colours each room by its housekeeping status and marks it from the room name', function () {
    $chartRoom = fn (): array => collect($this->actingAs($this->admin)
        ->getJson(route('admin.smartBookingChartData', ['property_id' => $this->property->id]))
        ->assertOk()
        ->json('categories'))->flatMap(fn (array $category): array => $category['rooms'])->firstWhere('id', $this->room->id);

    $this->actingAs($this->admin)
        ->getJson(route('admin.smartBookingChartData', ['property_id' => $this->property->id]))
        ->assertJsonPath('housekeeping_statuses.1.name', 'Dirty');

    // Not marked yet: the first status.
    expect($chartRoom()['housekeeping'])->toBe(['name' => 'Clean', 'color' => '#e3e3e0']);

    $this->actingAs($this->admin)
        ->patchJson(route('admin.rooms.housekeeping', [$this->property, $this->room]), ['status' => 'Inspected'])
        ->assertOk()
        ->assertJsonPath('housekeeping', ['name' => 'Inspected', 'color' => '#16a34a']);

    expect($this->room->fresh()->housekeeping_status)->toBe('Inspected')
        ->and($chartRoom()['housekeeping']['color'])->toBe('#16a34a');
});

test('only the property\'s own statuses and rooms can be marked', function () {
    $this->actingAs($this->admin)
        ->patchJson(route('admin.rooms.housekeeping', [$this->property, $this->room]), ['status' => 'Sparkling'])
        ->assertStatus(422);

    $other = Property::factory()->create();
    $this->actingAs($this->admin)
        ->patchJson(route('admin.rooms.housekeeping', [$other, $this->room]), ['status' => 'Clean'])
        ->assertNotFound();

    expect($this->room->fresh()->housekeeping_status)->toBeNull();
});

test('check-out marks the room with the check-out default, or with the status chosen in the dialog', function () {
    $booking = checkedInBooking($this->property, $this->category, $this->room);

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.show', [$this->property, $booking]))
        ->assertInertia(fn ($page) => $page->where('housekeepingCheckOutDefault', 'Dirty')->has('housekeepingStatuses', 3));

    $this->actingAs($this->admin)->patch(route('admin.bookings.updateStatus', [$this->property, $booking]), ['status' => 'checked_out'])->assertSessionHas('success');
    expect($this->room->fresh()->housekeeping_status)->toBe('Dirty');

    $this->actingAs($this->admin)->patch(route('admin.bookings.updateStatus', [$this->property, $booking]), ['status' => 'confirmed']);
    $this->actingAs($this->admin)->patch(route('admin.bookings.updateStatus', [$this->property, $booking]), ['status' => 'checked_in']);
    $this->actingAs($this->admin)
        ->patch(route('admin.bookings.updateStatus', [$this->property, $booking]), ['status' => 'checked_out', 'housekeeping_status' => 'Inspected'])
        ->assertSessionHas('success');

    expect($this->room->fresh()->housekeeping_status)->toBe('Inspected');
});

test('an unknown housekeeping status stops the check-out before anything changes', function () {
    $booking = checkedInBooking($this->property, $this->category, $this->room);

    $this->actingAs($this->admin)
        ->patch(route('admin.bookings.updateStatus', [$this->property, $booking]), ['status' => 'checked_out', 'housekeeping_status' => 'Sparkling'])
        ->assertSessionHasErrors('housekeeping_status');

    expect($booking->fresh()->status)->toBe(BookingStatus::CheckedIn)
        ->and($this->room->fresh()->housekeeping_status)->toBeNull();
});

test('a room whose status doesn\'t allow check-in needs "Check in anyway"; a hard stop refuses it outright', function () {
    housekeepingStatusesAre($this->property, [
        ['name' => 'Clean', 'color' => '#e3e3e0', 'display_order' => 1, 'allow_check_in' => true],
        ['name' => 'Dirty', 'color' => '#f53003', 'display_order' => 2, 'allow_check_in' => false, 'hard_stop' => false],
        ['name' => 'Under Maintenance', 'color' => '#000000', 'display_order' => 3, 'allow_check_in' => false, 'hard_stop' => true],
    ]);
    $booking = arrivingBooking($this->property, $this->category, $this->room);
    $checkIn = fn (array $extra = []) => $this->actingAs($this->admin)->patch(route('admin.bookings.updateStatus', [$this->property, $booking]), ['status' => 'checked_in', ...$extra]);

    // The booking page knows, so the dialog can warn.
    $this->room->update(['housekeeping_status' => 'Dirty']);
    $this->actingAs($this->admin)
        ->get(route('admin.bookings.show', [$this->property, $booking]))
        ->assertInertia(fn ($page) => $page->where('booking.rooms.0.housekeeping.name', 'Dirty')->where('booking.rooms.0.housekeeping.allow_check_in', false));

    $checkIn()->assertSessionHas('error', fn (string $message): bool => str_contains($message, '101 is Dirty') && str_contains($message, 'Check in anyway'));
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);

    $this->room->update(['housekeeping_status' => 'Under Maintenance']);
    $checkIn(['override_housekeeping' => true])->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'can\'t be checked in'));
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);

    $this->room->update(['housekeeping_status' => 'Dirty']);
    $checkIn(['override_housekeeping' => true])->assertSessionHas('success');
    expect($booking->fresh()->status)->toBe(BookingStatus::CheckedIn);
});

test('a room checked in takes the check-in default status', function () {
    housekeepingStatusesAre($this->property, [
        ['name' => 'Clean', 'color' => '#e3e3e0', 'display_order' => 1],
        ['name' => 'Occupied', 'color' => '#2563eb', 'display_order' => 2, 'default_on_check_in' => true],
    ]);
    $booking = arrivingBooking($this->property, $this->category, $this->room);

    $this->actingAs($this->admin)->patch(route('admin.bookings.updateStatus', [$this->property, $booking]), ['status' => 'checked_in'])->assertSessionHas('success');

    expect($this->room->fresh()->housekeeping_status)->toBe('Occupied');
});

test('when a guest in house moves rooms, the room they left takes the room-change default', function () {
    housekeepingStatusesAre($this->property, [
        ['name' => 'Clean', 'color' => '#e3e3e0', 'display_order' => 1],
        ['name' => 'Dirty', 'color' => '#f53003', 'display_order' => 2, 'default_on_room_change' => true],
    ]);
    $booking = checkedInBooking($this->property, $this->category, $this->room);
    $newRoom = Room::factory()->create(['room_category_id' => $this->category->id, 'name' => '102']);

    $booking->subBookings()->sole()->update(['room_id' => $newRoom->id]);

    expect($this->room->fresh()->housekeeping_status)->toBe('Dirty')
        ->and($newRoom->fresh()->housekeeping_status)->toBeNull();
});
