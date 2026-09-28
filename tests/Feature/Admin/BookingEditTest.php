<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Guest;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomCategory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function editPayload(RoomCategory $category, int $roomId, string $checkIn, string $checkOut, array $overrides = []): array
{
    return [
        'booking_ref_id' => 'EDITME',
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'status' => 'confirmed',
        'discount' => 0,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
        'rooms' => [['room_category_id' => $category->id, 'room_id' => $roomId, 'meal_plan' => 'EP', 'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
        ...$overrides,
    ];
}

function createBookingForEdit(Property $property, RoomCategory $category, int $roomId, string $checkIn, string $checkOut, array $attributes = []): Booking
{
    $booking = $property->bookings()->create([
        'booking_ref_id' => 'EDITME',
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'status' => BookingStatus::Confirmed,
        'room_total' => 2000,
        'discount' => 0,
        'taxes' => 0,
        'total' => 2000,
        'outstanding' => 1500,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
        ...$attributes,
    ]);
    $booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $roomId, 'meal_plan' => 'EP', 'adults' => 2, 'rate_per_night' => 1000]);

    return $booking;
}

test('the edit page loads the booking into the booking form', function () {
    $booking = createBookingForEdit($this->property, $this->category, $this->rooms[0]->id, $this->checkIn, $this->checkOut);

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.edit', [$this->property, $booking]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/bookings/Create')
            ->where('booking.id', $booking->id)
            ->where('booking.rooms.0.room_id', $this->rooms[0]->id)
            ->where('booking.check_in', $this->checkIn)
            ->where('billing.booking.id', $booking->id)
            ->where('billing.booking.outstanding', 1500)
            ->has('billing.booking.payments')
            ->has('billing.booking.payment_links')
            ->has('billing.folios'));
});

test('updating keeps the booking its own room and keeps what was already paid', function () {
    $booking = createBookingForEdit($this->property, $this->category, $this->rooms[0]->id, $this->checkIn, $this->checkOut);
    $guest = Guest::create(['property_id' => $this->property->id, 'first_name' => 'Asha']);

    $this->actingAs($this->admin)
        ->put(route('admin.bookings.update', [$this->property, $booking]), editPayload($this->category, $this->rooms[0]->id, $this->checkIn, now()->addDays(6)->toDateString(), [
            'guest_ids' => [$guest->id],
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.bookings.show', [$this->property, $booking]));

    $booking->refresh();

    // 3 nights x 1000 = 3000; 500 was already paid (2000 total - 1500 outstanding).
    expect((float) $booking->total)->toBe(3000.0)
        ->and((float) $booking->outstanding)->toBe(2500.0)
        ->and($booking->check_out->toDateString())->toBe(now()->addDays(6)->toDateString())
        ->and($booking->rooms()->sole()->room_id)->toBe($this->rooms[0]->id)
        ->and($booking->guests()->pluck('guests.id')->all())->toBe([$guest->id]);
});

test('updating rejects moving to a room that is blocked', function () {
    $booking = createBookingForEdit($this->property, $this->category, $this->rooms[0]->id, $this->checkIn, $this->checkOut);
    RoomBlock::factory()->forRoom($this->rooms[1])->between($this->checkIn, $this->checkOut)->create();

    $this->actingAs($this->admin)
        ->put(route('admin.bookings.update', [$this->property, $booking]), editPayload($this->category, $this->rooms[1]->id, $this->checkIn, $this->checkOut))
        ->assertSessionHasErrors('rooms');

    expect($booking->rooms()->sole()->room_id)->toBe($this->rooms[0]->id);
});

test('updating adds new documents and deletes removed ones', function () {
    Storage::fake('local');
    $booking = createBookingForEdit($this->property, $this->category, $this->rooms[0]->id, $this->checkIn, $this->checkOut);
    Storage::disk('local')->put('booking-attachments/old.pdf', 'old');
    $old = $booking->attachments()->create(['document_type' => 'Passport', 'path' => 'booking-attachments/old.pdf', 'original_name' => 'old.pdf', 'mime_type' => 'application/pdf', 'size' => 3]);

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.update', [$this->property, $booking]), editPayload($this->category, $this->rooms[0]->id, $this->checkIn, $this->checkOut, [
            '_method' => 'put',
            'removed_attachment_ids' => [$old->id],
            'attachments' => [['document_type' => 'Pan Card', 'document_number' => 'ABCDE1234F', 'expiry_date' => '', 'file' => UploadedFile::fake()->image('pan.jpg')]],
        ]))
        ->assertSessionHasNoErrors();

    $remaining = $booking->attachments()->get();

    expect($remaining)->toHaveCount(1)
        ->and($remaining->first()->document_type)->toBe('Pan Card');
    Storage::disk('local')->assertMissing('booking-attachments/old.pdf');
    Storage::disk('local')->assertExists($remaining->first()->path);
});

test('cancelled and checked out bookings cannot be edited', function () {
    $booking = createBookingForEdit($this->property, $this->category, $this->rooms[0]->id, $this->checkIn, $this->checkOut, ['status' => BookingStatus::Cancelled]);

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.edit', [$this->property, $booking]))
        ->assertRedirect(route('admin.bookings.show', [$this->property, $booking]));

    $this->actingAs($this->admin)
        ->put(route('admin.bookings.update', [$this->property, $booking]), editPayload($this->category, $this->rooms[0]->id, $this->checkIn, $this->checkOut, ['status' => 'cancelled']))
        ->assertForbidden();
});

test('save & add payment reopens the new booking in the edit form; confirm & email goes to the dashboard', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), editPayload($this->category, $this->rooms[0]->id, $this->checkIn, $this->checkOut, ['after' => 'edit']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.bookings.edit', [$this->property, Booking::sole()]));

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), editPayload($this->category, $this->rooms[1]->id, $this->checkIn, $this->checkOut, ['booking_ref_id' => 'SECOND']))
        ->assertRedirect(route('admin.dashboard', ['property' => $this->property->id]));
});
