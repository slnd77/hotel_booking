<?php

namespace App\Mail;

use App\Models\WebCheckin;
use App\Services\WebCheckins;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The guest's web check-in link, sent from the booking.
 */
class WebCheckinLinkMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public WebCheckin $checkin) {}

    public function envelope(): Envelope
    {
        $this->checkin->loadMissing('booking.property');

        return new Envelope(subject: "Check in online for your stay at {$this->checkin->booking->property->name} ({$this->checkin->booking->booking_ref_id})");
    }

    public function content(): Content
    {
        $booking = $this->checkin->booking;
        $booking->loadMissing(['property', 'guests']);
        $guest = $booking->guests->first();

        return new Content(
            markdown: 'mail.web-checkin-link',
            with: [
                'propertyName' => $booking->property->name,
                'guestName' => $guest ? trim("{$guest->first_name} {$guest->last_name}") : 'Guest',
                'bookingRef' => $booking->booking_ref_id,
                'checkIn' => $booking->check_in->format('d M Y'),
                'url' => app(WebCheckins::class)->url($this->checkin),
            ],
        );
    }
}
