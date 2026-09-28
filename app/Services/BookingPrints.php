<?php

namespace App\Services;

use App\Enums\BookingPrintDocument;
use App\Enums\BookingStatus;
use App\Enums\OutletBillStatus;
use App\Enums\TransactionType;
use App\Http\Controllers\Concerns\CalculatesRoomTaxes;
use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\ChargeCode;
use App\Models\Currency;
use App\Models\Guest;
use App\Models\Invoice;
use App\Models\OutletBill;
use App\Models\OutletBillItem;
use App\Models\Payment;
use App\Models\Property;
use App\Models\SubBooking;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\PdfBuilder;

use function Spatie\LaravelPdf\Support\pdf;

/**
 * Builds what a booking's printouts show (GRC, reservation, bill summary, room invoice).
 * Titles, footers, watermarks and statutory IDs follow the property's Invoice Settings;
 * the GRC's fields follow the property's GRC settings.
 *
 * @phpstan-type RoomLine array{id: int, category_id: int, category: string, room_number: string|null, meal_plan: string, adults: int, children: int, infants: int, nights: int, check_in: CarbonImmutable, check_out: CarbonImmutable, rate: float, amount: float}
 */
class BookingPrints
{
    use CalculatesRoomTaxes;

    /** SAC code for accommodation services, printed beside the room on GST invoices. */
    private const ROOM_SAC_CODE = '996311';

    public function __construct(private BookingEngineService $bookingEngine) {}

    /**
     * The Blade view that lays out a document.
     *
     * @return view-string
     */
    public static function viewFor(BookingPrintDocument $document): string
    {
        return match ($document) {
            BookingPrintDocument::Grc => 'prints.booking.grc',
            BookingPrintDocument::RoomInvoice => 'prints.booking.room-invoice',
            default => 'prints.booking.reservation',
        };
    }

    /**
     * The document as an A4 PDF of the same page the print view shows (downloaded, or
     * attached to an e-mail).
     */
    public function pdf(Booking $booking, BookingPrintDocument $document, ?User $printedBy): PdfBuilder
    {
        return pdf()
            ->view(self::viewFor($document), [...$this->data($booking, $document, $printedBy), 'autoPrint' => false, 'pdfUrl' => null])
            ->format(Format::A4)
            ->margins(8, 8, 8, 8, 'mm')
            ->name(Str::slug($document->label()).'-'.$booking->booking_ref_id.'.pdf');
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Booking $booking, BookingPrintDocument $document, ?User $printedBy): array
    {
        $booking->loadMissing(['property.currency', 'property.country', 'property.state', 'property.city', 'property.corporateCountry', 'property.corporateState', 'property.corporateCity', 'rooms.roomCategory', 'rooms.room', 'guests', 'payments']);
        $property = $booking->property;
        $settings = $this->invoiceSettings($property);
        $components = $this->activeRoomTaxComponents($property);
        $invoice = Invoice::query()->where('booking_id', $booking->id)->first();
        $isProforma = $document === BookingPrintDocument::RoomInvoice && ! $invoice;
        $rooms = $this->rooms($booking);
        $grc = $this->grc($property);

        return [
            'document' => $document,
            'title' => $document === BookingPrintDocument::Grc ? $grc['print_header'] : $this->title($document, $settings, $isProforma),
            'watermark' => $this->watermark($document, $settings, $isProforma),
            'settings' => $settings,
            'currency' => Currency::forProperty($property)['symbol'],
            'property' => [
                'name' => $property->name,
                'legal_name' => $property->property_legal_name,
                'address' => $this->propertyAddress($property, $settings['enable_address']),
                'phone' => $property->property_contact_number,
                'state' => $property->state?->name,
                'statutory_ids' => collect($settings['statutory_ids'])->filter(fn (array $item): bool => filled($item['display_name'] ?? null) && filled($item['display_value'] ?? null))->values()->all(),
            ],
            'booking' => [
                'ref' => $booking->booking_ref_id,
                'id' => $booking->id,
                'status' => $booking->status->label(),
                'is_cancelled' => $booking->status === BookingStatus::Cancelled,
                'source' => $this->sourceLabel($booking->source),
                'check_in' => $booking->check_in,
                'check_out' => $booking->check_out,
                // The property's check-out time (Invoice Settings → "Property Check Out Time" prints it instead of the reported one).
                'check_out_time' => $booking->check_out_time,
                // Reported (actual) arrival and departure, in property time: the first room in, and
                // the last room out once every room has left.
                'checked_in_at' => ($firstIn = $booking->rooms->pluck('checked_in_at')->filter()->min()) ? $property->localTime($firstIn) : null,
                'checked_out_at' => $booking->rooms->isNotEmpty() && $booking->rooms->every(fn (SubBooking $room): bool => $room->checked_out_at !== null)
                    ? $property->localTime($booking->rooms->pluck('checked_out_at')->max())
                    : null,
                'nights' => BookingPricing::nights($booking->check_in->toDateString(), $booking->check_out->toDateString()),
                'room_total' => (float) $booking->room_total,
                'discount' => (float) $booking->discount,
                'taxes' => (float) $booking->taxes,
                'total' => (float) $booking->total,
                'outstanding' => (float) $booking->outstanding,
                'special_instructions' => $booking->special_instructions,
                'internal_notes' => $booking->internal_notes,
                // Printed documents show times in the property's timezone (stored in UTC).
                'created_at' => $booking->created_at ? $property->localTime($booking->created_at) : null,
                'room_summary' => $rooms->groupBy('category')->map(fn (Collection $group, string $category): string => "{$group->count()} -- {$category}")->implode(', '),
            ],
            'guest' => $this->guest($booking->guests->first()),
            'rooms' => $rooms->all(),
            'taxLines' => $this->taxLines($booking, $rooms, $components),
            'usesGst' => collect($components)->contains(fn (array $component): bool => in_array(strtoupper((string) ($component['component_choice'] ?? '')), ['CGST', 'SGST', 'IGST', 'UTGST'], true)),
            'sacCode' => self::ROOM_SAC_CODE,
            'payments' => $booking->payments->sortBy('id')->values()->map(fn (Payment $payment): array => [
                'mode' => $payment->payment_mode->value ?? 'Cash',
                'receipt_number' => $payment->receipt_number ?? 'RCPT/'.$payment->id,
                'date' => $property->localTime($payment->payment_transaction_date ?? $payment->payment_creation_date ?? $payment->created_at),
                'amount' => (float) ($payment->pivot->tagged_amount ?? $payment->tagged_amount ?? $payment->total_txn_amount),
            ])->all(),
            // Add-on services sold on the booking.
            'addons' => $booking->addons()->get()->map(fn (BookingAddon $line): array => [
                'name' => $line->name,
                'quantity' => $line->quantity,
                'pricing' => Addon::PRICING[$line->pricing] ?? $line->pricing,
                'multiplier' => $line->multiplier,
                'unit_price' => (float) $line->unit_price,
                'amount' => (float) $line->amount,
                'tax' => (float) $line->tax_amount,
                'total' => (float) $line->total,
            ])->all(),
            // Outlet (POS) bills charged to the booking's rooms; voided ones are left out.
            'outletBills' => OutletBill::query()
                ->where('booking_id', $booking->id)
                ->where('status', OutletBillStatus::Posted)
                ->with(['outlet:id,name', 'items', 'subBooking.inventoryUnit:id,name'])
                ->orderBy('business_date')
                ->orderBy('id')
                ->get()
                ->map(fn (OutletBill $bill): array => [
                    'date' => $bill->business_date,
                    'outlet' => $bill->outlet->name,
                    'bill_number' => $bill->bill_number,
                    'room' => $bill->subBooking?->inventoryUnit?->name,
                    'items' => $bill->items->map(fn (OutletBillItem $line): string => "{$line->quantity} x {$line->name}")->implode(', '),
                    'sub_total' => round((float) $bill->sub_total - (float) $bill->discount, 2),
                    'tax' => (float) $bill->tax_total,
                    'total' => (float) $bill->total,
                ])
                ->all(),
            'inclusions' => $this->inclusions($booking, $rooms),
            'termsAndConditions' => $this->richText($this->metaFieldValue($property, 'terms_and_conditions_while_booking')),
            'cancellationPolicy' => $this->bookingEngine->cancellationPolicyLines($property, CarbonImmutable::parse($booking->check_in)),
            'invoice' => $invoice ? [
                'number' => $invoice->invoice_number,
                'generated_at' => $property->localTime($invoice->invoice_generation_date),
                'generated_by' => $invoice->generated_by_staff,
            ] : null,
            // Invoice Settings → Invoice Billing Methods: one invoice for the booking, or one per room (its own folio).
            'invoiceSections' => $document === BookingPrintDocument::RoomInvoice && $settings['invoice_billing_method'] === 'individual_invoices'
                ? $this->roomInvoiceSections($booking, $rooms, $components)
                : [],
            'grc' => $grc,
            'guestSignature' => $this->imageDataUri($booking->webCheckin?->signature_path),
            'printedBy' => $printedBy?->name,
            'printedAt' => $property->localTime(),
        ];
    }

    /**
     * Invoice Settings → general, with the defaults the settings page starts from.
     *
     * @return array{statutory_ids: list<array{display_name?: string, display_value?: string}>, headers: array<string, array{value: string, enabled: bool}>, toggles: array<string, bool>, watermarks: array<string, array{value: string, enabled: bool}>, enable_address: string, invoice_template: string, guest_liability_enabled: bool, guest_liability_text: string, parent_company: string, co_registration_label: string, invoice_billing_method: string, invoice_type: string, checkin_checkout_preference: string, invoice_series: array<string, string>, invoice_logo: string|null, digital_signature: string|null}
     */
    public function invoiceSettings(Property $property): array
    {
        $saved = $this->metaFieldData($property, 'invoice_settings_general') ?? [];

        $headers = ['reservation' => 'Reservation', 'bill_summary' => 'Bill Summary', 'room_proforma_invoice' => 'Room Proforma Invoice', 'room_invoice' => 'Room Invoice'];
        $toggles = [
            'checkin_checkout_time' => true, 'invoice_generation_date' => true, 'place_of_supply' => true,
            'tnc_guest_agent_company' => false, 'cancellation_policy_guest_agent_company' => false,
            'tnc_reservation_guest' => false, 'cancellation_policy_reservation_guest' => false,
            'tnc_reservation_internal' => false, 'cancellation_policy_reservation_internal' => false,
            'show_footer_signature' => false,
        ];
        $watermarks = ['watermark_room_proforma' => 'PROFORMA INVOICE', 'watermark_bill_summary' => ''];

        $savedHeaders = collect($this->metaFieldArray($property, 'invoice_settings_general', 'print_headers'))->keyBy('key');
        $savedToggles = collect([
            ...$this->metaFieldArray($property, 'invoice_settings_general', 'print_footer_toggles_a'),
            ...$this->metaFieldArray($property, 'invoice_settings_general', 'print_footer_toggles_b'),
        ])->keyBy('key');
        $savedWatermarks = collect($this->metaFieldArray($property, 'invoice_settings_general', 'print_footer_watermarks'))->keyBy('key');

        return [
            'statutory_ids' => array_values($saved['statutory_ids'] ?? []),
            'headers' => collect($headers)->map(fn (string $default, string $key): array => [
                'value' => (string) ($savedHeaders[$key]['value'] ?? $default),
                'enabled' => (bool) ($savedHeaders[$key]['enabled'] ?? true),
            ])->all(),
            'toggles' => collect($toggles)->map(fn (bool $default, string $key): bool => (bool) ($savedToggles[$key]['enabled'] ?? $default))->all(),
            'watermarks' => collect($watermarks)->map(fn (string $default, string $key): array => [
                'value' => (string) ($savedWatermarks[$key]['value'] ?? $default),
                'enabled' => (bool) ($savedWatermarks[$key]['enabled'] ?? $key === 'watermark_room_proforma'),
            ])->all(),
            'enable_address' => (string) ($saved['enable_address'] ?? 'permanent'),
            'invoice_template' => (string) ($saved['invoice_template'] ?? 'summarized_view'),
            'guest_liability_enabled' => (bool) ($saved['guest_liability_enabled'] ?? false),
            'guest_liability_text' => (string) ($saved['guest_liability_text'] ?? ''),
            'parent_company' => trim((string) ($saved['parent_company'] ?? '')),
            // Display name of a company's registration number (the guest company's GSTIN prints under it).
            'co_registration_label' => trim((string) ($saved['co_registration_label'] ?? '')) ?: 'GSTIN',
            'invoice_billing_method' => (string) ($saved['invoice_billing_method'] ?? 'consolidate_at_billing_preference'),
            'invoice_type' => (string) ($saved['billing_method_invoice_type'] ?? 'individual_invoices'),
            // "property_checkout_time": the invoice shows the property's check-out time, not when the desk marked it.
            'checkin_checkout_preference' => (string) ($saved['checkin_checkout_preference'] ?? 'user_marking_checkout'),
            // Invoice / OR Template Setting: the invoice series and its permit (e.g. BIR in the Philippines).
            'invoice_series' => array_filter([
                'range' => trim(implode(' – ', array_filter([trim((string) ($saved['invoice_start_range'] ?? '')), trim((string) ($saved['invoice_end_range'] ?? ''))]))),
                'permit_label' => trim((string) ($saved['invoice_bir_permit_display_name'] ?? '')) ?: 'Permit to Use (PTU) No.',
                'permit_no' => trim((string) ($saved['invoice_bir_permit_no'] ?? '')),
                'permit_date' => trim((string) ($saved['invoice_bir_permit_date'] ?? '')),
                'owned_by' => trim((string) ($saved['invoice_owned_by'] ?? '')),
            ]),
            // Embedded as data URIs so they also render in PDFs and e-mailed invoices.
            'invoice_logo' => $this->imageDataUri($saved['invoice_logo_path'] ?? null),
            'digital_signature' => $this->imageDataUri($saved['digital_signature_path'] ?? null),
        ];
    }

    private function imageDataUri(?string $path): ?string
    {
        if (! filled($path) || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return 'data:'.(Storage::disk('local')->mimeType($path) ?: 'image/png').';base64,'.base64_encode(Storage::disk('local')->get($path));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function title(BookingPrintDocument $document, array $settings, bool $isProforma): ?string
    {
        $key = match ($document) {
            BookingPrintDocument::Grc, BookingPrintDocument::ReservationInternal, BookingPrintDocument::ReservationGuest => 'reservation',
            BookingPrintDocument::BillSummary => 'bill_summary',
            BookingPrintDocument::RoomInvoice => $isProforma ? 'room_proforma_invoice' : 'room_invoice',
        };

        $header = $settings['headers'][$key];

        return $header['enabled'] && filled($header['value']) ? $header['value'] : null;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function watermark(BookingPrintDocument $document, array $settings, bool $isProforma): ?string
    {
        $key = match (true) {
            $document === BookingPrintDocument::BillSummary => 'watermark_bill_summary',
            $isProforma => 'watermark_room_proforma',
            default => null,
        };

        if ($key === null) {
            return null;
        }

        $watermark = $settings['watermarks'][$key];

        return $watermark['enabled'] && filled($watermark['value']) ? $watermark['value'] : null;
    }

    /**
     * Each room with its own nights (a split booking's rooms keep their own dates).
     *
     * @return Collection<int, RoomLine>
     */
    private function rooms(Booking $booking): Collection
    {
        return $booking->rooms->sortBy('id')->values()->map(fn (SubBooking $room): array => $this->roomLine($room));
    }

    /**
     * One room's line: its category, guests, own nights and rate.
     *
     * @return RoomLine
     */
    private function roomLine(SubBooking $room): array
    {
        $nights = BookingPricing::nights($room->check_in_date->toDateString(), $room->check_out_date->toDateString());

        return [
            'id' => $room->id,
            'category_id' => $room->room_category_id,
            'category' => $room->roomCategory->name ?? 'Room',
            'room_number' => $room->room?->name,
            'meal_plan' => $room->meal_plan ?: 'EP',
            'adults' => (int) $room->adults,
            'children' => (int) $room->children,
            'infants' => (int) $room->infants,
            'nights' => $nights,
            'check_in' => $room->check_in_date,
            'check_out' => $room->check_out_date,
            'rate' => (float) $room->rate_per_night,
            'amount' => round((float) $room->rate_per_night * $nights, 2),
        ];
    }

    /**
     * Tax per component and room category (e.g. "CGST - Deluxe"), when the property's tax
     * rule reproduces the booking's saved taxes; otherwise one line with the saved taxes.
     *
     * @param  Collection<int, RoomLine>  $rooms
     * @param  array<int, array<string, mixed>>  $components
     * @return list<array{label: string, taxable: float, amount: float}>
     */
    private function taxLines(Booking $booking, Collection $rooms, array $components): array
    {
        $savedTaxes = round((float) $booking->taxes, 2);

        if ($savedTaxes <= 0) {
            return [];
        }

        $roomTotal = (float) $rooms->sum('amount');
        $lines = [];

        foreach ($rooms as $room) {
            $discountShare = $roomTotal > 0 ? (float) $booking->discount * ($room['amount'] / $roomTotal) : 0.0;
            $netRate = max(0, $room['amount'] - $discountShare) / $room['nights'];

            foreach ($components as $component) {
                $label = ($component['component_choice'] ?? 'Tax').' - '.$room['category'];
                $amount = $this->taxForAmount([$component], $room['rate'], $netRate) * $room['nights'];

                $lines[$label] ??= ['label' => $label, 'taxable' => 0.0, 'amount' => 0.0];
                $lines[$label]['taxable'] += $room['amount'] - $discountShare;
                $lines[$label]['amount'] += $amount;
            }
        }

        $lines = collect($lines)
            ->map(fn (array $line): array => ['label' => $line['label'], 'taxable' => round($line['taxable'], 2), 'amount' => round($line['amount'], 2)])
            ->filter(fn (array $line): bool => $line['amount'] > 0)
            ->values();

        if ($lines->isEmpty() || abs($lines->sum('amount') - $savedTaxes) >= 0.05) {
            return [['label' => 'Taxes', 'taxable' => round((float) $booking->room_total - (float) $booking->discount, 2), 'amount' => $savedTaxes]];
        }

        return array_values($lines->all());
    }

    /**
     * The property's active room tax components, by name (e.g. ["CGST", "SGST"]).
     *
     * @return list<string>
     */
    public function taxComponentNames(Property $property): array
    {
        return array_values(collect($this->activeRoomTaxComponents($property))
            ->map(fn (array $component): string => (string) ($component['component_choice'] ?? 'Tax'))
            ->unique()
            ->all());
    }

    /**
     * The booking's saved taxes split by component, the way its invoice lines split them.
     * When the tax rule no longer reproduces the saved taxes, they're split evenly.
     *
     * @param  list<string>  $componentNames
     * @return array<string, float>
     */
    public function taxByComponent(Booking $booking, array $componentNames): array
    {
        $amounts = array_fill_keys($componentNames, 0.0);

        if ($componentNames === [] || (float) $booking->taxes <= 0) {
            return $amounts;
        }

        $lines = $this->taxLines($booking, $this->rooms($booking), $this->activeRoomTaxComponents($booking->property));

        if (count($lines) === 1 && $lines[0]['label'] === 'Taxes') {
            return array_fill_keys($componentNames, round((float) $booking->taxes / count($componentNames), 2));
        }

        foreach ($lines as $line) {
            $name = Str::before($line['label'], ' - ');

            if (array_key_exists($name, $amounts)) {
                $amounts[$name] = round($amounts[$name] + $line['amount'], 2);
            }
        }

        return $amounts;
    }

    /**
     * The guest's name and contact details as documents (and invoice e-mails) use them.
     *
     * @return array{name: string, phone: ?string, email: ?string, address: ?string, company: array{name: ?string, registration_no: ?string, address: ?string}|null}|null
     */
    public function guest(?Guest $guest): ?array
    {
        if (! $guest) {
            return null;
        }

        $email = collect(is_array($guest->emails) ? $guest->emails : [])
            ->map(fn ($entry) => is_array($entry) ? ($entry['email'] ?? null) : $entry)
            ->first(fn ($entry): bool => filled($entry));

        $address = is_array($guest->address) ? ($guest->address['address'] ?? null) : $guest->address;
        $company = is_array($guest->company) ? $guest->company : [];

        return [
            'name' => trim(($guest->title ? $guest->title.' ' : '').$guest->first_name.' '.$guest->last_name),
            'phone' => filled($guest->phone) ? trim(($guest->phone_country_code ?? '').' '.$guest->phone) : null,
            'email' => $email,
            'address' => filled($address) ? (string) $address : null,
            // The guest's company (Guest → Company): printed as the billed company with its registration number.
            'company' => filled($company['name'] ?? null) || filled($company['gstin'] ?? null) ? [
                'name' => filled($company['name'] ?? null) ? (string) $company['name'] : null,
                'registration_no' => filled($company['gstin'] ?? null) ? (string) $company['gstin'] : null,
                'address' => filled($company['address'] ?? null) ? (string) $company['address'] : null,
            ] : null,
        ];
    }

    /**
     * Invoice Billing Methods → "Individual Invoices": one invoice per room, read from that
     * room's own folio — its charges, taxes, adjustments and the receipts credited to it — so
     * each matches the room's folio on the booking page.
     *
     * @param  Collection<int, RoomLine>  $rooms
     * @param  array<int, array<string, mixed>>  $components
     * @return list<array<string, mixed>>
     */
    private function roomInvoiceSections(Booking $booking, Collection $rooms, array $components): array
    {
        $booking->loadMissing(['rooms.folio.subFolios.transactions.chargeCode', 'rooms.folio.subFolios.transactions.payment']);
        $signed = fn (Transaction $entry): float => $entry->transaction_type === TransactionType::Debit ? (float) $entry->amount : -(float) $entry->amount;
        $sections = [];

        foreach ($booking->rooms->values() as $index => $subBooking) {
            $room = $rooms->firstWhere('id', $subBooking->id);
            $entries = $subBooking->folio?->subFolios->flatMap->transactions ?? collect();

            if (! $room) {
                continue;
            }

            $byCode = fn (string ...$codes): float => round($entries->filter(fn (Transaction $entry): bool => in_array($entry->chargeCode?->code, $codes, true))->sum($signed), 2);
            $roomCharge = $byCode(ChargeCode::ROOM);
            $discount = -$byCode(ChargeCode::DISCOUNT);
            $taxes = $byCode(ChargeCode::TAX);
            $receipts = $entries->filter(fn (Transaction $entry): bool => $entry->payment_id !== null);
            // Everything else on the folio: booking amendments, balances transferred in or out, extras.
            $adjustments = round($entries->reject(fn (Transaction $entry): bool => $entry->payment_id !== null || in_array($entry->chargeCode?->code, [ChargeCode::ROOM, ChargeCode::DISCOUNT, ChargeCode::TAX], true))->sum($signed), 2);

            $sections[] = [
                'label' => 'Room '.($index + 1).' | '.$room['category'],
                'rooms' => [$room],
                'room_total' => $roomCharge,
                'discount' => $discount,
                'taxes' => $taxes,
                'taxLines' => $this->roomTaxLines($room, $discount, $taxes, $components),
                'adjustments' => $adjustments,
                'total' => round($roomCharge - $discount + $taxes + $adjustments, 2),
                'payments' => $receipts->groupBy('payment_id')->map(fn (Collection $paymentEntries): array => [
                    'mode' => $paymentEntries->first()->payment?->payment_mode->value ?? 'Payment',
                    'receipt_number' => $paymentEntries->first()->payment->receipt_number ?? '—',
                    'date' => $booking->property->localTime($paymentEntries->first()->payment->payment_transaction_date ?? $paymentEntries->first()->posted_at),
                    'amount' => round(-$paymentEntries->sum($signed), 2),
                ])->filter(fn (array $payment): bool => abs($payment['amount']) >= 0.01)->values()->all(),
                'net_payable' => round($entries->sum($signed), 2),
            ];
        }

        return $sections;
    }

    /**
     * One room's tax split per component, when the tax rule reproduces the room's taxes;
     * otherwise one "Taxes" line.
     *
     * @param  RoomLine  $room
     * @param  array<int, array<string, mixed>>  $components
     * @return list<array{label: string, taxable: float, amount: float}>
     */
    private function roomTaxLines(array $room, float $discount, float $taxes, array $components): array
    {
        if ($taxes <= 0) {
            return [];
        }

        $taxable = round($room['amount'] - $discount, 2);
        $netRate = max(0, $taxable) / max(1, $room['nights']);
        $lines = collect($components)
            ->map(fn (array $component): array => [
                'label' => ($component['component_choice'] ?? 'Tax').' - '.$room['category'],
                'taxable' => $taxable,
                'amount' => round($this->taxForAmount([$component], $room['rate'], $netRate) * $room['nights'], 2),
            ])
            ->filter(fn (array $line): bool => $line['amount'] > 0)
            ->values();

        return $lines->isNotEmpty() && abs($lines->sum('amount') - $taxes) < 0.05
            ? array_values($lines->all())
            : [['label' => 'Taxes', 'taxable' => $taxable, 'amount' => $taxes]];
    }

    public function propertyAddress(Property $property, string $which): string
    {
        $useCorporate = $which === 'corporate' && ! $property->corporate_address_permanent_address_same && filled($property->corporate_address_line_1);

        $parts = $useCorporate
            ? [$property->corporate_address_line_1, $property->corporate_address_line_2, $property->corporateCity?->name, $property->corporateState?->name, $property->corporateCountry?->name, $property->corporate_postal_code]
            : [$property->address_line_1, $property->address_line_2, $property->city?->name, $property->state?->name, $property->country?->name, $property->postal_code];

        return collect($parts)->filter(fn ($part): bool => filled($part))->implode(', ');
    }

    /**
     * The booking's inclusions per room category, else the category's own inclusion details.
     *
     * @param  Collection<int, RoomLine>  $rooms
     * @return list<array{category: string, html: string}>
     */
    private function inclusions(Booking $booking, Collection $rooms): array
    {
        $saved = (array) ($booking->inclusions ?? []);

        return array_values($rooms->unique('category_id')->map(function (array $room) use ($saved, $booking): array {
            $html = $saved[$room['category_id']] ?? $booking->rooms->firstWhere('room_category_id', $room['category_id'])?->roomCategory?->inclusion_details;

            return ['category' => $room['category'], 'html' => $this->richText($html)];
        })->filter(fn (array $inclusion): bool => $inclusion['html'] !== '')->all());
    }

    /**
     * GRC settings (Property → GRC): which fields the card shows, and its header.
     *
     * @return array{print_header: string, fields: array<string, bool>, terms: string, house_rules: string}
     */
    private function grc(Property $property): array
    {
        $saved = $this->metaFieldData($property, 'GRC_settings') ?? [];

        return [
            'print_header' => filled($saved['print_header'] ?? null) ? $saved['print_header'] : 'Guest Reservation Card',
            'fields' => collect($this->metaFieldArray($property, 'GRC_settings', 'fields'))->mapWithKeys(fn (array $field): array => [$field['name'] => (bool) $field['enabled']])->all(),
            'terms' => $this->richText($this->metaFieldValue($property, 'terms_and_conditions_while_check_in')),
            'house_rules' => $this->richText($this->metaFieldValue($property, 'house_rules')),
        ];
    }

    private function metaFieldValue(Property $property, string $key): ?string
    {
        return app(PropertySettings::class)->raw($property, $key);
    }

    /**
     * Rich text from the settings editors, reduced to basic formatting tags without
     * attributes so it can be printed as HTML.
     */
    private function richText(?string $html): string
    {
        if (! filled($html)) {
            return '';
        }

        $allowed = '<p><br><b><strong><i><em><u><s><ul><ol><li><h1><h2><h3><h4><span><div><blockquote>';
        $clean = preg_replace('/<([a-z0-9]+)\b[^>]*>/i', '<$1>', strip_tags($html, $allowed)) ?? '';

        return trim(strip_tags($clean)) === '' ? '' : $clean;
    }

    private function sourceLabel(?string $source): string
    {
        return [
            'direct' => 'Direct',
            'corporate' => 'Corporate',
            'travel_agent' => 'Agent',
            'ota' => 'OTA',
            'meta' => 'Meta',
            'booking_engine' => 'Booking Engine',
        ][$source] ?? ucfirst(str_replace('_', ' ', (string) $source));
    }
}
