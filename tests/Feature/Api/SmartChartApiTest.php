<?php

use App\Enums\BookingStatus;
use App\Models\BlockReason;
use App\Models\Booking;
use App\Models\BookingChannel;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\ApiScopes;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::findOrCreate('admin', 'web'));
    $this->token = $this->admin->createToken(ApiScopes::DEVICE_PREFIX.'Pixel', ['*'])->plainTextToken;

    $this->property = Property::factory()->create();
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id, 'meal_plans' => ['EP', 'CP']]);
    $this->rooms = Room::factory()->count(2)->create(['room_category_id' => $this->category->id]);
});

function chartBookingFor(Property $property, string $ref = 'APP1'): Booking
{
    return Booking::create([
        'property_id' => $property->id,
        'booking_ref_id' => $ref,
        'check_in' => now()->toDateString(),
        'check_out' => now()->addDay()->toDateString(),
        'status' => BookingStatus::Confirmed,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
    ]);
}

test('the app reads the smart chart, a booking summary and finds bookings by reservation ID', function () {
    $booking = chartBookingFor($this->property, 'FINDME42');

    $this->withToken($this->token)->getJson(route('api.v1.chart.show', $this->property))
        ->assertOk()
        ->assertJsonPath('property_id', $this->property->id)
        ->assertJsonStructure(['dates' => [['date', 'enquiries_count', 'notes']], 'categories' => [['id', 'rooms', 'daily_availability']], 'daily_totals', 'housekeeping_statuses', 'block_reasons']);

    $this->withToken($this->token)->getJson(route('api.v1.chart.show', [$this->property, 'start_date' => 'soon']))->assertJsonValidationErrors('start_date');

    $this->withToken($this->token)->getJson(route('api.v1.bookings.summary', [$this->property, $booking]))
        ->assertOk()->assertJsonPath('booking_ref_id', 'FINDME42');

    $this->withToken($this->token)->getJson(route('api.v1.bookings.search', [$this->property, 'q' => 'dme4']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $booking->id);
});

test('the app checks a booking in and cancels it', function () {
    $booking = chartBookingFor($this->property);
    $url = route('api.v1.bookings.updateStatus', [$this->property, $booking]);

    $this->withToken($this->token)->patchJson($url, ['status' => 'checked_in'])->assertOk();
    expect($booking->fresh()->status)->toBe(BookingStatus::CheckedIn);

    $this->withToken($this->token)->patchJson($url, ['status' => 'cancelled'])->assertOk();
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

test('the app quick-books rooms and gets the new booking back', function () {
    $this->withToken($this->token)->getJson(route('api.v1.bookings.options', $this->property))
        ->assertOk()
        ->assertJsonPath('categories.0.meal_plans', ['EP', 'CP'])
        ->assertJsonStructure(['categories' => [['rooms' => [['id', 'name']]]], 'types', 'statuses', 'hold_defaults']);

    $this->withToken($this->token)->postJson(route('api.v1.bookings.store', $this->property), [
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => now()->addDays(2)->toDateString(),
        'check_out' => now()->addDays(4)->toDateString(),
        'status' => 'confirmed',
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
        'rooms' => [['room_category_id' => $this->category->id, 'room_id' => $this->rooms[0]->id, 'meal_plan' => 'EP', 'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1500]],
    ])
        ->assertCreated()
        ->assertJsonPath('booking.status', 'confirmed');

    expect(Booking::query()->where('property_id', $this->property->id)->count())->toBe(1);
});

test('the app blocks and unblocks rooms, marks housekeeping and adds notes', function () {
    $start = now()->addDay()->toDateString();
    $end = now()->addDays(2)->toDateString();

    $this->withToken($this->token)->postJson(route('api.v1.roomBlocks.store', $this->property), [
        'block_reason_id' => BlockReason::query()->value('id') ?? BlockReason::factory()->create()->id,
        'start_date' => $start,
        'end_date' => $end,
        'room_ids' => [$this->rooms[0]->id],
        'remarks' => 'Painting',
    ])->assertCreated();
    expect(RoomBlock::query()->count())->toBe(1);

    $this->withToken($this->token)->postJson(route('api.v1.roomBlocks.unblock', $this->property), [
        'start_date' => $start,
        'end_date' => $end,
        'room_ids' => [$this->rooms[0]->id],
    ])->assertOk();
    expect(RoomBlock::query()->count())->toBe(0);

    $this->withToken($this->token)->patchJson(route('api.v1.rooms.housekeeping', [$this->property, $this->rooms[1]]), ['status' => 'Dirty'])
        ->assertOk()->assertJsonPath('housekeeping.name', 'Dirty');

    $this->withToken($this->token)->postJson(route('api.v1.chartNotes.store', $this->property), ['date' => $start, 'body' => 'VIP'])
        ->assertOk()->assertJsonPath('notes.0.body', 'VIP');
});

test('an API key only reaches the chart actions it has scopes for', function () {
    $key = $this->admin->createToken(ApiScopes::KEY_PREFIX.'Read only', ['chart:read'])->plainTextToken;
    $booking = chartBookingFor($this->property);

    $this->withToken($key)->getJson(route('api.v1.chart.show', $this->property))->assertOk();
    $this->withToken($key)->patchJson(route('api.v1.bookings.updateStatus', [$this->property, $booking]), ['status' => 'cancelled'])
        ->assertForbidden()
        ->assertJsonPath('message', 'This API key is missing the scope: bookings:write.');
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

test('the account owner manages the booking channels from the app', function () {
    BookingChannel::query()->create(['name' => 'Agoda']);

    $this->withToken($this->token)->getJson(route('api.v1.bookingChannels.index'))
        ->assertOk()->assertJsonPath('data.0.name', 'Agoda')->assertJsonPath('data.0.bookings_count', 0);

    $created = $this->withToken($this->token)->postJson(route('api.v1.bookingChannels.store'), ['name' => 'Expedia'])
        ->assertCreated()->json('channel');
    $this->withToken($this->token)->postJson(route('api.v1.bookingChannels.store'), ['name' => 'Expedia'])->assertJsonValidationErrors('name');

    $this->withToken($this->token)->patchJson(route('api.v1.bookingChannels.update', $created['id']), ['is_active' => false])
        ->assertOk()->assertJsonPath('channel.is_active', false);

    $clerk = User::factory()->create();
    $clerk->assignRole(Role::findOrCreate('Front Office', 'web'));
    $clerkToken = $clerk->createToken(ApiScopes::DEVICE_PREFIX.'Phone', ['*'])->plainTextToken;
    app('auth')->forgetGuards();

    $this->withToken($clerkToken)->getJson(route('api.v1.bookingChannels.index'))->assertForbidden();
});
