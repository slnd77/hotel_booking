<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Services\PropertySettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id',
        'booking_id',
        'invoice_number',
        'folio_number',
        'order_number',
        'invoice_generation_date',
        'reservation_date',
        'outlet_name',
        'unit_type',
        'status',
        'paid_by',
        'booking_source',
        'guest_name',
        'agent_ota_name',
        'corporate_account',
        'corporate_id',
        'city_ledger_account_id',
        'due_date',
        'last_receipt_date',
        'invoice_amount',
        'taxable_amount',
        'total_tax',
        'amount_paid',
        'tax_exempted',
        'tax_exemption_type',
        'invoice_outstanding_amount',
        'generated_by_staff',
        'booking_reference',
        'days_in_outstanding',
        'sales_person',
        'promo_name',
        'document_attached',
        'party_name',
        'party_gstin',
        'checkin_date',
        'checkout_date',
        'total_pax',
        'total_children',
        'rooms_count',
        'discount',
        'room_nights',
        'meal_plans',
        'item_types',
        'tally_status',
        'tally_response',
        'has_attachment',
        'document',
        'send_bill',
        'soa_generated',
        'show_tax_breakup',
        'created_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'invoice_generation_date' => 'datetime',
            'reservation_date' => 'datetime',
            'last_receipt_date' => 'date',
            'checkin_date' => 'date',
            'checkout_date' => 'date',
            'due_date' => 'date',
            'invoice_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'tax_exempted' => 'decimal:2',
            'invoice_outstanding_amount' => 'decimal:2',
            'discount' => 'decimal:2',
            'total_pax' => 'integer',
            'total_children' => 'integer',
            'rooms_count' => 'integer',
            'room_nights' => 'integer',
            'has_attachment' => 'boolean',
            'send_bill' => 'boolean',
            'soa_generated' => 'boolean',
            'show_tax_breakup' => 'boolean',
        ];
    }

    /**
     * The company account the invoice is billed to.
     *
     * @return BelongsTo<CityLedgerAccount, $this>
     */
    public function cityLedgerAccount(): BelongsTo
    {
        return $this->belongsTo(CityLedgerAccount::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return HasMany<CreditNote, $this>
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class)->orderBy('id');
    }

    /**
     * Generate or update an invoice for a checked-out booking.
     */
    public static function generateForBooking(Booking $booking, ?string $staffName = null): self
    {
        $booking->loadMissing(['property', 'guests', 'rooms.roomCategory', 'payments', 'addons']);

        $property = $booking->property;
        $primaryGuest = $booking->guests->first();
        $guestName = $primaryGuest ? trim("{$primaryGuest->first_name} {$primaryGuest->last_name}") : '—';

        $checkIn = CarbonImmutable::parse($booking->check_in);
        $checkOut = CarbonImmutable::parse($booking->check_out);
        $nights = max(1, (int) $checkIn->diffInDays($checkOut));

        // The bill: room charges (less discount) and taxes, plus the add-ons with their own tax.
        $addonsAmount = round((float) $booking->addons->sum('amount'), 2);
        $addonsTax = round((float) $booking->addons->sum('tax_amount'), 2);
        $totalAmount = round((float) $booking->total + (float) $booking->addons_total, 2);
        $outstanding = (float) $booking->outstanding;
        $paidAmount = max(0.0, $totalAmount - $outstanding);
        $taxableAmount = max(0.0, (float) $booking->room_total - (float) $booking->discount) + $addonsAmount;
        $totalTax = (float) $booking->taxes + $addonsTax;

        // A tax invoice keeps its number and date once issued; a new one takes the next serial number.
        $existing = self::query()->where('booking_id', $booking->id)->first(['id', 'invoice_number', 'invoice_generation_date']);
        $invoiceNumber = $existing?->invoice_number ?? self::nextInvoiceNumber($property);
        $company = is_array($primaryGuest?->company) ? $primaryGuest->company : [];
        // Bill To Guest Company: made out to the company's City Ledger account, due after its terms.
        $account = $booking->city_ledger_account_id ? CityLedgerAccount::query()->find($booking->city_ledger_account_id) : null;
        $invoiceDate = $existing?->invoice_generation_date ?? now();
        $gstin = $account?->tax_number ?: ($company['gstin'] ?? null);

        $staff = $staffName ?: (auth()->user()?->name ?? 'Staff');

        $lastPayment = $booking->payments->sortByDesc('payment_transaction_date')->first();
        $lastReceiptDate = $lastPayment?->payment_transaction_date?->toDateString();

        $mealPlans = $booking->rooms->pluck('meal_plan')->filter()->unique()->implode(', ') ?: 'EP';
        $itemTypes = $booking->rooms->map(fn ($r) => $r->roomCategory?->name)->filter()->unique()->implode(', ') ?: 'Room';

        $totalPax = $booking->rooms->sum('adults') ?: 1;
        $totalChildren = $booking->rooms->sum('children') ?: 0;
        $roomsCount = $booking->rooms->count() ?: 1;

        $sourceMap = [
            'direct' => 'Direct',
            'corporate' => 'Corporate',
            'travel_agent' => 'Travel Agent',
            'ota' => 'OTA',
            'meta' => 'Meta',
            'booking_engine' => 'Booking Engine',
        ];
        $source = $sourceMap[$booking->source] ?? ucfirst($booking->source ?? 'Direct');

        $invoice = self::updateOrCreate(
            [
                'booking_id' => $booking->id,
            ],
            [
                'property_id' => $booking->property_id,
                'invoice_number' => $invoiceNumber,
                'folio_number' => $booking->booking_ref_id,
                'order_number' => null,
                'invoice_generation_date' => $invoiceDate,
                'reservation_date' => $booking->created_at ?? now(),
                'outlet_name' => 'Front Office',
                'unit_type' => 'Room',
                'status' => InvoiceStatus::BillGenerated,
                'paid_by' => $account !== null || $booking->room_bill_to === 'to_company' ? 'Company' : 'Guest',
                'booking_source' => $source,
                'guest_name' => $guestName,
                'party_name' => $account?->name ?? $guestName,
                'agent_ota_name' => null,
                'corporate_account' => $account?->name,
                'corporate_id' => $account !== null ? ($account->code ?: (string) $account->id) : null,
                'city_ledger_account_id' => $account?->id,
                'due_date' => $account !== null ? CarbonImmutable::parse($invoiceDate)->addDays($account->payment_terms_days)->toDateString() : null,
                'last_receipt_date' => $lastReceiptDate,
                'invoice_amount' => $totalAmount,
                'taxable_amount' => $taxableAmount,
                'total_tax' => $totalTax,
                'amount_paid' => $paidAmount,
                'tax_exempted' => 0.00,
                'tax_exemption_type' => null,
                'invoice_outstanding_amount' => $outstanding,
                'generated_by_staff' => $staff,
                'booking_reference' => (string) $booking->id,
                'days_in_outstanding' => $outstanding <= 0 ? 'No Outstanding' : '0',
                'sales_person' => null,
                'promo_name' => $booking->rate_plan,
                'document_attached' => null,
                // A guest billed to a GST-registered company: the invoice is B2B (GSTR-1 b2b).
                'party_gstin' => filled($gstin) ? strtoupper(trim((string) $gstin)) : null,
                'checkin_date' => $booking->check_in,
                'checkout_date' => $booking->check_out,
                'total_pax' => $totalPax,
                'total_children' => $totalChildren,
                'rooms_count' => $roomsCount,
                'discount' => (float) $booking->discount,
                'room_nights' => $nights,
                'meal_plans' => $mealPlans,
                'item_types' => $itemTypes,
                'tally_status' => null,
                'tally_response' => null,
                'has_attachment' => false,
                'document' => null,
                'send_bill' => false,
                'soa_generated' => false,
                'show_tax_breakup' => false,
                'created_by' => $staff,
            ]
        );

        // Log activity
        ActivityLog::create([
            'user_id' => auth()->id() ?? 1,
            'property_id' => $booking->property_id,
            'guest_id' => $primaryGuest?->id,
            'action' => "Bill # {$invoiceNumber} generated for amount {$totalAmount}.",
            'date' => now(),
        ]);

        return $invoice;
    }

    /**
     * The property's next invoice number (GST rule 46: a consecutive serial number of at most
     * 16 characters, unique in the financial year), from Invoice Settings → Invoice Tracker
     * Number: prefix, in-prefix, the serial padded to "Post Fix with Digits" (5 by default)
     * and post-fix. With "Auto reset" the serial starts again each financial year (1 April,
     * property time). Without a tracker the series is INV + the property's abbreviation.
     */
    public static function nextInvoiceNumber(Property $property): string
    {
        $tracker = (app(PropertySettings::class)->json($property, 'invoice_settings_trackers') ?? [])['invoice'] ?? [];
        $prefix = trim((string) ($tracker['prefix'] ?? '')).trim((string) ($tracker['in_prefix'] ?? ''));
        $prefix = $prefix === '' ? 'INV'.mb_substr((string) ($property->abbreviation ?: 'CON'), 0, 6) : $prefix;
        $postfix = trim((string) ($tracker['postfix'] ?? ''));
        $digits = max(1, min(10, (int) ($tracker['postfix_digits'] ?? 0) ?: 5));

        $series = self::query()->where('property_id', $property->id)->where('invoice_number', 'like', addcslashes($prefix, '%_\\').'%')->lockForUpdate();

        if ((bool) ($tracker['auto_reset'] ?? false)) {
            $local = $property->localTime();
            $yearStart = CarbonImmutable::create($local->month >= 4 ? $local->year : $local->year - 1, 4, 1, 0, 0, 0, $property->timezoneName())->utc();
            $series->where('invoice_generation_date', '>=', $yearStart);
        }

        $serial = $series->count();

        do {
            $serial++;
            $number = $prefix.str_pad((string) $serial, $digits, '0', STR_PAD_LEFT).$postfix;
        } while (self::query()->where('property_id', $property->id)->where('invoice_number', $number)->exists());

        return $number;
    }
}
