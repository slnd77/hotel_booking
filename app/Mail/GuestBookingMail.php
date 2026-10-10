<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\SubBooking;
use App\Services\GuestEmails;
use App\Services\WebCheckins;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A guest e-mail about their booking: its confirmation, its cancellation or a payment receipt.
 * Sent from the SendGuestEmail job (already queued).
 */
class GuestBookingMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $email  GuestEmails::CONFIRMATION, CANCELLATION or RECEIPT
     */
    public function __construct(public string $email, public Booking $booking, public string $guestName, public ?Payment $payment = null) {}

    public function envelope(): Envelope
    {
        $this->booking->loadMissing('property');
        $property = $this->booking->property->name;
        $reference = $this->booking->booking_ref_id;

        return new Envelope(subject: match ($this->email) {
            GuestEmails::CANCELLATION => "Booking {$reference} at {$property} is cancelled",
            GuestEmails::RECEIPT => "Payment received for booking {$reference} at {$property}",
            default => "Your booking at {$property} is confirmed ({$reference})",
        });
    }

    public function content(): Content
    {
        $this->booking->loadMissing('property.currency', 'rooms.roomCategory');
        $property = $this->booking->property;
        $currency = $property->currency->code ?? Currency::DEFAULT_CODE;
        $money = fn (float|int|string|null $amount): string => $currency.' '.number_format((float) $amount, 2);
        // Rooms plus any add-on services.
        $total = (float) $this->booking->total + (float) $this->booking->addons_total;
        $outstanding = (float) $this->booking->outstanding;

        return new Content(
            markdown: match ($this->email) {
                GuestEmails::CANCELLATION => 'mail.guest-booking-cancellation',
                GuestEmails::RECEIPT => 'mail.guest-payment-receipt',
                default => 'mail.guest-booking-confirmation',
            },
            with: [
                'propertyName' => $property->name,
                'propertyAddress' => collect([$property->address_line_1, $property->address_line_2])->filter()->implode(', '),
                'guestName' => $this->guestName,
                'bookingRef' => $this->booking->booking_ref_id,
                'checkIn' => $this->booking->check_in->format('d M Y'),
                'checkOut' => $this->booking->check_out->format('d M Y'),
                'nights' => (int) $this->booking->check_in->startOfDay()->diffInDays($this->booking->check_out->startOfDay()),
                'rooms' => $this->booking->rooms
                    ->groupBy(fn (SubBooking $room): string => $room->roomCategory->name ?? 'Room')
                    ->map(fn ($rooms, string $category): string => "{$rooms->count()} × {$category}")
                    ->values()
                    ->all(),
                'total' => $money($total),
                'paid' => $money(max(0, $total - $outstanding)),
                'outstanding' => $outstanding > 0 ? $money($outstanding) : null,
                'receiptNumber' => $this->payment?->receipt_number,
                'paymentAmount' => $this->payment ? $money($this->payment->total_txn_amount) : null,
                'paymentMode' => $this->payment?->payment_mode?->label(),
                // The e-mail is read as-is: the property's local time, with its zone.
                'webCheckinUrl' => $this->email === GuestEmails::CONFIRMATION ? $this->webCheckinUrl() : null,
                'paidAt' => $this->payment ? $property->localTime($this->payment->payment_transaction_date ?? $this->payment->created_at)->format('d M Y, h:i A T') : null,
            ],
        );
    }

    /**
     * The guest's web check-in link, when the property offers it in the confirmation.
     */
    private function webCheckinUrl(): ?string
    {
        $checkins = app(WebCheckins::class);
        $settings = $checkins->settings($this->booking->property);

        return $settings['enabled'] && $settings['include_in_confirmation_email'] ? $checkins->url($checkins->for($this->booking)) : null;
    }
}
