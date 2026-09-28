<?php

namespace App\Mail;

use App\Models\PaymentLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Collect Online" e-mail with the link to pay part or all of a booking.
 */
class PaymentLinkMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public PaymentLink $paymentLink) {}

    public function envelope(): Envelope
    {
        $this->paymentLink->loadMissing('property', 'booking');

        return new Envelope(
            subject: "Payment request for booking {$this->paymentLink->booking->booking_ref_id} at {$this->paymentLink->property->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.payment-link',
            with: [
                'propertyName' => $this->paymentLink->property->name,
                'bookingRef' => $this->paymentLink->booking->booking_ref_id,
                'checkIn' => $this->paymentLink->booking->check_in->format('d M Y'),
                'checkOut' => $this->paymentLink->booking->check_out->format('d M Y'),
                'amount' => $this->paymentLink->currency.' '.number_format((float) $this->paymentLink->amount, 2),
                // The e-mail is read as-is: the property's local time, with its zone.
                'expiresAt' => $this->paymentLink->expires_at ? $this->paymentLink->property->localTime($this->paymentLink->expires_at)->format('d M Y, h:i A T') : null,
                'url' => $this->paymentLink->url(),
            ],
        );
    }
}
