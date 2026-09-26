<?php

use App\Enums\BookingCheckInStatus;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Guest;
use App\Models\Language;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Permission::firstOrCreate(['name' => 'checkout_on_outstanding_payments', 'guard_name' => 'web']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->admin->givePermissionTo('checkout_on_outstanding_payments');

    Currency::create(['code' => 'USD', 'name' => 'USD']);
    Language::create(['code' => 'en', 'name' => 'English']);
});

function createTestBooking(Property $property, array $overrides = []): Booking
{
    $createdAt = $overrides['created_at'] ?? null;
    unset($overrides['created_at']);

    $booking = $property->bookings()->create(array_merge([
        'booking_ref_id' => 'REF-'.fake()->unique()->numerify('#####'),
        'check_in' => now()->addDays(1)->toDateString(),
        'check_out' => now()->addDays(3)->toDateString(),
    ], $overrides));

    if ($createdAt) {
        $booking->forceFill(['created_at' => $createdAt])->save();
    }

    return $booking;
}

test('bookings index is scoped to the selected property and sorted newest first', function () {
    $property = Property::factory()->create();
    $otherProperty = Property::factory()->create();

    $older = createTestBooking($property, ['created_at' => now()->subDays(2)]);
    $newer = createTestBooking($property, ['created_at' => now()->subDay()]);
    createTestBooking($otherProperty);

    $response = $this->actingAs($this->admin)->get("/admin/databases/bookings?property={$property->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/databases/Bookings')
        ->has('bookings.data', 2)
        ->where('bookings.data.0.id', $newer->id)
        ->where('bookings.data.1.id', $older->id));
});

test('bookings index includes the primary guest details', function () {
    $property = Property::factory()->create();
    $booking = createTestBooking($property);
    $guest = Guest::factory()->create(['property_id' => $property->id, 'title' => null, 'first_name' => 'Sushant', 'last_name' => 'Gupta']);
    $booking->guests()->attach($guest);

    $response = $this->actingAs($this->admin)->get("/admin/databases/bookings?property={$property->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/databases/Bookings')
        ->where('bookings.data.0.guest_name', 'Sushant Gupta')
        ->where('bookings.data.0.guest_id', $guest->id));
});

test('downloading the booking list is rejected without an eligible date filter', function () {
    $property = Property::factory()->create();
    createTestBooking($property);

    $response = $this->actingAs($this->admin)->get("/admin/databases/bookings/download?property={$property->id}");

    $response->assertStatus(422);
});

test('downloading the booking list is rejected when the date range exceeds 30 days', function () {
    $property = Property::factory()->create();
    createTestBooking($property);

    $response = $this->actingAs($this->admin)->get(
        "/admin/databases/bookings/download?property={$property->id}&created_from=2026-01-01&created_to=2026-03-01"
    );

    $response->assertStatus(422);
});

test('downloading the booking list succeeds with a date filter of 30 days or fewer and logs the activity', function () {
    $property = Property::factory()->create();
    createTestBooking($property);

    $from = now()->subDays(10)->toDateString();
    $to = now()->addDays(10)->toDateString();

    $response = $this->actingAs($this->admin)->get(
        "/admin/databases/bookings/download?property={$property->id}&created_from={$from}&created_to={$to}"
    );

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $this->admin->id,
        'property_id' => $property->id,
        'guest_id' => null,
        'action' => 'booking_list_downloaded',
    ]);
});

test('view reservation page renders with booking details, rooms, and payments', function () {
    $property = Property::factory()->create();
    $booking = createTestBooking($property, [
        'room_total' => 10000,
        'taxes' => 500,
        'total' => 10500,
        'outstanding' => 0,
        'status' => 'confirmed',
    ]);
    $guest = Guest::factory()->create(['property_id' => $property->id, 'title' => null, 'first_name' => 'Tarak', 'last_name' => 'Gond', 'phone' => '9432800377']);
    $booking->guests()->attach($guest);

    $category = RoomCategory::factory()->create(['property_id' => $property->id]);
    $booking->rooms()->create([
        'room_category_id' => $category->id,
        'rate_per_night' => 5000,
        'adults' => 2,
    ]);

    $response = $this->actingAs($this->admin)->get("/admin/properties/{$property->id}/bookings/{$booking->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/bookings/Show')
        ->where('booking.id', $booking->id)
        ->where('booking.booking_ref_id', $booking->booking_ref_id)
        ->where('booking.guests.0.name', 'Tarak Gond')
        ->where('booking.total', 10500)
        ->has('booking.rooms.0.taxes')
        ->has('booking.rooms.0.total')
        ->has('booking.rooms.0.discount')
        ->has('activityLogs')
    );
});

test('booking status can be updated from view reservation page and logs activity', function () {
    $property = Property::factory()->create();
    $booking = createTestBooking($property, ['status' => 'confirmed', 'check_in' => now()->toDateString()]);
    $guest = Guest::factory()->create(['property_id' => $property->id]);
    $booking->guests()->attach($guest);

    $response = $this->actingAs($this->admin)->patch("/admin/properties/{$property->id}/bookings/{$booking->id}/status", [
        'status' => 'checked_in',
    ]);

    $response->assertRedirect();
    expect($booking->fresh()->status)->toBe(BookingStatus::CheckedIn);

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $this->admin->id,
        'property_id' => $property->id,
        'guest_id' => $guest->id,
        'action' => 'StayStatus : Checked In via Web',
    ]);
});

test('payment link can be sent and generated for a booking via Collect Online', function () {
    $property = Property::factory()->create();
    Mail::fake();

    $booking = createTestBooking($property, ['status' => 'confirmed', 'total' => 6000, 'outstanding' => 6000]);
    $guest = Guest::factory()->create(['property_id' => $property->id, 'emails' => ['guest@example.com'], 'phone' => '9876543210']);
    $booking->guests()->attach($guest);

    $response = $this->actingAs($this->admin)->postJson(route('admin.bookings.paymentLinks.store', [$property, $booking]), [
        'collect_for' => 'Guest',
        'amount' => 5000,
        'email' => 'guest@example.com',
        'mobile' => '9876543210',
        'send' => true,
    ]);

    $response->assertOk()
        ->assertJsonStructure(['message', 'payment_link']);

    // The link carries only a random token — never the amount or the booking reference.
    expect($response->json('payment_link'))->not->toContain('5000')
        ->and($response->json('payment_link'))->not->toContain($booking->booking_ref_id);

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $this->admin->id,
        'property_id' => $property->id,
        'guest_id' => $guest->id,
    ]);
});

test('undo check-in reverts checked_in status back to confirmed and logs activity', function () {
    $property = Property::factory()->create();
    $booking = createTestBooking($property, ['status' => 'checked_in']);
    $guest = Guest::factory()->create(['property_id' => $property->id]);
    $booking->guests()->attach($guest);

    $response = $this->actingAs($this->admin)->patch("/admin/properties/{$property->id}/bookings/{$booking->id}/status", [
        'status' => 'confirmed',
    ]);

    $response->assertRedirect();
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $this->admin->id,
        'property_id' => $property->id,
        'guest_id' => $guest->id,
        'action' => 'StayStatus : Confirmed (Check in undone) via Web',
    ]);
});

test('undo check-out reverts checked_out status back to confirmed and logs activity', function () {
    $property = Property::factory()->create();
    $booking = createTestBooking($property, ['status' => 'checked_out']);
    $guest = Guest::factory()->create(['property_id' => $property->id]);
    $booking->guests()->attach($guest);

    $response = $this->actingAs($this->admin)->patch("/admin/properties/{$property->id}/bookings/{$booking->id}/status", [
        'status' => 'confirmed',
    ]);

    $response->assertRedirect();
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $this->admin->id,
        'property_id' => $property->id,
        'guest_id' => $guest->id,
        'action' => 'StayStatus : Confirmed (Check out undone) via Web',
    ]);
});

test('checkout without outstanding payments succeeds for user without checkout_on_outstanding_payments permission', function () {
    $property = Property::factory()->create();
    $booking = createTestBooking($property, [
        'status' => 'checked_in',
        'total' => 1000,
        'outstanding' => 0,
    ]);
    $guest = Guest::factory()->create(['property_id' => $property->id]);
    $booking->guests()->attach($guest);

    $staffUser = User::factory()->create();
    $staffUser->assignRole('admin');

    $response = $this->actingAs($staffUser)->patch("/admin/properties/{$property->id}/bookings/{$booking->id}/status", [
        'status' => 'checked_out',
    ]);

    $response->assertRedirect();
    expect($booking->fresh()->status)->toBe(BookingStatus::CheckedOut);
});

test('a pay-at-hotel booking that is not fully paid cannot check out', function () {
    $property = Property::factory()->create();
    $booking = createTestBooking($property, [
        'status' => 'checked_in',
        'total' => 5000,
        'outstanding' => 2000,
        'pay_at_hotel' => true,
    ]);
    $guest = Guest::factory()->create(['property_id' => $property->id]);
    $booking->guests()->attach($guest);

    $response = $this->actingAs($this->admin)->patch("/admin/properties/{$property->id}/bookings/{$booking->id}/status", [
        'status' => 'checked_out',
    ]);

    $response->assertRedirect()->assertSessionHas('error', 'It is set payable at hotel and it is not fully paid.');
    expect($booking->fresh()->status)->toBe(BookingStatus::CheckedIn);
});

test('a booking not payable at hotel checks out with an amount due only with the outstanding-checkout permission', function () {
    $property = Property::factory()->create();
    $booking = createTestBooking($property, [
        'status' => 'checked_in',
        'total' => 5000,
        'outstanding' => 2000,
        'pay_at_hotel' => false,
    ]);
    $guest = Guest::factory()->create(['property_id' => $property->id]);
    $booking->guests()->attach($guest);

    $clerkRole = Role::firstOrCreate(['name' => 'Clerk']);
    $clerkRole->givePermissionTo(Permission::firstOrCreate(['name' => 'reservation.check_out', 'guard_name' => 'web']));
    $staffUser = User::factory()->create();
    $staffUser->assignRole($clerkRole);

    $this->actingAs($staffUser)->patch("/admin/properties/{$property->id}/bookings/{$booking->id}/status", [
        'status' => 'checked_out',
    ])->assertRedirect()->assertSessionHas('error', 'Checkout with an outstanding balance requires authorization.');
    expect($booking->fresh()->status)->toBe(BookingStatus::CheckedIn);

    $clerkRole->givePermissionTo('checkout_on_outstanding_payments');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($staffUser->fresh())->patch("/admin/properties/{$property->id}/bookings/{$booking->id}/status", [
        'status' => 'checked_out',
    ])->assertRedirect()->assertSessionHas('success');
    expect($booking->fresh()->status)->toBe(BookingStatus::CheckedOut);
});

test('view reservation page passes canCheckoutWithOutstanding and today props', function () {
    $property = Property::factory()->create();
    $booking = createTestBooking($property, [
        'status' => 'confirmed',
        'total' => 2000,
        'outstanding' => 500,
    ]);

    $adminRole = Role::findByName('admin');
    $adminRole->revokePermissionTo('checkout_on_outstanding_payments');

    $staffUser = User::factory()->create();
    $staffUser->assignRole('admin');
    $response = $this->actingAs($staffUser)->get("/admin/properties/{$property->id}/bookings/{$booking->id}");
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/bookings/Show')
        ->where('canCheckoutWithOutstanding', false)
        ->where('today', $property->today())
    );

    $staffUser->givePermissionTo('checkout_on_outstanding_payments');
    $adminResponse = $this->actingAs($staffUser)->get("/admin/properties/{$property->id}/bookings/{$booking->id}");
    $adminResponse->assertOk();
    $adminResponse->assertInertia(fn ($page) => $page
        ->component('admin/bookings/Show')
        ->where('canCheckoutWithOutstanding', true)
        ->where('today', $property->today())
    );
});

test('booking check_in_status is cast to BookingCheckInStatus enum', function () {
    $property = Property::factory()->create();
    $booking = createTestBooking($property, [
        'status' => 'confirmed',
        'check_in_status' => 'check-in',
    ]);

    expect($booking->fresh()->check_in_status)->toBe(BookingCheckInStatus::CheckIn)
        ->and($booking->fresh()->check_in_status->value)->toBe('check-in');
});

test('checking in updates check_in_status to check-in and checking out updates to Check Out', function () {
    $property = Property::factory()->create();
    $booking = createTestBooking($property, [
        'status' => 'confirmed',
        'outstanding' => 0,
        'check_in' => now()->toDateString(),
    ]);
    $guest = Guest::factory()->create(['property_id' => $property->id]);
    $booking->guests()->attach($guest);

    // Guest checks in
    $response = $this->actingAs($this->admin)->patch("/admin/properties/{$property->id}/bookings/{$booking->id}/status", [
        'status' => 'checked_in',
    ]);
    $response->assertRedirect();
    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::CheckedIn)
        ->and($booking->check_in_status)->toBe(BookingCheckInStatus::CheckIn)
        ->and($booking->check_in_status->value)->toBe('check-in');

    // Guest checks out
    $response2 = $this->actingAs($this->admin)->patch("/admin/properties/{$property->id}/bookings/{$booking->id}/status", [
        'status' => 'checked_out',
    ]);
    $response2->assertRedirect();
    $booking->refresh();
    expect($booking->status)->toBe(BookingStatus::CheckedOut)
        ->and($booking->check_in_status)->toBe(BookingCheckInStatus::CheckOut)
        ->and($booking->check_in_status->value)->toBe('Check Out');
});

test('user can mark booking check in status as no stay and logs activity', function () {
    $property = Property::factory()->create();
    $booking = createTestBooking($property, [
        'status' => 'confirmed',
    ]);
    $guest = Guest::factory()->create(['property_id' => $property->id]);
    $booking->guests()->attach($guest);

    $response = $this->actingAs($this->admin)->patch("/admin/properties/{$property->id}/bookings/{$booking->id}/status", [
        'status' => 'no_stay',
    ]);

    $response->assertRedirect();
    $booking->refresh();
    expect($booking->check_in_status)->toBe(BookingCheckInStatus::NoStay)
        ->and($booking->check_in_status->value)->toBe('No Stay');

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $this->admin->id,
        'property_id' => $property->id,
        'guest_id' => $guest->id,
        'action' => 'StayStatus : No Stay via Web',
    ]);
});

test('user can update check in status via check-in-status route', function () {
    $property = Property::factory()->create();
    $booking = createTestBooking($property, [
        'status' => 'confirmed',
    ]);
    $guest = Guest::factory()->create(['property_id' => $property->id]);
    $booking->guests()->attach($guest);

    $response = $this->actingAs($this->admin)->patch("/admin/properties/{$property->id}/bookings/{$booking->id}/check-in-status", [
        'check_in_status' => 'No Stay',
    ]);

    $response->assertRedirect();
    $booking->refresh();
    expect($booking->check_in_status)->toBe(BookingCheckInStatus::NoStay);
});

test('bookings can be filtered by check_in_status in booking database', function () {
    $property = Property::factory()->create();
    $checkedInBooking = createTestBooking($property, [
        'status' => 'checked_in',
        'check_in_status' => 'check-in',
    ]);
    $noStayBooking = createTestBooking($property, [
        'status' => 'confirmed',
        'check_in_status' => 'No Stay',
    ]);

    $response = $this->actingAs($this->admin)->get("/admin/databases/bookings?property={$property->id}&check_in_status=Check-In");
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/databases/Bookings')
        ->has('bookings.data', 1)
        ->where('bookings.data.0.id', $checkedInBooking->id)
    );

    $responseNoStay = $this->actingAs($this->admin)->get("/admin/databases/bookings?property={$property->id}&check_in_status=No+Stay");
    $responseNoStay->assertOk();
    $responseNoStay->assertInertia(fn ($page) => $page
        ->component('admin/databases/Bookings')
        ->has('bookings.data', 1)
        ->where('bookings.data.0.id', $noStayBooking->id)
    );
});
