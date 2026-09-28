<?php

use App\Enums\BookingStatus;
use App\Models\BlockReason;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomBlock;
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
    $this->rooms = Room::factory()->count(3)->create(['room_category_id' => $this->category->id]);

    $this->startDate = now()->addDay()->toDateString();
    $this->endDate = now()->addDays(3)->toDateString();
});

test('bulk block creates a block per room and removes them from chart availability', function () {
    $this->actingAs($this->admin)
        ->postJson(route('admin.roomBlocks.store', $this->property), [
            'block_reason_id' => BlockReason::query()->where('name', 'Maintenance')->value('id'),
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'room_ids' => $this->rooms->take(2)->pluck('id')->all(),
            'name' => 'Painting',
            'remarks' => 'Repainting walls',
        ])
        ->assertCreated();

    expect(RoomBlock::query()->where('property_id', $this->property->id)->count())->toBe(2);

    $chart = $this->actingAs($this->admin)
        ->getJson(route('admin.smartBookingChartData', ['property_id' => $this->property->id]))
        ->assertOk()
        ->json();

    $category = collect($chart['categories'])->firstWhere('id', $this->category->id);

    expect($category['daily_availability'][now()->toDateString()]['available'])->toBe(3)
        ->and($category['daily_availability'][$this->startDate]['available'])->toBe(1)
        ->and($category['daily_availability'][$this->endDate]['available'])->toBe(3);

    $blockedRoom = collect($category['rooms'])->firstWhere('id', $this->rooms[0]->id);

    expect($blockedRoom['blocks'])->toHaveCount(1)
        ->and($blockedRoom['blocks'][0])->toMatchArray([
            'reason_label' => 'Maintenance',
            'name' => 'Painting',
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ]);
});

test('bulk block rejects rooms that are already booked for the dates', function () {
    $booking = Booking::create([
        'property_id' => $this->property->id,
        'booking_ref_id' => 'BLOCKTEST',
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => $this->startDate,
        'check_out' => now()->addDays(2)->toDateString(),
        'status' => BookingStatus::Confirmed,
        'room_total' => 1000,
        'taxes' => 0,
        'total' => 1000,
        'outstanding' => 0,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
    ]);
    $booking->rooms()->create([
        'room_category_id' => $this->category->id,
        'room_id' => $this->rooms[0]->id,
        'meal_plan' => 'EP',
        'adults' => 2,
        'rate_per_night' => 1000,
    ]);

    $this->actingAs($this->admin)
        ->postJson(route('admin.roomBlocks.store', $this->property), [
            'block_reason_id' => BlockReason::query()->where('name', 'Booking')->value('id'),
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'room_ids' => [$this->rooms[0]->id, $this->rooms[1]->id],
            'remarks' => 'Group hold',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('room_ids');

    expect(RoomBlock::count())->toBe(0);
});

test('bulk block requires remarks and rooms belonging to the property', function () {
    $otherRoom = Room::factory()->create();

    $this->actingAs($this->admin)
        ->postJson(route('admin.roomBlocks.store', $this->property), [
            'block_reason_id' => BlockReason::query()->where('name', 'Booking')->value('id'),
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'room_ids' => [$otherRoom->id],
            'remarks' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['remarks', 'room_ids.0']);
});

test('unblock releases only the selected nights and splits longer blocks', function () {
    $room = $this->rooms[0];
    RoomBlock::factory()->forRoom($room)->between(now()->toDateString(), now()->addDays(5)->toDateString())->create();

    $this->actingAs($this->admin)
        ->postJson(route('admin.roomBlocks.unblock', $this->property), [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'room_ids' => [$room->id],
        ])
        ->assertOk();

    $remaining = RoomBlock::query()->where('room_id', $room->id)->orderBy('start_date')->get()
        ->map(fn (RoomBlock $block): array => [$block->start_date->toDateString(), $block->end_date->toDateString()])
        ->all();

    expect($remaining)->toBe([
        [now()->toDateString(), $this->startDate],
        [$this->endDate, now()->addDays(5)->toDateString()],
    ]);
});

test('quick book is prefilled with the dates and rooms selected on the chart', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.bookings.create', [
            'property' => $this->property,
            'check_in' => $this->startDate,
            'check_out' => $this->endDate,
            'room_ids' => $this->rooms[0]->id.','.$this->rooms[2]->id.',999999',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('prefill.check_in', $this->startDate)
            ->where('prefill.check_out', $this->endDate)
            ->where('prefill.rooms', fn ($rooms): bool => collect($rooms)->sortBy('room_id')->values()->all() === [
                ['room_category_id' => $this->category->id, 'room_id' => $this->rooms[0]->id],
                ['room_category_id' => $this->category->id, 'room_id' => $this->rooms[2]->id],
            ]));
});

/**
 * @param  list<array{room_category_id: int, room_id: int|null}>  $rooms
 * @return array<string, mixed>
 */
function quickBookPayload(string $checkIn, string $checkOut, array $rooms): array
{
    return [
        'booking_ref_id' => '',
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'status' => 'confirmed',
        'discount' => 0,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
        'rooms' => array_map(fn (array $room): array => [...$room, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000], $rooms),
    ];
}

test('quick book rejects a blocked room until it is unblocked', function () {
    RoomBlock::factory()->forRoom($this->rooms[0])->between($this->startDate, $this->endDate)->create();
    $payload = quickBookPayload($this->startDate, $this->endDate, [['room_category_id' => $this->category->id, 'room_id' => $this->rooms[0]->id]]);

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), $payload)
        ->assertSessionHasErrors('rooms');

    expect(Booking::count())->toBe(0);

    $this->actingAs($this->admin)
        ->postJson(route('admin.roomBlocks.unblock', $this->property), [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'room_ids' => [$this->rooms[0]->id],
        ])
        ->assertOk();

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.dashboard', ['property' => $this->property->id]));

    expect(Booking::count())->toBe(1);
});

test('quick book gives each auto room its own free room, skipping blocked ones', function () {
    RoomBlock::factory()->forRoom($this->rooms[0])->between($this->startDate, $this->endDate)->create();

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), quickBookPayload($this->startDate, $this->endDate, [
            ['room_category_id' => $this->category->id, 'room_id' => null],
            ['room_category_id' => $this->category->id, 'room_id' => null],
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.dashboard', ['property' => $this->property->id]));

    $assignedRoomIds = Booking::sole()->rooms()->get()->pluck('room_id')->sort()->values()->all();

    expect($assignedRoomIds)->toBe([$this->rooms[1]->id, $this->rooms[2]->id]);
});

test('quick book rejects more rooms than are free once blocks are counted', function () {
    RoomBlock::factory()->forRoom($this->rooms[0])->between($this->startDate, $this->endDate)->create();
    RoomBlock::factory()->forRoom($this->rooms[1])->between($this->startDate, $this->endDate)->create();

    $this->actingAs($this->admin)
        ->getJson(route('admin.bookings.roomAvailability', ['property' => $this->property, 'check_in' => $this->startDate, 'check_out' => $this->endDate]))
        ->assertOk()
        ->assertJsonPath("availability.{$this->category->id}", 1);

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), quickBookPayload($this->startDate, $this->endDate, [
            ['room_category_id' => $this->category->id, 'room_id' => null],
            ['room_category_id' => $this->category->id, 'room_id' => null],
        ]))
        ->assertSessionHasErrors('rooms');
});

test('chart blocks include who blocked the room', function () {
    RoomBlock::factory()->forRoom($this->rooms[0])->between($this->startDate, $this->endDate)->create(['user_id' => $this->admin->id]);

    $chart = $this->actingAs($this->admin)
        ->getJson(route('admin.smartBookingChartData', ['property_id' => $this->property->id]))
        ->json();

    $room = collect(collect($chart['categories'])->firstWhere('id', $this->category->id)['rooms'])->firstWhere('id', $this->rooms[0]->id);

    expect($room['blocks'][0]['blocked_by'])->toBe($this->admin->name)
        ->and($room['blocks'][0]['blocked_at'])->not->toBeNull();
});
