<?php

use App\Enums\BookingStatus;
use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomCategory;
use App\Models\SubBooking;
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
    $this->otherCategory = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    $this->otherCategoryRoom = Room::factory()->create(['room_category_id' => $this->otherCategory->id]);
});

function bookingOnRoom(Property $property, RoomCategory $category, Room $room, string $checkIn, int $nights, BookingStatus $status = BookingStatus::Confirmed): array
{
    $booking = $property->bookings()->create([
        'booking_ref_id' => fake()->unique()->bothify('MOVE####'),
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => $checkIn,
        'check_out' => now()->parse($checkIn)->addDays($nights)->toDateString(),
        'status' => $status,
        'room_total' => 3000,
        'taxes' => 0,
        'total' => 3000,
        'outstanding' => 3000,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
    ]);
    $bookingRoom = $booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $room->id, 'meal_plan' => 'EP', 'adults' => 2, 'rate_per_night' => 1000]);

    return [$booking, $bookingRoom];
}

test('a booking can be dragged to another room and other dates, keeping its length and total', function () {
    [$booking, $bookingRoom] = bookingOnRoom($this->property, $this->category, $this->rooms[0], now()->addDays(2)->toDateString(), 3);

    $this->actingAs($this->admin)
        ->patchJson(route('admin.bookings.move', [$this->property, $booking]), [
            'sub_booking_id' => $bookingRoom->id,
            'room_id' => $this->rooms[2]->id,
            'check_in' => now()->addDays(4)->toDateString(),
        ])
        ->assertOk();

    $booking->refresh();

    expect($booking->check_in->toDateString())->toBe(now()->addDays(4)->toDateString())
        ->and($booking->check_out->toDateString())->toBe(now()->addDays(7)->toDateString())
        ->and((float) $booking->total)->toBe(3000.0)
        ->and($bookingRoom->fresh()->room_id)->toBe($this->rooms[2]->id)
        ->and(ActivityLog::query()->where('action', 'like', 'Reservation moved on Smart Chart%')->exists())->toBeTrue();
});

test('a booking cannot be dropped onto a booked or blocked room, another category or the past', function () {
    [$booking, $bookingRoom] = bookingOnRoom($this->property, $this->category, $this->rooms[0], now()->addDays(2)->toDateString(), 2);
    bookingOnRoom($this->property, $this->category, $this->rooms[1], now()->addDays(3)->toDateString(), 2);
    RoomBlock::factory()->forRoom($this->rooms[2])->between(now()->addDays(2)->toDateString(), now()->addDays(4)->toDateString())->create();

    $move = fn (array $payload) => $this->actingAs($this->admin)
        ->patchJson(route('admin.bookings.move', [$this->property, $booking]), ['sub_booking_id' => $bookingRoom->id, 'check_in' => now()->addDays(2)->toDateString(), ...$payload]);

    $move(['room_id' => $this->rooms[1]->id])->assertJsonValidationErrors('room_id');
    $move(['room_id' => $this->rooms[2]->id])->assertJsonValidationErrors('room_id');
    $move(['room_id' => $this->otherCategoryRoom->id])->assertJsonValidationErrors('room_id');
    $move(['room_id' => $this->rooms[0]->id, 'check_in' => now()->subDay()->toDateString()])->assertJsonValidationErrors('check_in');

    expect($bookingRoom->fresh()->room_id)->toBe($this->rooms[0]->id)
        ->and($booking->fresh()->check_in->toDateString())->toBe(now()->addDays(2)->toDateString());
});

test('a checked-in booking can change room but not dates', function () {
    [$booking, $bookingRoom] = bookingOnRoom($this->property, $this->category, $this->rooms[0], now()->toDateString(), 2, BookingStatus::CheckedIn);

    $this->actingAs($this->admin)
        ->patchJson(route('admin.bookings.move', [$this->property, $booking]), ['sub_booking_id' => $bookingRoom->id, 'room_id' => $this->rooms[1]->id, 'check_in' => now()->addDay()->toDateString()])
        ->assertJsonValidationErrors('check_in');

    $this->actingAs($this->admin)
        ->patchJson(route('admin.bookings.move', [$this->property, $booking]), ['sub_booking_id' => $bookingRoom->id, 'room_id' => $this->rooms[1]->id, 'check_in' => now()->toDateString()])
        ->assertOk();

    expect(SubBooking::query()->find($bookingRoom->id)->room_id)->toBe($this->rooms[1]->id);
});

test('a block can be dragged to any free room and new dates, keeping its length', function () {
    $block = RoomBlock::factory()->forRoom($this->rooms[0])->between(now()->addDay()->toDateString(), now()->addDays(3)->toDateString())->create();
    bookingOnRoom($this->property, $this->category, $this->rooms[1], now()->addDays(5)->toDateString(), 2);

    $this->actingAs($this->admin)
        ->patchJson(route('admin.roomBlocks.move', [$this->property, $block]), ['room_id' => $this->rooms[1]->id, 'start_date' => now()->addDays(4)->toDateString()])
        ->assertJsonValidationErrors('room_id');

    $this->actingAs($this->admin)
        ->patchJson(route('admin.roomBlocks.move', [$this->property, $block]), ['room_id' => $this->otherCategoryRoom->id, 'start_date' => now()->addDays(4)->toDateString()])
        ->assertOk();

    $block->refresh();

    expect($block->room_id)->toBe($this->otherCategoryRoom->id)
        ->and($block->start_date->toDateString())->toBe(now()->addDays(4)->toDateString())
        ->and($block->end_date->toDateString())->toBe(now()->addDays(6)->toDateString());
});

test('chart bookings expose the booking room they are drawn for', function () {
    [, $bookingRoom] = bookingOnRoom($this->property, $this->category, $this->rooms[0], now()->toDateString(), 1);

    $chart = $this->actingAs($this->admin)
        ->getJson(route('admin.smartBookingChartData', ['property_id' => $this->property->id]))
        ->json();

    $room = collect(collect($chart['categories'])->firstWhere('id', $this->category->id)['rooms'])->firstWhere('id', $this->rooms[0]->id);

    expect($room['bookings'][0]['sub_booking_id'])->toBe($bookingRoom->id);
});
