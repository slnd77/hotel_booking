<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\TransactionType;
use App\Models\ActivityLog;
use App\Models\ChargeCode;
use App\Models\CreditNote;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\PdfBuilder;

use function Spatie\LaravelPdf\Support\pdf;

/**
 * Credit notes against invoices. A note reduces the invoice by an amount (tax included),
 * split into taxable value and tax in the invoice's own proportion, and credits the
 * booking's master folio by it: the guest owes less, or is due a refund if already paid.
 * Numbers follow the property's Credit Note tracker (Invoice settings).
 */
class CreditNotes
{
    public function __construct(
        private Ledger $ledger,
        private BookingLedgerSync $ledgerSync,
        private PropertySettings $settings,
    ) {}

    /**
     * What can still be credited on the invoice: its amount less the notes already issued.
     */
    public function creditable(Invoice $invoice): float
    {
        return max(0.0, round((float) $invoice->invoice_amount - (float) $invoice->creditNotes()->sum('total'), 2));
    }

    /**
     * @throws ValidationException when the amount is more than the invoice has left to credit
     */
    public function issue(Invoice $invoice, float $total, string $reason, User $user): CreditNote
    {
        $note = DB::transaction(function () use ($invoice, $total, $reason, $user): CreditNote {
            $invoice = Invoice::query()->lockForUpdate()->with(['property.currency', 'booking'])->findOrFail($invoice->id);
            $total = round($total, 2);
            $creditable = $this->creditable($invoice);

            if ($total <= 0 || $total > $creditable) {
                throw ValidationException::withMessages(['total' => 'At most '.number_format($creditable, 2).' can be credited on this invoice.']);
            }

            $taxShare = (float) $invoice->invoice_amount > 0 ? (float) $invoice->total_tax / (float) $invoice->invoice_amount : 0.0;
            $tax = round($total * $taxShare, 2);
            $number = $this->nextNumber($invoice->property);
            $booking = $invoice->booking;
            $transaction = null;

            if ($booking !== null && ! in_array($booking->status, BookingStatus::enquiryStatuses(), true)) {
                $transaction = $this->ledger->postEntry(
                    $this->ledger->defaultWindowFor($booking, $invoice->property->currency?->code),
                    TransactionType::Credit,
                    $total,
                    ChargeCode::system(ChargeCode::CREDIT_NOTE),
                    "Credit note {$number}",
                );
            }

            $note = CreditNote::create([
                'property_id' => $invoice->property_id,
                'invoice_id' => $invoice->id,
                'booking_id' => $booking?->id,
                'transaction_id' => $transaction?->id,
                'credit_note_number' => $number,
                'issue_date' => $invoice->property->today(),
                'reason' => $reason,
                'taxable_amount' => round($total - $tax, 2),
                'tax_amount' => $tax,
                'total' => $total,
                'created_by' => $user->id,
            ]);

            // The folio credit went to Sales Returns in full; its tax comes back out of Tax Payable (or its GST accounts).
            if ($transaction !== null && $tax > 0) {
                $generalLedger = app(GeneralLedger::class);
                $taxLines = array_map(fn (array $share): array => [$share[0], $share[1], 0.0], $generalLedger->taxSplit($invoice->property_id, $tax, $booking));
                $generalLedger->post($invoice->property_id, [
                    ...$taxLines,
                    [$generalLedger->account($invoice->property_id, 'sales_returns'), 0.0, $tax],
                ], "Tax on credit note {$number}", $note);
            }

            return $note;
        });

        $invoice->refresh();

        // The booking now owes less (or is owed money back); the invoice shows what is left.
        if ($invoice->booking !== null) {
            $this->ledgerSync->sync($invoice->booking);
            $outstanding = (float) $invoice->booking->refresh()->outstanding;
        } else {
            $outstanding = max(0.0, round((float) $invoice->invoice_outstanding_amount - (float) $note->total, 2));
        }

        $invoice->update(['invoice_outstanding_amount' => $outstanding]);

        ActivityLog::create([
            'user_id' => $user->id,
            'property_id' => $invoice->property_id,
            'booking_id' => $invoice->booking_id,
            'guest_id' => $invoice->booking?->guest_id,
            'action' => "Credit note # {$note->credit_note_number} issued on bill # {$invoice->invoice_number} for amount {$note->total}: {$note->reason}",
            'date' => now(),
        ]);

        return $note;
    }

    /**
     * What the credit note's print shows: the property's header from Invoice settings, the
     * invoice it corrects, the party, and the amounts.
     *
     * @return array<string, mixed>
     */
    public function printData(CreditNote $note): array
    {
        $note->loadMissing(['invoice', 'creator:id,name', 'property.state', 'property.city', 'property.country', 'property.corporateCity', 'property.corporateState', 'property.corporateCountry']);
        $prints = app(BookingPrints::class);
        $property = $note->property;
        $settings = $prints->invoiceSettings($property);

        return [
            'title' => 'Credit Note',
            'watermark' => null,
            'currency' => Currency::forProperty($property)['symbol'],
            'property' => [
                'name' => $property->name,
                'legal_name' => $property->property_legal_name,
                'address' => $prints->propertyAddress($property, $settings['enable_address']),
                'phone' => $property->property_contact_number,
                'state' => $property->state?->name,
                'statutory_ids' => collect($settings['statutory_ids'])->filter(fn (array $item): bool => filled($item['display_name'] ?? null) && filled($item['display_value'] ?? null))->values()->all(),
            ],
            'booking' => ['ref' => $note->credit_note_number],
            'note' => $note,
            'invoice' => $note->invoice,
        ];
    }

    public function pdf(CreditNote $note): PdfBuilder
    {
        return pdf()
            ->view('prints.credit-note', [...$this->printData($note), 'autoPrint' => false, 'pdfUrl' => null])
            ->format(Format::A4)
            ->margins(8, 8, 8, 8, 'mm')
            ->name('credit-note-'.Str::slug($note->credit_note_number).'.pdf');
    }

    /**
     * The property's next number: tracker prefix, in-prefix, a four-digit running number and
     * postfix (CN-0001 when no tracker is set). Called inside the issuing transaction.
     */
    private function nextNumber(Property $property): string
    {
        $tracker = ($this->settings->json($property, 'invoice_settings_trackers') ?? [])['credit_note'] ?? [];
        $prefix = trim((string) ($tracker['prefix'] ?? '')).trim((string) ($tracker['in_prefix'] ?? ''));
        $prefix = $prefix === '' ? 'CN-' : $prefix;
        $postfix = trim((string) ($tracker['postfix'] ?? ''));

        $issued = CreditNote::query()->where('property_id', $property->id)->lockForUpdate()->count();

        do {
            $issued++;
            $number = $prefix.str_pad((string) $issued, 4, '0', STR_PAD_LEFT).$postfix;
        } while (CreditNote::query()->where('property_id', $property->id)->where('credit_note_number', $number)->exists());

        return $number;
    }
}
