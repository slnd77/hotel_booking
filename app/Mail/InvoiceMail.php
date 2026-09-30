<?php

namespace App\Mail;

use App\Enums\BookingPrintDocument;
use App\Models\Booking;
use App\Models\User;
use App\Services\BookingPrints;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The guest's room invoice, sent at check-out with the invoice PDF attached. Queued: the PDF
 * is rendered by the queue worker, not during the check-out request.
 */
class InvoiceMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  User|null  $sentBy  printed on the invoice as who produced it
     */
    public function __construct(public Booking $booking, public string $guestName, public ?User $sentBy, public string $fileName, public bool $isProforma) {}

    public function envelope(): Envelope
    {
        $this->booking->loadMissing('property');

        return new Envelope(
            subject: ($this->isProforma ? 'Proforma invoice' : 'Invoice')." for your stay at {$this->booking->property->name} ({$this->booking->booking_ref_id})",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.invoice',
            with: [
                'propertyName' => $this->booking->property->name,
                'guestName' => $this->guestName,
                'bookingRef' => $this->booking->booking_ref_id,
                'checkIn' => $this->booking->check_in->format('d M Y'),
                'checkOut' => $this->booking->check_out->format('d M Y'),
                'isProforma' => $this->isProforma,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdf = app(BookingPrints::class)->pdf($this->booking, BookingPrintDocument::RoomInvoice, $this->sentBy);

        return [Attachment::fromData(fn (): string => $pdf->generatePdfContent(), $this->fileName)->withMime('application/pdf')];
    }
}
