<?php

use App\Enums\BookingStatus;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Guest;
use App\Models\Invoice;
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

    $this->creator = User::factory()->create(['name' => 'Creator Clerk']);
    $this->creator->assignRole('admin');
    $this->viewer = User::factory()->create(['name' => 'Viewing Manager']);
    $this->viewer->assignRole('admin');

    $this->property = Property::factory()->create();
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    Room::factory()->count(2)->create(['room_category_id' => $this->category->id]);
    $this->guest = Guest::factory()->create(['property_id' => $this->property->id]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function showAuditPayload(RoomCategory $category, array $overrides = []): array
{
    return [
        'booking_ref_id' => 'AUDIT-1', 'source' => 'direct', 'type' => 'walk_in', 'status' => 'confirmed', 'discount' => 0,
        'check_in' => now()->addDays(2)->toDateString(), 'check_out' => now()->addDays(4)->toDateString(),
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        'rooms' => [['room_category_id' => $category->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
        ...$overrides,
    ];
}

function showAuditBooking(Property $property, RoomCategory $category, string $ref): Booking
{
    $booking = $property->bookings()->create([
        'booking_ref_id' => $ref, 'source' => 'direct', 'type' => 'walk_in', 'status' => BookingStatus::Confirmed,
        'check_in' => now()->addDays(2)->toDateString(), 'check_out' => now()->addDays(4)->toDateString(),
        'room_total' => 2000, 'discount' => 0, 'taxes' => 100, 'total' => 2100, 'outstanding' => 2100,
    ]);
    $booking->rooms()->create(['room_category_id' => $category->id, 'meal_plan' => 'EP', 'adults' => 1, 'rate_per_night' => 1000]);

    return $booking;
}

test('the booking page names who created and last changed the booking, not who is looking at it', function () {
    $this->actingAs($this->creator)
        ->post(route('admin.bookings.store', $this->property), showAuditPayload($this->category, ['guest_ids' => [$this->guest->id]]))
        ->assertSessionHasNoErrors();
    $booking = Booking::query()->where('booking_ref_id', 'AUDIT-1')->sole();

    expect($booking->created_by)->toBe($this->creator->id);

    $this->actingAs($this->viewer)
        ->get(route('admin.bookings.show', [$this->property, $booking]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/bookings/Show')
            ->where('booking.created_by', 'Creator Clerk')
            ->where('booking.updated_by', 'Creator Clerk'));

    // The viewer changes it: they are now the last to update it; the creator stays.
    $this->actingAs($this->viewer)
        ->put(route('admin.bookings.update', [$this->property, $booking]), showAuditPayload($this->category, ['guest_ids' => [$this->guest->id], 'special_instructions' => 'Quiet room']))
        ->assertSessionHasNoErrors();

    $this->actingAs($this->creator)
        ->get(route('admin.bookings.show', [$this->property, $booking]))
        ->assertInertia(fn ($page) => $page
            ->where('booking.created_by', 'Creator Clerk')
            ->where('booking.updated_by', 'Viewing Manager'));
});

test('the booking page\'s activity log lists only that booking\'s entries', function () {
    $booking = showAuditBooking($this->property, $this->category, 'MINE');
    $other = showAuditBooking($this->property, $this->category, 'OTHER');
    $booking->guests()->attach($this->guest->id);
    $other->guests()->attach($this->guest->id);

    $log = fn (array $attributes) => ActivityLog::create(['user_id' => $this->creator->id, 'property_id' => $this->property->id, 'date' => now(), ...$attributes]);
    $log(['booking_id' => $booking->id, 'guest_id' => $this->guest->id, 'action' => 'Cash receipt # RCPT/1 for amount 500 received.']);
    $log(['booking_id' => null, 'guest_id' => null, 'action' => 'Bill # INV1 generated for booking MINE.']);
    $log(['booking_id' => $other->id, 'guest_id' => $this->guest->id, 'action' => 'StayStatus : No Stay via Web']);
    $log(['booking_id' => null, 'guest_id' => null, 'action' => 'Housekeeping : 101 marked as Dirty']);
    $log(['booking_id' => null, 'guest_id' => $this->guest->id, 'action' => 'Reservation modified via Web']);

    $this->actingAs($this->viewer)
        ->get(route('admin.bookings.show', [$this->property, $booking]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activityLogs', fn ($logs) => collect($logs)->pluck('action')->sort()->values()->all() === [
                'Bill # INV1 generated for booking MINE.',
                'Cash receipt # RCPT/1 for amount 500 received.',
            ]));
});

test('quick book needs at least one guest', function () {
    $this->actingAs($this->creator)
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(route('admin.bookings.store', $this->property), showAuditPayload($this->category))
        ->assertSessionHasErrors('guest_ids');
    $this->actingAs($this->creator)
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(route('admin.bookings.store', $this->property), showAuditPayload($this->category, ['guest_ids' => []]))
        ->assertSessionHasErrors('guest_ids');

    expect(Booking::query()->where('booking_ref_id', 'AUDIT-1')->exists())->toBeFalse();

    $this->actingAs($this->creator)
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(route('admin.bookings.store', $this->property), showAuditPayload($this->category, ['guest_ids' => [$this->guest->id]]))
        ->assertSessionHasNoErrors();

    expect(Booking::query()->where('booking_ref_id', 'AUDIT-1')->sole()->guests()->count())->toBe(1);
});

test('the invoice bills the add-ons along with the rooms', function () {
    $booking = showAuditBooking($this->property, $this->category, 'ADDONS');
    $booking->guests()->attach($this->guest->id);
    $booking->addons()->create(['name' => 'Airport pickup', 'pricing' => 'per_booking', 'unit_price' => 800, 'quantity' => 1, 'multiplier' => 1, 'amount' => 800, 'tax_rate' => 0, 'tax_amount' => 0, 'total' => 800, 'added_from' => 'front_desk']);
    $booking->forceFill(['addons_total' => 800, 'outstanding' => 2900])->save();

    $invoice = Invoice::generateForBooking($booking->fresh(), 'Clerk');

    expect((float) $invoice->invoice_amount)->toBe(2900.0)
        ->and((float) $invoice->taxable_amount)->toBe(2800.0)
        ->and((float) $invoice->total_tax)->toBe(100.0)
        ->and((float) $invoice->invoice_outstanding_amount)->toBe(2900.0);
});
