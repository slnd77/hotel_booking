<?php

use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Mail\GuestBookingMail;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\BookingChannel;
use App\Models\Currency;
use App\Models\Guest;
use App\Models\Language;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\ApplicationSettings;
use App\Services\GuestEmails;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create();
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    Room::factory()->count(3)->create(['room_category_id' => $this->category->id]);
    $this->guest = Guest::factory()->create(['property_id' => $this->property->id, 'emails' => ['guest@example.com']]);

    Mail::fake();
});

/**
 * Books one room through the booking form, as the front desk does.
 *
 * @param  array<string, mixed>  $overrides
 */
function bookForGuest(array $overrides = []): Booking
{
    test()->actingAs(test()->admin)->post(route('admin.bookings.store', test()->property), [
        'booking_ref_id' => 'MAIL-1',
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => now()->addDays(3)->toDateString(),
        'check_out' => now()->addDays(5)->toDateString(),
        'status' => 'confirmed',
        'discount' => 0,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
        'guest_ids' => [test()->guest->id],
        'rooms' => [['room_category_id' => test()->category->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
        ...$overrides,
    ])->assertSessionHasNoErrors();

    return Booking::query()->where('booking_ref_id', $overrides['booking_ref_id'] ?? 'MAIL-1')->sole();
}

test('a confirmed booking e-mails the guest its confirmation and logs it', function () {
    $booking = bookForGuest();

    Mail::assertSent(GuestBookingMail::class, fn (GuestBookingMail $mail): bool => $mail->hasTo('guest@example.com')
        && $mail->email === GuestEmails::CONFIRMATION
        && $mail->booking->is($booking));
    Mail::assertSentCount(1);

    expect(ActivityLog::query()->where('action', 'like', 'Booking confirmation e-mailed to guest@example.com%')->exists())->toBeTrue();
});

test('a booking put on hold is confirmed by e-mail only once it is confirmed', function () {
    $booking = bookForGuest(['status' => 'on_hold', 'hold_release_at' => now()->addDay()->format('Y-m-d\TH:i')]);

    Mail::assertNothingSent();

    $this->actingAs($this->admin)->patch(route('admin.bookings.updateStatus', [$this->property, $booking]), ['status' => 'confirmed']);

    Mail::assertSent(GuestBookingMail::class, fn (GuestBookingMail $mail): bool => $mail->email === GuestEmails::CONFIRMATION);
});

test('cancelling a booking e-mails the guest', function () {
    $booking = bookForGuest();

    $this->actingAs($this->admin)->patch(route('admin.bookings.updateStatus', [$this->property, $booking]), ['status' => 'cancelled']);

    Mail::assertSent(GuestBookingMail::class, fn (GuestBookingMail $mail): bool => $mail->email === GuestEmails::CANCELLATION && $mail->hasTo('guest@example.com'));
});

test('a payment against the booking e-mails the guest a receipt; adjustments do not', function () {
    $booking = bookForGuest();
    $payment = fn (PaymentMode $mode, string $receipt): Payment => DB::transaction(fn () => Payment::create([
        'property_id' => $this->property->id,
        'booking_id' => $booking->id,
        'type' => 'receipt',
        'receipt_number' => $receipt,
        'payment_mode' => $mode,
        'total_txn_amount' => 500,
        'tagged_amount' => 500,
        'untagged_amount' => 0,
        'payment_creation_date' => now(),
        'payment_transaction_date' => now(),
        'status' => PaymentStatus::Received,
    ]));

    $payment(PaymentMode::UPI, 'R-1');
    $payment(PaymentMode::Commission, 'R-2');

    Mail::assertSent(GuestBookingMail::class, fn (GuestBookingMail $mail): bool => $mail->email === GuestEmails::RECEIPT && $mail->payment?->receipt_number === 'R-1');
    Mail::assertNotSent(GuestBookingMail::class, fn (GuestBookingMail $mail): bool => $mail->payment?->receipt_number === 'R-2');
});

test('OTA bookings, guests without an e-mail and e-mails switched off send nothing', function () {
    bookForGuest(['booking_ref_id' => 'OTA-1', 'source' => 'ota', 'booking_channel_id' => BookingChannel::factory()->create()->id]);

    $this->guest->update(['emails' => []]);
    bookForGuest(['booking_ref_id' => 'NOMAIL-1']);

    $this->guest->update(['emails' => ['guest@example.com']]);
    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.guestEmails.update'), ['booking_confirmation' => false, 'booking_cancellation' => true, 'payment_receipt' => true])
        ->assertSessionHasNoErrors();
    bookForGuest(['booking_ref_id' => 'OFF-1']);

    Mail::assertNothingSent();
    expect(app(ApplicationSettings::class)->sendsGuestEmail(GuestEmails::CONFIRMATION))->toBeFalse()
        ->and(app(ApplicationSettings::class)->sendsGuestEmail(GuestEmails::RECEIPT))->toBeTrue();
});

test('the confirmation e-mail shows the stay, rooms and amounts', function () {
    $booking = bookForGuest();
    $mail = new GuestBookingMail(GuestEmails::CONFIRMATION, $booking, 'Asha Rao');

    $mail->assertSeeInHtml('MAIL-1')
        ->assertSeeInHtml('Asha Rao')
        ->assertSeeInHtml($booking->check_in->format('d M Y'))
        ->assertSeeInHtml('1 × '.$this->category->name)
        ->assertHasSubject("Your booking at {$this->property->name} is confirmed (MAIL-1)");
});
