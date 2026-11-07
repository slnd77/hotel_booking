<?php

use App\Enums\SubBookingStatus;
use App\Enums\WaitlistStatus;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomCategory;
use App\Models\User;
use App\Models\WaitlistRequest;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);

    $this->property = Property::factory()->create(['timezone' => 'UTC']);
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id, 'name' => 'Deluxe', 'max_adults' => 2, 'meal_plans' => ['EP']]);
    RoomCategory::query()->where('property_id', $this->property->id)->whereKeyNot($this->category->id)->delete();
    sellOnBookingEngine($this->property, $this->category, 2000);
    $this->room = $this->category->rooms()->first();

    $this->checkIn = now()->addDays(5)->toDateString();
    $this->checkOut = now()->addDays(7)->toDateString();
});

/**
 * Sells the only room for the test dates.
 */
function sellOutTheRoom(object $test): Booking
{
    $booking = Booking::query()->create([
        'property_id' => $test->property->id, 'booking_ref_id' => 'FULL-1', 'status' => 'confirmed',
        'check_in' => $test->checkIn, 'check_out' => $test->checkOut,
    ]);
    $booking->rooms()->create(['room_category_id' => $test->category->id, 'room_id' => $test->room->id, 'adults' => 2, 'children' => 0, 'rate_per_night' => 2000]);

    return $booking;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function waitlistPayload(object $test, array $overrides = []): array
{
    return [
        'guest_name' => 'Asha Rao', 'phone' => '9000000001', 'email' => 'asha@example.com',
        'room_category_id' => $test->category->id, 'check_in' => $test->checkIn, 'check_out' => $test->checkOut,
        'rooms' => 1, 'adults' => 2, 'children' => 0, 'notes' => 'Anniversary trip',
        ...$overrides,
    ];
}

it('waits while the dates are sold out and turns available when the stay is cancelled', function () {
    $booking = sellOutTheRoom($this);

    $this->post(route('admin.databases.waitlist.store', $this->property), waitlistPayload($this))->assertSessionHasNoErrors();

    $request = WaitlistRequest::query()->sole();
    expect($request->status)->toBe(WaitlistStatus::Waiting)
        ->and($request->created_by)->toBe($this->admin->id)
        ->and($request->source)->toBe(WaitlistRequest::SOURCE_FRONT_DESK);

    // The guest cancels: the room frees up and the request is flagged at once.
    $booking->rooms()->first()->update(['status' => SubBookingStatus::Cancelled]);

    expect($request->fresh()->status)->toBe(WaitlistStatus::Available)
        ->and($request->fresh()->available_since)->not->toBeNull();

    $this->get(route('admin.databases.waitlist.index', ['property' => $this->property->id]))
        ->assertInertia(fn (Assert $page) => $page->component('admin/databases/Waitlist')
            ->has('requests.data', 1)
            ->where('requests.data.0.status', 'available')
            ->where('counts.available', 1));
});

it('is available straight away when the rooms are free, and goes back to waiting if they are taken', function () {
    $this->post(route('admin.databases.waitlist.store', $this->property), waitlistPayload($this))->assertSessionHasNoErrors();
    $request = WaitlistRequest::query()->sole();
    expect($request->status)->toBe(WaitlistStatus::Available);

    sellOutTheRoom($this);
    $this->post(route('admin.databases.waitlist.refresh', $this->property))->assertRedirect();

    expect($request->fresh()->status)->toBe(WaitlistStatus::Waiting);
});

it('turns available when a room block is lifted', function () {
    RoomBlock::factory()->create(['property_id' => $this->property->id, 'room_id' => $this->room->id, 'start_date' => $this->checkIn, 'end_date' => $this->checkOut]);
    $request = WaitlistRequest::factory()->create(['property_id' => $this->property->id, 'room_category_id' => $this->category->id, 'check_in' => $this->checkIn, 'check_out' => $this->checkOut]);

    $this->post(route('admin.databases.waitlist.refresh', $this->property));
    expect($request->fresh()->status)->toBe(WaitlistStatus::Waiting);

    RoomBlock::query()->sole()->delete();
    expect($request->fresh()->status)->toBe(WaitlistStatus::Available);
});

it('converts an available request into a booking through the booking form', function () {
    $request = WaitlistRequest::factory()->create([
        'property_id' => $this->property->id, 'room_category_id' => $this->category->id, 'guest_name' => 'Asha Rao',
        'check_in' => $this->checkIn, 'check_out' => $this->checkOut, 'status' => WaitlistStatus::Available,
    ]);

    $this->post(route('admin.databases.waitlist.convert', [$this->property, $request]))
        ->assertRedirect(route('admin.bookings.create', ['property' => $this->property->id, 'waitlist' => $request->id]));

    $this->get(route('admin.bookings.create', ['property' => $this->property->id, 'waitlist' => $request->id]))
        ->assertInertia(fn (Assert $page) => $page->component('admin/bookings/Create')
            ->where('prefill.check_in', $this->checkIn)
            ->where('prefill.check_out', $this->checkOut)
            ->where('prefill.waitlist_request_id', $request->id)
            ->where('prefill.guest_name', 'Asha Rao')
            ->where('prefill.rooms.0.room_category_id', $this->category->id));

    $this->post(route('admin.bookings.store', $this->property), [
        'waitlist_request_id' => $request->id,
        'booking_ref_id' => 'WL-1', 'source' => 'direct', 'type' => 'walk_in',
        'check_in' => $this->checkIn, 'check_out' => $this->checkOut,
        'status' => 'confirmed', 'discount' => 0, 'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        'rooms' => [['room_category_id' => $this->category->id, 'room_id' => $this->room->id, 'meal_plan' => 'EP', 'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => 2000]],
    ])->assertSessionHasNoErrors();

    $booking = Booking::query()->where('booking_ref_id', 'WL-1')->sole();
    expect($request->fresh()->status)->toBe(WaitlistStatus::Converted)
        ->and($request->fresh()->booking_id)->toBe($booking->id);
});

it('refuses to convert while the rooms are still sold out, and cancels a request', function () {
    sellOutTheRoom($this);
    $request = WaitlistRequest::factory()->create(['property_id' => $this->property->id, 'room_category_id' => $this->category->id, 'check_in' => $this->checkIn, 'check_out' => $this->checkOut]);

    $this->from(route('admin.databases.waitlist.index'))->post(route('admin.databases.waitlist.convert', [$this->property, $request]))
        ->assertRedirect(route('admin.databases.waitlist.index'))
        ->assertSessionHas('error');
    expect($request->fresh()->status)->toBe(WaitlistStatus::Waiting);

    $this->patch(route('admin.databases.waitlist.cancel', [$this->property, $request]))->assertRedirect();
    expect($request->fresh()->status)->toBe(WaitlistStatus::Cancelled);

    // Closed requests can't be converted or cancelled again.
    $this->post(route('admin.databases.waitlist.convert', [$this->property, $request]))->assertStatus(422);
});

it('expires requests past their check-in date', function () {
    $stale = WaitlistRequest::factory()->create(['property_id' => $this->property->id, 'check_in' => now()->subDays(3)->toDateString(), 'check_out' => now()->subDay()->toDateString()]);
    $fresh = WaitlistRequest::factory()->create(['property_id' => $this->property->id, 'check_in' => $this->checkIn, 'check_out' => $this->checkOut]);

    $this->artisan('waitlist:refresh')->assertSuccessful();

    expect($stale->fresh()->status)->toBe(WaitlistStatus::Expired)
        ->and($fresh->fresh()->status)->toBe(WaitlistStatus::Available);
});

it('lets a guest join the waitlist from the booking engine when sold out', function () {
    sellOutTheRoom($this);

    $this->postJson(route('booking-engine.waitlist', $this->property->ulid), [
        'check_in' => $this->checkIn, 'check_out' => $this->checkOut, 'rooms' => 1, 'adults' => 2, 'children' => 1,
        'guest' => ['name' => 'Ravi Kumar', 'phone' => '+91 9000000002', 'email' => 'ravi@example.com'],
    ])->assertOk()->assertJson(['success' => true, 'status' => 'waiting']);

    $request = WaitlistRequest::query()->sole();
    expect($request->guest_name)->toBe('Ravi Kumar')
        ->and($request->source)->toBe(WaitlistRequest::SOURCE_BOOKING_ENGINE)
        ->and($request->room_category_id)->toBeNull()
        ->and($request->children)->toBe(1);

    $this->postJson(route('booking-engine.waitlist', $this->property->ulid), [
        'check_in' => now()->subDay()->toDateString(), 'check_out' => $this->checkOut,
        'guest' => ['name' => 'Ravi Kumar', 'phone' => '+91 9000000002'],
    ])->assertUnprocessable()->assertJsonValidationErrors('check_in');
});

it('rejects a request in the past or for another property\'s category', function () {
    $other = RoomCategory::factory()->create(['property_id' => Property::factory()->create()->id]);

    $this->post(route('admin.databases.waitlist.store', $this->property), waitlistPayload($this, ['check_in' => now()->subDay()->toDateString()]))
        ->assertSessionHasErrors('check_in');
    $this->post(route('admin.databases.waitlist.store', $this->property), waitlistPayload($this, ['room_category_id' => $other->id]))
        ->assertSessionHasErrors('room_category_id');
});
