<?php

use App\Enums\OutletType;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Guest;
use App\Models\Language;
use App\Models\Outlet;
use App\Models\OutletItem;
use App\Models\OutletPricePlan;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\ApiScopes;
use App\Services\ExchangeRates;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::findOrCreate('admin', 'web'));
    $this->device = $this->admin->createToken(ApiScopes::DEVICE_PREFIX.'Pixel', ['*'])->plainTextToken;

    $this->property = Property::factory()->create();
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id, 'meal_plans' => ['EP', 'CP']]);
    $this->rooms = Room::factory()->count(2)->create(['room_category_id' => $this->category->id]);
});

/**
 * @param  list<string>  $scopes
 */
function apiKey(User $user, array $scopes): string
{
    return $user->createToken(ApiScopes::KEY_PREFIX.'Integration', $scopes)->plainTextToken;
}

/**
 * Sends the next request with this token. The guard remembers the last request's user and
 * token within a test, so it's reset first.
 */
function asToken($test, string $token)
{
    app('auth')->forgetGuards();

    return $test->withToken($token);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiBookingPayload(RoomCategory $category, array $overrides = []): array
{
    return [
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => now()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
        'status' => 'confirmed',
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
        'rooms' => [['room_category_id' => $category->id, 'meal_plan' => 'EP', 'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
        ...$overrides,
    ];
}

function apiBooking($test, array $overrides = []): Booking
{
    $id = asToken($test, $test->device)->postJson(route('api.v1.bookings.store', $test->property), apiBookingPayload($test->category, $overrides))
        ->assertCreated()
        ->json('booking.id');

    return Booking::query()->findOrFail($id);
}

test('a key lists and reads reservations, and enquiries apart from them', function () {
    $booking = apiBooking($this);

    // Without "Accept: application/json" the API still answers in JSON.
    asToken($this, $this->device)
        ->post(route('api.v1.enquiries.store', $this->property), apiBookingPayload($this->category, ['hold_release_at' => now()->addDays(3)->toIso8601String()]))
        ->assertCreated()
        ->assertJsonPath('booking.status', 'enquiry');

    $key = apiKey($this->admin, ['bookings:read']);

    asToken($this, $key)->getJson(route('api.v1.bookings.index', $this->property))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $booking->id)
        ->assertJsonPath('data.0.rooms.0.meal_plan', 'EP')
        ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'total']]);

    asToken($this, $key)->getJson(route('api.v1.enquiries.index', $this->property))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'enquiry');

    asToken($this, $key)->getJson(route('api.v1.bookings.index', [$this->property, 'status' => 'checked_in']))->assertOk()->assertJsonCount(0, 'data');
    asToken($this, $key)->getJson(route('api.v1.bookings.index', [$this->property, 'status' => 'enquiry']))->assertStatus(422);

    asToken($this, $key)->getJson(route('api.v1.bookings.show', [$this->property, $booking]))
        ->assertOk()
        ->assertJsonPath('data.booking_ref_id', $booking->booking_ref_id)
        ->assertJsonPath('data.total', 2000)
        ->assertJsonStructure(['data' => ['rooms', 'guests', 'payments', 'folios' => [['id', 'windows' => [['id', 'balance', 'entries']]]]]]);

    // A booking of another property is not found under this one.
    asToken($this, $key)->getJson(route('api.v1.bookings.show', [Property::factory()->create(), $booking]))->assertNotFound();

    // Reading is not writing.
    asToken($this, $key)->putJson(route('api.v1.bookings.update', [$this->property, $booking]), apiBookingPayload($this->category))
        ->assertForbidden();
});

test('a booking is edited over the API', function () {
    $booking = apiBooking($this);

    asToken($this, apiKey($this->admin, ['bookings:write']))
        ->putJson(route('api.v1.bookings.update', [$this->property, $booking]), apiBookingPayload($this->category, [
            'rooms' => [['room_category_id' => $this->category->id, 'meal_plan' => 'CP', 'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1200]],
        ]))
        ->assertOk()
        ->assertJsonPath('booking.id', $booking->id);

    expect((float) $booking->refresh()->total)->toBe(2400.0)
        ->and($booking->rooms()->sole()->meal_plan)->toBe('CP');
});

test('a key lists, reads and edits guests of its property only', function () {
    $guestId = asToken($this, $this->device)
        ->postJson(route('api.v1.guests.store', $this->property), ['first_name' => 'Meera', 'last_name' => 'Rao', 'phone' => '9800000001', 'emails' => ['meera@example.com']])
        ->assertOk()
        ->json('guest.id');
    $outsider = Guest::query()->create(['property_id' => Property::factory()->create()->id, 'first_name' => 'Other']);

    $read = apiKey($this->admin, ['guests:read']);

    asToken($this, $read)->getJson(route('api.v1.guests.index', [$this->property, 'q' => 'meer']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.emails', ['meera@example.com']);
    asToken($this, $read)->getJson(route('api.v1.guests.show', [$this->property, $guestId]))->assertOk()->assertJsonPath('data.last_name', 'Rao');
    asToken($this, $read)->getJson(route('api.v1.guests.show', [$this->property, $outsider]))->assertNotFound();

    $write = apiKey($this->admin, ['guests:write']);

    asToken($this, $write)
        ->putJson(route('api.v1.guests.update', [$this->property, $guestId]), ['first_name' => 'Meera', 'last_name' => 'Iyer', 'phone' => '9800000001', 'emails' => ['meera@example.com']])
        ->assertOk()->assertJsonPath('data.last_name', 'Iyer');
    asToken($this, $write)
        ->putJson(route('api.v1.guests.update', [$this->property, $outsider]), ['first_name' => 'Changed'])
        ->assertNotFound();
});

test('a key records a payment against a booking, which the folio credits, and cancels it', function () {
    $booking = apiBooking($this);
    $write = apiKey($this->admin, ['payments:write']);

    $payment = asToken($this, $write)
        ->postJson(route('api.v1.payments.store', $this->property), [
            'booking_id' => $booking->id,
            'guest_name' => 'Meera Rao',
            'payment_mode' => 'UPI',
            'total_txn_amount' => 500,
            'payment_ref_id' => 'UPI-1',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'Received')
        ->assertJsonPath('data.collection_source', 'API')
        ->assertJsonPath('data.allocations.0.booking_id', $booking->id)
        ->json('data');

    expect((float) $booking->refresh()->outstanding)->toBe(1500.0);

    // Paid in euros: the server converts at the property's rate, whatever amount was sent.
    app(ExchangeRates::class)->set($this->property, 'EUR', 90, CarbonImmutable::parse($this->property->today()), $this->admin);
    $euros = asToken($this, $write)
        ->postJson(route('api.v1.payments.store', $this->property), [
            'booking_id' => $booking->id, 'guest_name' => 'Meera Rao', 'payment_mode' => 'Cash',
            'currency_code' => 'EUR', 'currency_amount' => 5, 'total_txn_amount' => 1, 'tagged_amount' => 450,
        ])
        ->assertCreated()
        ->json('data');

    expect((float) Payment::query()->findOrFail($euros['id'])->total_txn_amount)->toBe(450.0)
        ->and((float) $booking->refresh()->outstanding)->toBe(1050.0);
    Payment::query()->findOrFail($euros['id'])->update(['status' => PaymentStatus::Cancelled]);

    $read = apiKey($this->admin, ['payments:read']);
    asToken($this, $read)->getJson(route('api.v1.payments.index', [$this->property, 'booking_id' => $booking->id, 'status' => 'Received']))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $payment['id']);
    asToken($this, $read)->postJson(route('api.v1.payments.store', $this->property), [])->assertForbidden();

    // A booking of another property can't be tagged.
    $foreign = Booking::query()->create(['property_id' => Property::factory()->create()->id, 'booking_ref_id' => 'FOREIGN', 'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(), 'status' => 'confirmed', 'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest']);
    asToken($this, $write)
        ->postJson(route('api.v1.payments.store', $this->property), ['booking_id' => $foreign->id, 'guest_name' => 'X', 'payment_mode' => 'Cash', 'total_txn_amount' => 10])
        ->assertJsonValidationErrors('booking_id');

    asToken($this, $write)
        ->postJson(route('api.v1.payments.cancel', [$this->property, $payment['id']]), ['cancellation_reason' => 'Duplicate'])
        ->assertOk()
        ->assertJsonPath('data.status', PaymentStatus::Cancelled->value);

    expect((float) $booking->refresh()->outstanding)->toBe(2000.0);
});

test('a key bills an outlet and posts the bill to a checked-in room with a payment', function () {
    $booking = apiBooking($this);
    asToken($this, $this->device)->patchJson(route('api.v1.bookings.updateStatus', [$this->property, $booking]), ['status' => 'checked_in'])->assertOk();

    $outlet = Outlet::factory()->create(['property_id' => $this->property->id, 'name' => 'Bar', 'type' => OutletType::FoodAndBeverages]);
    $item = OutletItem::factory()->create(['outlet_id' => $outlet->id, 'name' => 'Lime Soda']);
    OutletPricePlan::factory()->create(['outlet_id' => $outlet->id])->prices()->create(['outlet_item_id' => $item->id, 'price' => 80]);

    $key = apiKey($this->admin, ['pos:read', 'pos:write']);

    asToken($this, $key)->getJson(route('api.v1.outlets.index', $this->property))
        ->assertOk()->assertJsonPath('data.0.items.0.price', 80);

    $billId = asToken($this, $key)
        ->postJson(route('api.v1.outletBills.store', [$this->property, $outlet]), ['items' => [['outlet_item_id' => $item->id, 'quantity' => 3]]])
        ->assertCreated()
        ->assertJsonPath('data.total', 240)
        ->assertJsonPath('data.status', 'open')
        ->json('data.id');

    asToken($this, $key)
        ->postJson(route('api.v1.outletBills.postToRoom', [$this->property, $billId]), [
            'sub_booking_id' => $booking->rooms()->sole()->id,
            'payment' => ['payment_mode' => 'Cash', 'amount' => 240],
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.booking_ref_id', $booking->booking_ref_id);

    // Bill 240 charged and paid on the folio: the booking still owes only its rooms.
    expect((float) $booking->refresh()->outstanding)->toBe(2000.0);

    // Another property's outlet isn't reachable under this one.
    $otherOutlet = Outlet::factory()->create();
    asToken($this, $key)->postJson(route('api.v1.outletBills.store', [$this->property, $otherOutlet]), [])->assertNotFound();
});
