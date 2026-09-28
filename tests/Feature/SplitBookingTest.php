<?php

use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
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
    $this->rooms = Room::factory()->count(3)->create(['room_category_id' => $this->category->id]);

    $this->checkIn = now()->addDays(3)->toDateString();
    $this->checkOut = now()->addDays(5)->toDateString();

    // Two rooms, two nights, 1,000 a night each.
    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), [
        'booking_ref_id' => 'SPLIT-1',
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => $this->checkIn,
        'check_out' => $this->checkOut,
        'status' => 'confirmed',
        'discount' => 0,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
        'rooms' => [
            ['room_category_id' => $this->category->id, 'room_id' => $this->rooms[0]->id, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000],
            ['room_category_id' => $this->category->id, 'room_id' => $this->rooms[1]->id, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000],
        ],
    ])->assertSessionHasNoErrors();

    $this->booking = Booking::query()->where('booking_ref_id', 'SPLIT-1')->sole();
    [$this->first, $this->second] = $this->booking->rooms()->get()->all();
});

function dayOffset(string $date, int $days): string
{
    return date('Y-m-d', strtotime("{$date} {$days} day"));
}

test('dragging one room a day earlier on the chart moves only that room and splits the booking', function () {
    $total = (float) $this->booking->total;

    $this->actingAs($this->admin)
        ->patchJson(route('admin.bookings.move', [$this->property, $this->booking]), [
            'sub_booking_id' => $this->first->id,
            'room_id' => $this->rooms[0]->id,
            'check_in' => dayOffset($this->checkIn, -1),
            'scope' => 'room',
        ])
        ->assertOk()
        ->assertJsonPath('price.difference', 0);

    $booking = $this->booking->fresh();

    expect($booking->rooms_split)->toBeTrue()
        ->and($this->first->fresh()->check_in_date->toDateString())->toBe(dayOffset($this->checkIn, -1))
        ->and($this->first->fresh()->check_out_date->toDateString())->toBe(dayOffset($this->checkOut, -1))
        ->and($this->second->fresh()->check_in_date->toDateString())->toBe($this->checkIn)
        // The booking now spans its rooms.
        ->and($booking->check_in->toDateString())->toBe(dayOffset($this->checkIn, -1))
        ->and($booking->check_out->toDateString())->toBe($this->checkOut)
        // Same nights, same price; the ledger agrees.
        ->and((float) $booking->total)->toBe($total)
        ->and($booking->outstandingBalance())->toBe($total);
});

test('moving a split booking as a whole shifts every room, keeping their offsets', function () {
    $this->actingAs($this->admin)->patchJson(route('admin.bookings.move', [$this->property, $this->booking]), [
        'sub_booking_id' => $this->first->id, 'room_id' => $this->rooms[0]->id, 'check_in' => dayOffset($this->checkIn, -1), 'scope' => 'room',
    ])->assertOk();

    $this->actingAs($this->admin)->patchJson(route('admin.bookings.move', [$this->property, $this->booking]), [
        'sub_booking_id' => $this->second->id, 'room_id' => $this->rooms[1]->id, 'check_in' => dayOffset($this->checkIn, 2), 'scope' => 'booking',
    ])->assertOk();

    expect($this->first->fresh()->check_in_date->toDateString())->toBe(dayOffset($this->checkIn, 1))
        ->and($this->second->fresh()->check_in_date->toDateString())->toBe(dayOffset($this->checkIn, 2))
        ->and($this->booking->fresh()->check_in->toDateString())->toBe(dayOffset($this->checkIn, 1));
});

test('changing one room\'s dates on the booking page previews and reports the new total', function () {
    $url = fn (string $name) => route($name, [$this->property, $this->booking, $this->second]);
    $newDates = ['check_in' => $this->checkIn, 'check_out' => dayOffset($this->checkOut, 1)]; // one night longer

    $preview = $this->actingAs($this->admin)->postJson($url('admin.bookings.rooms.datesPreview'), $newDates)->assertOk()->json();
    expect($preview['difference'])->toBeGreaterThanOrEqual(1000.0)
        ->and($this->second->fresh()->check_out_date->toDateString())->toBe($this->checkOut); // nothing saved yet

    $this->actingAs($this->admin)->patch($url('admin.bookings.rooms.dates'), $newDates)
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'Total changed from'));

    $booking = $this->booking->fresh();

    expect((float) $booking->total)->toBe((float) $preview['new_total'])
        ->and($booking->check_out->toDateString())->toBe(dayOffset($this->checkOut, 1))
        ->and($booking->outstandingBalance())->toBe((float) $preview['new_total'])
        ->and($this->first->fresh()->check_out_date->toDateString())->toBe($this->checkOut);
});

test("a room can't be moved onto dates its room is already taken", function () {
    // Another booking holds room 1 the night before.
    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), [
        'booking_ref_id' => 'OTHER', 'source' => 'direct', 'type' => 'walk_in',
        'check_in' => dayOffset($this->checkIn, -1), 'check_out' => $this->checkIn, 'status' => 'confirmed', 'discount' => 0,
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        'rooms' => [['room_category_id' => $this->category->id, 'room_id' => $this->rooms[0]->id, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
    ]);

    $this->actingAs($this->admin)->patchJson(route('admin.bookings.move', [$this->property, $this->booking]), [
        'sub_booking_id' => $this->first->id, 'room_id' => $this->rooms[0]->id, 'check_in' => dayOffset($this->checkIn, -1), 'scope' => 'room',
    ])->assertJsonValidationErrors('room_id');

    expect($this->booking->fresh()->rooms_split)->toBeFalse();
});

test('the booking page can split a multi-room booking', function () {
    $this->actingAs($this->admin)->post(route('admin.bookings.split', [$this->property, $this->booking]))->assertSessionHas('success');

    expect($this->booking->fresh()->rooms_split)->toBeTrue();

    // Moving the booking's dates no longer drags split rooms along.
    $this->booking->fresh()->update(['special_instructions' => 'VIP']);
    expect($this->first->fresh()->check_in_date->toDateString())->toBe($this->checkIn);
});

test('the edit form saves each room of a split booking on its own dates and re-prices it', function () {
    $this->actingAs($this->admin)->post(route('admin.bookings.split', [$this->property, $this->booking]));

    $room = fn (int $roomId, string $checkIn, string $checkOut): array => [
        'room_category_id' => $this->category->id, 'room_id' => $roomId, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0,
        'rate_per_night' => 1000, 'check_in_date' => $checkIn, 'check_out_date' => $checkOut,
    ];

    $this->actingAs($this->admin)->put(route('admin.bookings.update', [$this->property, $this->booking]), [
        'booking_ref_id' => 'SPLIT-1', 'source' => 'direct', 'type' => 'walk_in',
        'check_in' => $this->checkIn, 'check_out' => $this->checkOut, 'status' => 'confirmed', 'discount' => 0,
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        'rooms' => [
            $room($this->rooms[0]->id, dayOffset($this->checkIn, -1), $this->checkOut), // arrives a day early: 3 nights
            $room($this->rooms[1]->id, $this->checkIn, $this->checkOut),                 // 2 nights
        ],
    ])->assertSessionHasNoErrors();

    $booking = $this->booking->fresh();

    expect($this->first->fresh()->check_in_date->toDateString())->toBe(dayOffset($this->checkIn, -1))
        ->and($this->second->fresh()->check_in_date->toDateString())->toBe($this->checkIn)
        ->and($booking->check_in->toDateString())->toBe(dayOffset($this->checkIn, -1))
        ->and((float) $booking->room_total)->toBe(5000.0)
        ->and($booking->outstandingBalance())->toBe((float) $booking->total);
});

test('shifting a room keeps a booking\'s stored price, even one priced under different taxes', function () {
    // Priced earlier with a different tax (e.g. by the booking engine): not what today's settings give.
    $this->booking->updateQuietly(['taxes' => 777, 'total' => 4777]);

    $preview = $this->actingAs($this->admin)
        ->postJson(route('admin.bookings.rooms.datesPreview', [$this->property, $this->booking, $this->first]), ['check_in' => dayOffset($this->checkIn, -1), 'check_out' => dayOffset($this->checkOut, -1)])
        ->json();

    expect((float) $preview['difference'])->toBe(0.0)
        ->and((float) $preview['new_total'])->toBe(4777.0);
});
