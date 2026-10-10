<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Jobs\SendGuestEmail;
use App\Mail\GuestBookingMail;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\Mail;

/**
 * E-mails the guest gets on their own: the booking confirmation, its cancellation and a
 * receipt for each payment. Booking and payment writes queue them (after the transaction
 * commits); the queued job checks the settings and the guest's address, then sends.
 * OTA bookings are left out: the OTA mails its own guests.
 */
class GuestEmails
{
    public const CONFIRMATION = 'booking_confirmation';

    public const CANCELLATION = 'booking_cancellation';

    public const RECEIPT = 'payment_receipt';

    /**
     * Statuses a booking is confirmed from: a new booking, or one that was only held or quoted.
     */
    private const PROVISIONAL = [BookingStatus::OnHold, BookingStatus::Enquiry, BookingStatus::Pending, BookingStatus::ExpiredQuote];

    /**
     * Payment modes that aren't money from the guest (adjustments, write-offs, OTA settlements).
     */
    private const NOT_RECEIPTS = [PaymentMode::DebitNote, PaymentMode::CreditNote, PaymentMode::Commission, PaymentMode::TDS, PaymentMode::BadDebts, PaymentMode::OTA];

    public function __construct(private ApplicationSettings $settings, private BookingPrints $prints) {}

    /**
     * After a booking is saved: confirmed (new, or from on hold / enquiry) or cancelled.
     */
    public function bookingSaved(Booking $booking): void
    {
        if ($booking->source === Booking::SOURCE_OTA) {
            return;
        }

        $previous = $booking->wasRecentlyCreated ? null : $booking->getOriginal('status');

        if (! $booking->wasRecentlyCreated && ! $booking->wasChanged('status')) {
            return;
        }

        if ($booking->status === BookingStatus::Confirmed && ($previous === null || in_array($previous, self::PROVISIONAL, true))) {
            SendGuestEmail::dispatch(self::CONFIRMATION, $booking, null, auth()->user()?->id)->afterCommit();
        }

        if ($booking->status === BookingStatus::Cancelled && $previous !== null && ! in_array($previous, self::PROVISIONAL, true)) {
            SendGuestEmail::dispatch(self::CANCELLATION, $booking, null, auth()->user()?->id)->afterCommit();
        }
    }

    /**
     * After a receipt is recorded against a booking.
     */
    public function paymentCreated(Payment $payment): void
    {
        if ($payment->booking_id === null
            || $payment->status !== PaymentStatus::Received
            || ($payment->type ?? 'receipt') !== 'receipt'
            || in_array($payment->payment_mode, self::NOT_RECEIPTS, true)
            || (float) $payment->total_txn_amount <= 0) {
            return;
        }

        $booking = $payment->booking;

        if ($booking === null || $booking->source === Booking::SOURCE_OTA) {
            return;
        }

        SendGuestEmail::dispatch(self::RECEIPT, $booking, $payment, auth()->user()?->id)->afterCommit();
    }

    /**
     * Sends the e-mail now (the queued job); false when it's switched off, no longer applies
     * or the guest has no e-mail address. $userId: who made the change, for the activity log.
     */
    public function send(string $email, Booking $booking, ?Payment $payment = null, ?int $userId = null): bool
    {
        if (! $this->settings->sendsGuestEmail($email) || ! $this->stillApplies($email, $booking, $payment)) {
            return false;
        }

        $booking->loadMissing('guests', 'property');
        $guest = $this->prints->guest($booking->guests->first());

        if (! filled($guest['email'] ?? null)) {
            return false;
        }

        Mail::to($guest['email'])->send(new GuestBookingMail($email, $booking, $guest['name'], $payment));

        // Logged against whoever made the change, or the booking's creator (booking engine: none).
        $userId ??= $booking->created_by;

        if ($userId === null) {
            return true;
        }

        ActivityLog::create([
            'user_id' => $userId,
            'property_id' => $booking->property_id,
            'booking_id' => $booking->id,
            'guest_id' => $booking->guests->first()?->id,
            'action' => ApplicationSettings::GUEST_EMAILS[$email]
                .($payment?->receipt_number ? " # {$payment->receipt_number}" : '')
                ." e-mailed to {$guest['email']} (booking {$booking->booking_ref_id}).",
            'date' => now(),
        ]);

        return true;
    }

    /**
     * The booking may have moved on while the e-mail waited in the queue.
     */
    private function stillApplies(string $email, Booking $booking, ?Payment $payment): bool
    {
        return match ($email) {
            self::CONFIRMATION => in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::CheckedIn], true),
            self::CANCELLATION => $booking->status === BookingStatus::Cancelled,
            self::RECEIPT => $payment !== null && $payment->status === PaymentStatus::Received,
            default => false,
        };
    }
}
