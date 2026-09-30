<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create(['timezone' => 'Asia/Kolkata']);
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['room_category_id' => $this->category->id]);

    // 20:00 UTC on the 29th is 01:30 on the 30th in India.
    $this->travelTo(CarbonImmutable::parse('2026-09-29 20:00:00', 'UTC'));
});

function tzBooking(Property $property, RoomCategory $category, Room $room, string $checkIn, string $checkOut): Booking
{
    $booking = $property->bookings()->create([
        'booking_ref_id' => 'TZ-1', 'source' => 'direct', 'type' => 'walk_in', 'status' => BookingStatus::Confirmed,
        'check_in' => $checkIn, 'check_out' => $checkOut,
        'room_total' => 1000, 'discount' => 0, 'taxes' => 0, 'total' => 1000, 'outstanding' => 1000,
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
    ]);
    $booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $room->id, 'meal_plan' => 'EP', 'adults' => 1, 'rate_per_night' => 1000]);

    return $booking;
}

test('the business date is the property\'s local date, not UTC\'s', function () {
    expect(now()->toDateString())->toBe('2026-09-29')
        ->and($this->property->today())->toBe('2026-09-30')
        ->and($this->property->forceFill(['timezone' => 'America/New_York'])->today())->toBe('2026-09-29');
});

test('a guest arriving just after midnight local time can check in while UTC is still on the day before', function () {
    $booking = tzBooking($this->property, $this->category, $this->room, '2026-09-30', '2026-10-01');

    $this->actingAs($this->admin)
        ->patch(route('admin.bookings.updateStatus', [$this->property, $booking]), ['status' => 'checked_in'])
        ->assertSessionHas('success');

    $room = $booking->subBookings()->sole();

    // Stored as the UTC instant.
    expect($booking->fresh()->status)->toBe(BookingStatus::CheckedIn)
        ->and($room->checked_in_at->toIso8601ZuluString())->toBe('2026-09-29T20:00:00Z');
});

test('admin pages get the property timezone and times as ISO 8601 UTC', function () {
    $booking = tzBooking($this->property, $this->category, $this->room, '2026-09-30', '2026-10-01');
    $booking->subBookings()->sole()->update(['checked_in_at' => now()]);

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.show', [$this->property, $booking]))
        ->assertInertia(fn ($page) => $page
            ->where('timezone', 'Asia/Kolkata')
            ->where('today', '2026-09-30')
            ->where('booking.rooms.0.checked_in_at', '2026-09-29T20:00:00Z')
            ->where('booking.created_at', '2026-09-29T20:00:00Z'));
});

test('a hold release time entered in property time is stored in UTC and sent back in UTC', function () {
    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), [
        'booking_ref_id' => 'HOLD-TZ', 'source' => 'direct', 'type' => 'walk_in',
        'check_in' => '2026-10-05', 'check_out' => '2026-10-06', 'status' => 'on_hold', 'discount' => 0,
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        'rooms' => [['room_category_id' => $this->category->id, 'room_id' => $this->room->id, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
        // 2 pm in India, as the form sends it.
        'hold_release_at' => '2026-10-04T14:00:00+05:30',
        'hold_auto_release' => false,
        'hold_reminders' => [],
    ])->assertSessionHasNoErrors();

    $booking = Booking::query()->where('booking_ref_id', 'HOLD-TZ')->sole();

    expect($booking->hold_release_at->toIso8601ZuluString())->toBe('2026-10-04T08:30:00Z');

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.edit', [$this->property, $booking]))
        ->assertInertia(fn ($page) => $page->where('booking.hold_release_at', '2026-10-04T08:30:00Z'));
});

test('list pages get times in UTC while CSV exports carry the property\'s local time', function () {
    tzBooking($this->property, $this->category, $this->room, '2026-09-30', '2026-10-01');

    $this->actingAs($this->admin)
        ->get(route('admin.databases.bookings.index', ['property' => $this->property->id]))
        ->assertInertia(fn ($page) => $page->where('bookings.data.0.created_at', '2026-09-29T20:00:00Z'));

    $csv = $this->actingAs($this->admin)
        ->get(route('admin.databases.bookings.download', ['property' => $this->property->id, 'created_from' => '2026-09-25', 'created_to' => '2026-10-02']))
        ->assertOk()
        ->getContent();

    expect($csv)->toContain('2026-09-30 01:30:00')
        ->not->toContain('2026-09-29 20:00:00');
});

test('receipt numbers take the year from the property\'s calendar', function () {
    // 19:00 UTC on 31 Dec is already New Year in India.
    $this->travelTo(CarbonImmutable::parse('2026-12-31 19:00:00', 'UTC'));

    expect(Payment::nextReceiptNumber($this->property->id))->toStartWith('RCPT/2027-28/');
});

test('the room invoice prints the reported check-in time, and only the stay date before it', function () {
    $booking = tzBooking($this->property, $this->category, $this->room, '2026-09-30', '2026-10-01');

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.print', [$this->property, $booking, 'room-invoice']).'?preview=1')
        ->assertSee('Check-In :</span>30-Sep-2026</div>', false);

    $booking->subBookings()->sole()->update(['checked_in_at' => now()]);

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.print', [$this->property, $booking, 'room-invoice']).'?preview=1')
        ->assertSee('Check-In :</span>30-Sep-2026 ; 1:30 am</div>', false);
});

test('printed documents show times in the property\'s timezone', function () {
    $booking = tzBooking($this->property, $this->category, $this->room, '2026-09-30', '2026-10-01');

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.print', [$this->property, $booking, 'room-invoice']).'?preview=1')
        ->assertSee('30-Sep-2026 ; 1:30:00 am');
});
