<?php

use App\Enums\BookingStatus;
use App\Enums\SubBookingStatus;
use App\Mail\InvoiceMail;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Guest;
use App\Models\Invoice;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\SubBooking;
use App\Models\User;
use App\Services\RoomInventory;
use Illuminate\Support\Facades\Mail;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create();
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    Room::factory()->count(2)->create(['room_category_id' => $this->category->id]);

    // Arrived yesterday, leaving in two days: two rooms.
    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), [
        'booking_ref_id' => 'STAY-1',
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => now()->subDay()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
        'status' => 'confirmed',
        'discount' => 0,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
        'rooms' => array_fill(0, 2, ['room_category_id' => $this->category->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]),
    ])->assertSessionHasNoErrors();

    $this->booking = Booking::query()->where('booking_ref_id', 'STAY-1')->sole();
    [$this->first, $this->second] = $this->booking->subBookings()->orderBy('id')->get()->all();
});

function moveRoom(Booking $booking, SubBooking $room, string $status)
{
    return test()->actingAs(test()->admin)->patch(route('admin.bookings.rooms.stay', [$booking->property_id, $booking, $room]), ['status' => $status]);
}

test('checking in one room makes the booking checked in while the other room waits', function () {
    moveRoom($this->booking, $this->first, 'checked_in')->assertSessionHas('success');

    expect($this->first->fresh()->status)->toBe(SubBookingStatus::CheckedIn)
        ->and($this->second->fresh()->status)->toBe(SubBookingStatus::Booked)
        ->and($this->booking->fresh()->status)->toBe(BookingStatus::CheckedIn);

    // A later save of the booking doesn't flatten the rooms' own statuses.
    $this->booking->fresh()->update(['special_instructions' => 'Late dinner']);

    expect($this->second->fresh()->status)->toBe(SubBookingStatus::Booked);
});

test('an early check-out frees the room for the remaining nights; the last room out checks the booking out', function () {
    Permission::firstOrCreate(['name' => 'checkout_on_outstanding_payments']);
    $this->admin->givePermissionTo('checkout_on_outstanding_payments');

    moveRoom($this->booking, $this->first, 'checked_in');
    moveRoom($this->booking, $this->second, 'checked_in');
    moveRoom($this->booking, $this->first, 'checked_out')->assertSessionHas('success');

    $first = $this->first->fresh();
    $today = now()->toDateString();
    $unavailable = app(RoomInventory::class)->unavailableRoomIds($this->property->id, $today, now()->addDays(2)->toDateString());

    expect($first->status)->toBe(SubBookingStatus::CheckedOut)
        ->and($first->check_out_date->toDateString())->toBe($today)
        ->and($unavailable->all())->not->toContain($first->inventoryUnit->room_id)
        ->and($unavailable->all())->toContain($this->second->fresh()->inventoryUnit->room_id)
        ->and($this->booking->fresh()->status)->toBe(BookingStatus::CheckedIn);

    // On the Smart Booking Chart the departed room's bar now ends today.
    $chart = $this->actingAs($this->admin)->getJson(route('admin.smartBookingChartData', ['property_id' => $this->property->id, 'start_date' => now()->subDay()->toDateString()]))->json();
    $bars = collect($chart['categories'])->flatMap(fn (array $category) => $category['rooms'])->flatMap(fn (array $room) => $room['bookings']);

    expect($bars->firstWhere('sub_booking_id', $first->id)['check_out'])->toBe($today)
        ->and($bars->firstWhere('sub_booking_id', $first->id)['status'])->toBe('checked_out')
        ->and($bars->firstWhere('sub_booking_id', $this->second->id)['check_out'])->toBe(now()->addDays(2)->toDateString());

    moveRoom($this->booking, $this->second, 'checked_out')->assertSessionHas('success');

    expect($this->booking->fresh()->status)->toBe(BookingStatus::CheckedOut)
        ->and(Invoice::query()->where('booking_id', $this->booking->id)->exists())->toBeTrue();
});

test("a pay-at-hotel room with an amount due can't be checked out; otherwise it can", function () {
    moveRoom($this->booking, $this->first, 'checked_in');
    moveRoom($this->booking, $this->second, 'checked_in');

    $this->booking->update(['pay_at_hotel' => true]);
    moveRoom($this->booking, $this->first, 'checked_out')->assertSessionHas('error', 'It is set payable at hotel and it is not fully paid.');
    expect($this->first->fresh()->status)->toBe(SubBookingStatus::CheckedIn);

    // Not pay-at-hotel: leaving a balance needs the "check out with outstanding" permission.
    $this->booking->update(['pay_at_hotel' => false]);
    $clerk = User::factory()->create();
    $clerk->assignRole(Role::firstOrCreate(['name' => 'Clerk'])->givePermissionTo(Permission::firstOrCreate(['name' => 'reservation.check_out'])));
    $this->actingAs($clerk)
        ->patch(route('admin.bookings.rooms.stay', [$this->property, $this->booking, $this->first]), ['status' => 'checked_out'])
        ->assertSessionHas('error', 'Checkout with an outstanding balance requires authorization.');
    expect($this->first->fresh()->status)->toBe(SubBookingStatus::CheckedIn);

    moveRoom($this->booking, $this->first, 'checked_out')->assertSessionHas('success');
    expect($this->first->fresh()->status)->toBe(SubBookingStatus::CheckedOut);
});

test('undoing a check-in and skipping straight to check-out are handled', function () {
    moveRoom($this->booking, $this->first, 'checked_in');
    moveRoom($this->booking, $this->first, 'booked')->assertSessionHas('success');

    expect($this->first->fresh()->status)->toBe(SubBookingStatus::Booked)
        ->and($this->booking->fresh()->status)->toBe(BookingStatus::Confirmed);

    moveRoom($this->booking, $this->first, 'checked_out')->assertSessionHasErrors('status');
});

test('the booking-level check-in button checks in every room', function () {
    $this->actingAs($this->admin)
        ->patch(route('admin.bookings.updateStatus', [$this->property, $this->booking]), ['status' => 'checked_in'])
        ->assertSessionHas('success');

    expect($this->booking->subBookings()->pluck('status')->unique()->all())->toBe([SubBookingStatus::CheckedIn]);
});

test('check-in and check-out record the date and time the front desk reports', function () {
    Permission::firstOrCreate(['name' => 'checkout_on_outstanding_payments']);
    $this->admin->givePermissionTo('checkout_on_outstanding_payments');

    // Sent from the browser with its UTC offset; stored in the app's timezone.
    $arrived = now()->subHours(3)->startOfMinute();
    $this->actingAs($this->admin)
        ->patch(route('admin.bookings.updateStatus', [$this->property, $this->booking]), ['status' => 'checked_in', 'reported_at' => $arrived->copy()->setTimezone('Asia/Kolkata')->toIso8601String()])
        ->assertSessionHas('success');

    expect($this->first->fresh()->checked_in_at->equalTo($arrived))->toBeTrue()
        ->and($this->second->fresh()->checked_in_at->equalTo($arrived))->toBeTrue();

    // A future time, or a check-out before the check-in, is refused.
    moveRoom($this->booking, $this->first, 'checked_out')->assertSessionHas('success');
    $this->actingAs($this->admin)
        ->patch(route('admin.bookings.rooms.stay', [$this->property, $this->booking, $this->second]), ['status' => 'checked_out', 'reported_at' => now()->addHour()->toIso8601String()])
        ->assertSessionHasErrors('reported_at');
    $this->actingAs($this->admin)
        ->patch(route('admin.bookings.rooms.stay', [$this->property, $this->booking, $this->second]), ['status' => 'checked_out', 'reported_at' => $arrived->copy()->subHour()->toIso8601String()])
        ->assertSessionHas('error');

    expect($this->second->fresh()->status)->toBe(SubBookingStatus::CheckedIn);
});

test('checking a room in and out records the reported times, and undo clears them', function () {
    Permission::firstOrCreate(['name' => 'checkout_on_outstanding_payments']);
    $this->admin->givePermissionTo('checkout_on_outstanding_payments');

    moveRoom($this->booking, $this->first, 'checked_in');
    expect($this->first->fresh()->checked_in_at)->not->toBeNull()
        ->and($this->first->fresh()->checked_out_at)->toBeNull();

    moveRoom($this->booking, $this->first, 'checked_out');
    expect($this->first->fresh()->checked_out_at)->not->toBeNull();

    moveRoom($this->booking, $this->first, 'checked_in');
    expect($this->first->fresh()->checked_out_at)->toBeNull()
        ->and($this->first->fresh()->checked_in_at)->not->toBeNull();

    moveRoom($this->booking, $this->first, 'booked');
    expect($this->first->fresh()->checked_in_at)->toBeNull();

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.show', [$this->property, $this->booking]))
        ->assertInertia(fn ($page) => $page->where('booking.rooms.0.checked_in_at', null)->has('booking.rooms.0.stay_status'));
});

test('checking out only one of several in-house rooms splits the booking so that room leaves on its own', function () {
    Permission::firstOrCreate(['name' => 'checkout_on_outstanding_payments']);
    $this->admin->givePermissionTo('checkout_on_outstanding_payments');

    moveRoom($this->booking, $this->first, 'checked_in');
    moveRoom($this->booking, $this->second, 'checked_in');
    expect($this->booking->fresh()->rooms_split)->toBeFalse();

    moveRoom($this->booking, $this->first, 'checked_out')->assertSessionHas('success');

    $booking = $this->booking->fresh();
    expect($booking->rooms_split)->toBeTrue()
        ->and($booking->status)->toBe(BookingStatus::CheckedIn)
        ->and($this->first->fresh()->check_out_date->toDateString())->toBe(now()->toDateString())
        ->and($this->second->fresh()->status)->toBe(SubBookingStatus::CheckedIn)
        ->and($this->second->fresh()->check_out_date->toDateString())->toBe(now()->addDays(2)->toDateString());
});

test('a room or booking can only be checked in from its check-in date until the day before check-out', function () {
    $this->booking->update(['check_in' => now()->addDay()->toDateString(), 'check_out' => now()->addDays(3)->toDateString()]);

    moveRoom($this->booking, $this->first, 'checked_in')->assertSessionHas('error');
    expect($this->first->fresh()->status)->toBe(SubBookingStatus::Booked);

    $this->actingAs($this->admin)
        ->patch(route('admin.bookings.updateStatus', [$this->property, $this->booking]), ['status' => 'checked_in'])
        ->assertSessionHas('error');
    expect($this->booking->fresh()->status)->toBe(BookingStatus::Confirmed);
});

test('check-out of only the selected rooms splits the booking and leaves the others in house', function () {
    Permission::firstOrCreate(['name' => 'checkout_on_outstanding_payments']);
    $this->admin->givePermissionTo('checkout_on_outstanding_payments');

    moveRoom($this->booking, $this->first, 'checked_in');
    moveRoom($this->booking, $this->second, 'checked_in');

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.rooms.checkOut', [$this->property, $this->booking]), ['sub_booking_ids' => [$this->first->id]])
        ->assertSessionHas('success');

    expect($this->first->fresh()->status)->toBe(SubBookingStatus::CheckedOut)
        ->and($this->first->fresh()->checked_out_at)->not->toBeNull()
        ->and($this->second->fresh()->status)->toBe(SubBookingStatus::CheckedIn)
        ->and($this->booking->fresh()->rooms_split)->toBeTrue()
        ->and($this->booking->fresh()->status)->toBe(BookingStatus::CheckedIn);
});

test('selected rooms of a pay-at-hotel booking with an amount due can\'t be checked out', function () {
    moveRoom($this->booking, $this->first, 'checked_in');
    moveRoom($this->booking, $this->second, 'checked_in');
    $this->booking->update(['pay_at_hotel' => true]);

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.rooms.checkOut', [$this->property, $this->booking]), ['sub_booking_ids' => [$this->first->id]])
        ->assertSessionHas('error', 'It is set payable at hotel and it is not fully paid.');

    expect($this->first->fresh()->status)->toBe(SubBookingStatus::CheckedIn);
});

test('checking out with "send invoice" e-mails the guest the invoice PDF; without it nothing is sent', function () {
    Mail::fake();
    Pdf::fake();
    $this->booking->guests()->attach(Guest::factory()->create(['property_id' => $this->property->id, 'emails' => ['guest@example.com']]));

    $this->actingAs($this->admin)->patch(route('admin.bookings.updateStatus', [$this->property, $this->booking]), ['status' => 'checked_in']);
    $this->actingAs($this->admin)
        ->patch(route('admin.bookings.updateStatus', [$this->property, $this->booking]), ['status' => 'checked_out', 'send_invoice' => true])
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'e-mailed to the guest'));

    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail): bool => $mail->hasTo('guest@example.com')
        && ! $mail->isProforma
        && $mail->fileName === 'room-invoice-STAY-1.pdf'
        && count($mail->attachments()) === 1);

    $this->actingAs($this->admin)->patch(route('admin.bookings.updateStatus', [$this->property, $this->booking]), ['status' => 'confirmed']);
    $this->actingAs($this->admin)->patch(route('admin.bookings.updateStatus', [$this->property, $this->booking]), ['status' => 'checked_in']);
    $this->actingAs($this->admin)->patch(route('admin.bookings.updateStatus', [$this->property, $this->booking]), ['status' => 'checked_out']);

    Mail::assertQueuedCount(1);
});

test('the invoice e-mail is skipped with a warning when the guest has no address, and the check-out still goes through', function () {
    Mail::fake();

    moveRoom($this->booking, $this->first, 'checked_in');
    moveRoom($this->booking, $this->second, 'checked_in');

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.rooms.checkOut', [$this->property, $this->booking]), ['sub_booking_ids' => [$this->first->id], 'send_invoice' => true])
        ->assertSessionHas('success')
        ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'no e-mail address'));

    expect($this->first->fresh()->status)->toBe(SubBookingStatus::CheckedOut);
    Mail::assertNothingOutgoing();
});

test('checking out some of the rooms e-mails a proforma invoice', function () {
    Mail::fake();
    Pdf::fake();
    $this->booking->guests()->attach(Guest::factory()->create(['property_id' => $this->property->id, 'emails' => ['guest@example.com']]));

    moveRoom($this->booking, $this->first, 'checked_in');
    moveRoom($this->booking, $this->second, 'checked_in');

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.rooms.checkOut', [$this->property, $this->booking]), ['sub_booking_ids' => [$this->first->id], 'send_invoice' => true])
        ->assertSessionHas('success');

    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail): bool => $mail->isProforma);
});

test('check-in of only the selected rooms leaves the others waiting', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.bookings.rooms.checkIn', [$this->property, $this->booking]), ['sub_booking_ids' => [$this->second->id]])
        ->assertSessionHas('success');

    expect($this->second->fresh()->status)->toBe(SubBookingStatus::CheckedIn)
        ->and($this->second->fresh()->checked_in_at)->not->toBeNull()
        ->and($this->first->fresh()->status)->toBe(SubBookingStatus::Booked)
        ->and($this->booking->fresh()->status)->toBe(BookingStatus::CheckedIn);

    $otherBooking = $this->property->bookings()->create([
        'booking_ref_id' => 'STAY-2', 'source' => 'direct', 'type' => 'walk_in', 'status' => BookingStatus::Confirmed,
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(),
        'room_total' => 0, 'discount' => 0, 'taxes' => 0, 'total' => 0, 'outstanding' => 0, 'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
    ]);

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.rooms.checkIn', [$this->property, $otherBooking]), ['sub_booking_ids' => [$this->first->id]])
        ->assertSessionHasErrors('sub_booking_ids.0');

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.rooms.checkIn', [$this->property, $this->booking]), ['sub_booking_ids' => []])
        ->assertSessionHasErrors('sub_booking_ids');
});
