<?php

namespace App\Mail;

use App\Models\OutletBill;
use App\Services\PosPrints;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * An outlet (POS) bill e-mailed to the guest with its PDF attached. Queued: the PDF is
 * rendered by the queue worker.
 */
class OutletBillMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public OutletBill $bill) {}

    public function envelope(): Envelope
    {
        $this->bill->loadMissing(['outlet', 'property']);

        return new Envelope(subject: "Your bill from {$this->bill->outlet->name}, {$this->bill->property->name} (#{$this->bill->bill_number})");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.outlet-bill',
            with: [
                'propertyName' => $this->bill->property->name,
                'outletName' => $this->bill->outlet->name,
                'guestName' => $this->bill->guest_name ?: 'Guest',
                'billNumber' => $this->bill->bill_number,
                'businessDate' => $this->bill->business_date->format('d M Y'),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $pdf = app(PosPrints::class)->billPdf($this->bill);

        return [Attachment::fromData(fn (): string => $pdf->generatePdfContent(), $pdf->downloadName)->withMime('application/pdf')];
    }
}
