<?php

use App\Models\Currency;
use App\Models\InventoryUnit;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomCategory;
use App\Models\SubBooking;
use App\Models\User;
use App\Services\RoomInventory;
use Database\Factories\RoomCategoryFactory;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create();
    $this->dorm = $this->property->roomCategories()->create(['name' => 'Mixed Dorm', 'display_order' => 99]);
    $this->checkIn = now()->addDays(3)->toDateString();
    $this->checkOut = now()->addDays(5)->toDateString();
});

/** Makes the category a dormitory and adds a room with the given beds through Rooms Setup. */
function dormRoom(object $test, string $name, int $beds): Room
{
    $test->dorm->update(['sell_by_bed' => true]);

    $test->actingAs($test->admin)
        ->post(route('admin.properties.room-categories.rooms.store', [$test->property, $test->dorm]), ['name' => $name, 'bed_count' => $beds])
        ->assertSessionHasNoErrors();

    return Room::query()->where('room_category_id', $test->dorm->id)->where('name', $name)->firstOrFail();
}

/** Books $beds dorm beds (Auto, or in the given room) through the booking form. */
function bookBeds(object $test, int $beds, ?int $roomId = null): TestResponse
{
    return $test->actingAs($test->admin)->post(route('admin.bookings.store', $test->property), [
        'source' => 'direct', 'type' => 'walk_in', 'status' => 'confirmed',
        'check_in' => $test->checkIn, 'check_out' => $test->checkOut, 'discount' => 0,
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        'rooms' => array_fill(0, $beds, [
            'room_category_id' => $test->dorm->id, 'room_id' => $roomId, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 800,
        ]),
    ]);
}

it('gives each room of a dormitory one bed unit per bed and sells the beds, not the room', function () {
    $room = dormRoom($this, 'D1', 10);

    expect($room->inventoryUnits()->where('unit_type', InventoryUnit::TYPE_BED)->where('is_active', true)->count())->toBe(10)
        ->and($room->inventoryUnits()->where('unit_type', InventoryUnit::TYPE_ROOM)->value('is_active'))->toBeFalse()
        ->and(app(RoomInventory::class)->capacityByCategory($this->property->id)[$this->dorm->id])->toBe(10);
});

it('puts each booked bed on its own free bed of the dorm room', function () {
    $room = dormRoom($this, 'D1', 3);

    bookBeds($this, 2, $room->id)->assertSessionHasNoErrors();
    bookBeds($this, 1)->assertSessionHasNoErrors();

    $beds = SubBooking::query()->where('room_category_id', $this->dorm->id)->with('inventoryUnit')->get();

    expect($beds->pluck('inventoryUnit.name')->sort()->values()->all())->toBe(['Bed 1', 'Bed 2', 'Bed 3'])
        ->and($beds->pluck('inventoryUnit.room_id')->unique()->all())->toBe([$room->id])
        ->and(app(RoomInventory::class)->unavailableRoomIds($this->property->id, $this->checkIn, $this->checkOut)->all())->toContain($room->id);

    // The dorm is full for those nights.
    bookBeds($this, 1, $room->id)->assertSessionHasErrors('rooms');
    bookBeds($this, 1)->assertSessionHasErrors('rooms');
});

it('fills one dorm room before the next and counts beds as availability', function () {
    dormRoom($this, 'D1', 2);
    $second = dormRoom($this, 'D2', 4);

    bookBeds($this, 3)->assertSessionHasNoErrors();

    $inventory = app(RoomInventory::class);

    expect($inventory->availabilityByCategory($this->property->id, $inventory->capacityByCategory($this->property->id), $this->checkIn, $this->checkOut)[$this->dorm->id])->toBe(3)
        ->and($inventory->freeBedsByRoom($this->property->id, $this->checkIn, $this->checkOut)->only([$second->id])->all())->toBe([$second->id => 3]);
});

it('takes all free beds of a blocked dorm room', function () {
    $room = dormRoom($this, 'D1', 6);
    dormRoom($this, 'D2', 4);
    bookBeds($this, 1, $room->id)->assertSessionHasNoErrors();
    RoomBlock::factory()->create(['property_id' => $this->property->id, 'room_id' => $room->id, 'start_date' => $this->checkIn, 'end_date' => $this->checkOut]);

    $inventory = app(RoomInventory::class);

    // 10 beds - 1 booked - 5 free beds of the blocked room.
    expect($inventory->availabilityByCategory($this->property->id, $inventory->capacityByCategory($this->property->id), $this->checkIn, $this->checkOut)[$this->dorm->id])->toBe(4);
});

it('refuses removing beds that have upcoming stays, or switching a category with stays', function () {
    $room = dormRoom($this, 'D1', 2);
    bookBeds($this, 2, $room->id)->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->put(route('admin.properties.room-categories.rooms.update', [$this->property, $this->dorm, $room]), ['name' => 'D1', 'bed_count' => 1])
        ->assertSessionHasErrors('bed_count');

    $this->actingAs($this->admin)
        ->put(route('admin.properties.room-categories.update', [$this->property, $this->dorm]), [
            ...$this->dorm->only(['name']), 'base_occupancy' => 1, 'ideal_occupancy' => 1, 'max_adults' => 1, 'max_children' => 0,
            'default_number_of_children' => 0, 'total_number_of_guests' => 1, 'sell_by_bed' => false,
        ])
        ->assertSessionHasErrors('sell_by_bed');

    // More beds is fine.
    $this->actingAs($this->admin)
        ->put(route('admin.properties.room-categories.rooms.update', [$this->property, $this->dorm, $room]), ['name' => 'D1', 'bed_count' => 4])
        ->assertSessionHasNoErrors();

    expect($room->inventoryUnits()->where('unit_type', InventoryUnit::TYPE_BED)->where('is_active', true)->count())->toBe(4);
});

it('shows a dorm room on the Smart Chart with one lane per bed', function () {
    $room = dormRoom($this, 'D1', 3);
    bookBeds($this, 2, $room->id)->assertSessionHasNoErrors();

    $chart = collect($this->actingAs($this->admin)
        ->getJson(route('admin.smartBookingChartData', ['property_id' => $this->property->id, 'start_date' => now()->toDateString()]))
        ->assertOk()
        ->json('categories'))
        ->firstWhere('id', $this->dorm->id);

    expect($chart['sell_by_bed'])->toBeTrue()
        ->and($chart['rooms'][0]['beds'])->toBe(3)
        ->and(collect($chart['rooms'][0]['bookings'])->pluck('bed')->sort()->values()->all())->toBe([1, 2]);
});

it('fixes a dormitory category at one adult per bed', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.properties.room-categories.update', [$this->property, $this->dorm]), [
            'name' => 'Mixed Dorm', 'base_occupancy' => 2, 'ideal_occupancy' => 3, 'max_adults' => 4, 'max_children' => 2,
            'default_number_of_children' => 1, 'total_number_of_guests' => 6, 'sell_by_bed' => true,
        ])
        ->assertSessionHasNoErrors();

    expect($this->dorm->fresh()->only(['base_occupancy', 'ideal_occupancy', 'max_adults', 'max_children', 'default_number_of_children', 'total_number_of_guests']))
        ->toBe(['base_occupancy' => 1, 'ideal_occupancy' => 1, 'max_adults' => 1, 'max_children' => 0, 'default_number_of_children' => 0, 'total_number_of_guests' => 1]);
});

it('builds a ready-to-sell dormitory from the factory', function () {
    $dorm = RoomCategory::factory()->dormitory(rooms: 2, beds: 8)->create(['property_id' => $this->property->id]);

    expect($dorm->sell_by_bed)->toBeTrue()
        ->and($dorm->abbreviation)->toBe('MD')
        ->and($dorm->max_adults)->toBe(1)
        ->and($dorm->rooms()->pluck('name')->all())->toBe(['Dorm 1', 'Dorm 2'])
        ->and(app(RoomInventory::class)->capacityByCategory($this->property->id)[$dorm->id])->toBe(16);
});

it('abbreviates a factory category from its name', function () {
    expect(RoomCategory::factory()->create(['property_id' => $this->property->id, 'name' => 'Super Deluxe Type A'])->abbreviation)->toBe('SDTA')
        ->and(RoomCategoryFactory::abbreviationFor('Single Room 12'))->toBe('SR');
});

it('tells the booking form each dorm room\'s free beds and a saved stay\'s bed', function () {
    $room = dormRoom($this, 'D1', 4);
    bookBeds($this, 3, $room->id)->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->getJson(route('admin.bookings.roomAvailability', [$this->property, 'check_in' => $this->checkIn, 'check_out' => $this->checkOut]))
        ->assertOk()
        ->assertJsonPath("free_beds.{$room->id}", 1);

    $booking = SubBooking::query()->where('room_category_id', $this->dorm->id)->firstOrFail()->booking;

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.edit', [$this->property, $booking]))
        ->assertInertia(fn ($page) => $page
            ->where('booking.rooms.0.room_id', $room->id)
            ->where('booking.rooms.0.bed', 1)
            ->where('booking.rooms.2.bed', 3));
});
