<?php

namespace App\Services;

use App\Enums\BookingPrintDocument;
use App\Mail\InvoiceMail;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Check-out → "Send invoice to guest": e-mails the room invoice (the same PDF as its print
 * view) to the booking's guest, through the queue. A proforma goes out while rooms of the
 * booking are still in house.
 */
class InvoiceEmails
{
    public function __construct(private BookingPrints $prints) {}

    /**
     * Queues the invoice; returns why it couldn't be (the check-out itself stands).
     */
    public function sendToGuest(Booking $booking, ?User $sentBy): ?string
    {
        $booking->loadMissing('guests', 'property');
        $guest = $this->prints->guest($booking->guests->first());

        if (! filled($guest['email'] ?? null)) {
            return 'The invoice was not e-mailed: the guest has no e-mail address.';
        }

        $invoice = Invoice::query()->where('booking_id', $booking->id)->first();

        try {
            $fileName = $this->prints->pdf($booking, BookingPrintDocument::RoomInvoice, $sentBy)->downloadName;

            Mail::to($guest['email'])->queue(new InvoiceMail($booking, $guest['name'], $sentBy, $fileName, $invoice === null));
        } catch (Throwable $exception) {
            report($exception);

            return 'The invoice could not be e-mailed: '.$exception->getMessage();
        }

        ActivityLog::create([
            'user_id' => $sentBy?->id,
            'property_id' => $booking->property_id,
            'booking_id' => $booking->id,
            'guest_id' => $booking->guests->first()?->id,
            'action' => ($invoice ? "Invoice # {$invoice->invoice_number}" : 'Proforma invoice')." e-mailed to {$guest['email']}.",
            'date' => now(),
        ]);

        return null;
    }
}
