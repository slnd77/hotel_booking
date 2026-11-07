<?php

use App\Enums\BookingStatus;
use App\Enums\SubBookingStatus;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Guest;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\RoomInventory;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create();
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    $this->rooms = Room::factory()->count(2)->create(['room_category_id' => $this->category->id]);
    $this->guest = Guest::factory()->create(['property_id' => $this->property->id]);

    $this->today = $this->property->today();
    $this->tomorrow = now()->addDay()->toDateString();
    $this->inTwoDays = now()->addDays(2)->toDateString();

    // Arrived three days ago, due out yesterday, still in house in the first room: an overstay.
    $this->overstay = overstayBooking($this->property, $this->rooms[0], $this->guest);
});

/**
 * A guest checked in to the room, whose check-out date has passed without a check-out.
 */
function overstayBooking(Property $property, Room $room, Guest $guest): Booking
{
    $booking = $property->bookings()->create([
        'booking_ref_id' => 'OVERSTAY', 'source' => 'direct', 'type' => 'walk_in', 'status' => BookingStatus::CheckedIn,
        'check_in' => now()->subDays(3)->toDateString(), 'check_out' => now()->subDay()->toDateString(),
        'room_total' => 2000, 'discount' => 0, 'taxes' => 0, 'total' => 2000, 'outstanding' => 0,
    ]);
    $booking->rooms()->create(['room_category_id' => $room->room_category_id, 'room_id' => $room->id, 'meal_plan' => 'EP', 'adults' => 1, 'rate_per_night' => 1000])
        ->update(['status' => SubBookingStatus::CheckedIn, 'checked_in_at' => now()->subDays(3)]);
    $booking->guests()->attach($guest->id);

    return $booking;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stayPayload(RoomCategory $category, ?int $roomId, string $checkIn, string $checkOut, array $overrides = []): array
{
    return [
        'booking_ref_id' => '', 'source' => 'direct', 'type' => 'walk_in', 'status' => 'confirmed', 'discount' => 0,
        'check_in' => $checkIn, 'check_out' => $checkOut, 'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        'rooms' => [['room_category_id' => $category->id, 'room_id' => $roomId, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
        ...$overrides,
    ];
}

test('a room with an overstaying guest is unavailable from today until they check out', function () {
    $inventory = app(RoomInventory::class);

    expect($inventory->unavailableRoomIds($this->property->id, $this->today, $this->inTwoDays)->all())->toBe([$this->rooms[0]->id])
        ->and($inventory->unavailableRoomIds($this->property->id, $this->tomorrow, $this->inTwoDays)->all())->toBe([])
        ->and($inventory->availabilityByCategory($this->property->id, $inventory->capacityByCategory($this->property->id), $this->today, $this->tomorrow)[$this->category->id])->toBe(1);

    // Once the guest has checked out, the room is free again.
    $this->overstay->subBookings()->update(['status' => SubBookingStatus::CheckedOut, 'checked_out_at' => now()]);

    expect($inventory->unavailableRoomIds($this->property->id, $this->today, $this->inTwoDays)->all())->toBe([]);
});

test('quick book refuses the overstaying guest\'s room for today and auto-assigns the other room', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), stayPayload($this->category, $this->rooms[0]->id, $this->today, $this->inTwoDays, ['guest_ids' => [$this->guest->id]]))
        ->assertSessionHasErrors('rooms');

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), stayPayload($this->category, null, $this->today, $this->inTwoDays, ['booking_ref_id' => 'AUTO', 'guest_ids' => [$this->guest->id]]))
        ->assertSessionHasNoErrors();

    expect(Booking::query()->where('booking_ref_id', 'AUTO')->sole()->rooms()->sole()->room_id)->toBe($this->rooms[1]->id);
});

test('checking in to a room whose guest is still in house is refused', function () {
    $arrival = $this->property->bookings()->create([
        'booking_ref_id' => 'ARRIVAL', 'source' => 'direct', 'type' => 'walk_in', 'status' => BookingStatus::Confirmed,
        'check_in' => $this->today, 'check_out' => $this->inTwoDays,
        'room_total' => 2000, 'discount' => 0, 'taxes' => 0, 'total' => 2000, 'outstanding' => 2000,
    ]);
    $stay = $arrival->rooms()->create(['room_category_id' => $this->category->id, 'room_id' => $this->rooms[0]->id, 'meal_plan' => 'EP', 'adults' => 1, 'rate_per_night' => 1000]);
    $arrival->guests()->attach($this->guest->id);

    $this->actingAs($this->admin)
        ->patch(route('admin.bookings.updateStatus', [$this->property, $arrival]), ['status' => 'checked_in', 'override_housekeeping' => true])
        ->assertSessionHas('error', fn (string $error): bool => str_contains($error, 'is occupied by booking OVERSTAY'));
    $this->actingAs($this->admin)
        ->patch(route('admin.bookings.rooms.stay', [$this->property, $arrival, $stay]), ['status' => 'checked_in', 'override_housekeeping' => true])
        ->assertSessionHas('error', fn (string $error): bool => str_contains($error, 'is occupied by booking OVERSTAY'));

    expect($arrival->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($stay->fresh()->status)->toBe(SubBookingStatus::Booked);

    // The guest leaves: the arrival can check in.
    $this->overstay->subBookings()->update(['status' => SubBookingStatus::CheckedOut, 'checked_out_at' => now()]);
    $this->overstay->update(['status' => BookingStatus::CheckedOut]);

    $this->actingAs($this->admin)
        ->patch(route('admin.bookings.rooms.stay', [$this->property, $arrival, $stay]), ['status' => 'checked_in', 'override_housekeeping' => true])
        ->assertSessionHas('success');

    expect($stay->fresh()->status)->toBe(SubBookingStatus::CheckedIn);
});

test('the smart chart draws the overstay bar through today', function () {
    $categories = $this->actingAs($this->admin)
        ->getJson(route('admin.smartBookingChartData', ['property_id' => $this->property->id, 'start_date' => now()->subDays(3)->toDateString()]))
        ->assertOk()
        ->json('categories');

    $room = collect($categories)->flatMap(fn (array $category): array => $category['rooms'])->firstWhere('id', $this->rooms[0]->id);
    $bar = collect($room['bookings'])->firstWhere('booking_ref_id', 'OVERSTAY');

    expect($bar)->not->toBeNull()
        ->and($bar['overstay'])->toBeTrue()
        ->and($bar['booked_check_out'])->toBe(now()->subDay()->toDateString())
        ->and($bar['check_out'])->toBe($this->tomorrow)
        ->and($bar['status'])->toBe('checked_in');
});
