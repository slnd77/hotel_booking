<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Services\GeneralLedger;
use App\Services\PaymentModeSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Payment extends Model
{
    protected $fillable = [
        'property_id',
        'booking_id',
        'type',
        'payment_creation_date',
        'payment_transaction_date',
        'voucher_number',
        'receipt_number',
        'status',
        'payment_ref_id',
        'pg_txn_id',
        'ota_booking_ref_id',
        'neft_ref_id',
        'imps_ref_id',
        'rtgs_ref_id',
        'guest_name',
        'payment_mode',
        'card_number',
        'total_txn_amount',
        'currency_code',
        'currency_amount',
        'exchange_rate',
        'tagged_amount',
        'untagged_amount',
        'pg_commission_amount',
        'collection_source',
        'card_name',
        'bank',
        'booking_source',
        'delete_status',
        'cancellation_reason',
        'collected_by_staff',
        'cashier_shift_id',
        'notes',
        'pg_pay_fields_edited',
        'coupon',
        'payee_name',
        'document',
        'reconciled_at',
        'reconciled_by',
    ];

    /**
     * Who marked it reconciled (Night audit → Payment Reconciliation).
     *
     * @return BelongsTo<User, $this>
     */
    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'payment_mode' => PaymentMode::class,
            'payment_creation_date' => 'datetime',
            'payment_transaction_date' => 'datetime',
            'reconciled_at' => 'datetime',
            'total_txn_amount' => 'decimal:2',
            'currency_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
            'tagged_amount' => 'decimal:2',
            'untagged_amount' => 'decimal:2',
            'pg_commission_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Money taken by a cashier with a shift open at the property belongs to that shift.
        static::creating(function (Payment $payment): void {
            if ($payment->cashier_shift_id === null && $payment->property_id && ($userId = auth()->id())) {
                $payment->cashier_shift_id = CashierShift::query()->open()
                    ->where('property_id', $payment->property_id)
                    ->where('user_id', $userId)
                    ->value('id');
            }
        });

        // Money in but not (fully) on a folio yet sits in Unallocated Receipts.
        static::saved(function (Payment $payment): void {
            DB::afterCommit(fn () => app(GeneralLedger::class)->trueUpReceipt($payment->fresh() ?? $payment));
        });

        static::created(function (Payment $payment): void {
            // What the gateway kept: an expense, out of the gateway's clearing account.
            if ((float) $payment->pg_commission_amount > 0 && $payment->property_id) {
                $generalLedger = app(GeneralLedger::class);
                $generalLedger->post($payment->property_id, [
                    [$generalLedger->account($payment->property_id, 'commission_expense'), (float) $payment->pg_commission_amount, 0.0],
                    [$generalLedger->paymentModeAccount($payment->property_id, $payment->payment_mode), 0.0, (float) $payment->pg_commission_amount],
                ], "Gateway commission on receipt {$payment->receipt_number}", $payment);
            }

            if ($payment->booking_id && (float) $payment->tagged_amount > 0) {
                if (! request()->has('allocations')) {
                    BookingPaymentMapping::firstOrCreate(
                        [
                            'booking_id' => $payment->booking_id,
                            'payment_id' => $payment->id,
                        ],
                        [
                            'tagged_amount' => $payment->tagged_amount,
                        ]
                    );
                }
            }
        });
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * The cashier shift it was taken in.
     *
     * @return BelongsTo<CashierShift, $this>
     */
    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class);
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsToMany<Booking, $this>
     */
    public function bookings(): BelongsToMany
    {
        return $this->belongsToMany(Booking::class, 'bookings_payments_mappings')
            ->withPivot('tagged_amount')
            ->withTimestamps();
    }

    /**
     * @return HasMany<BookingPaymentMapping, $this>
     */
    public function bookingPaymentMappings(): HasMany
    {
        return $this->hasMany(BookingPaymentMapping::class);
    }

    /**
     * Ledger credits this payment was distributed into (one per billing window it settles).
     *
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Next receipt number for a property (same scheme as the Payments page).
     */
    public static function nextReceiptNumber(int $propertyId): string
    {
        // The year is the property's local year (UTC is still in the old year for hours after local New Year).
        $localNow = Property::query()->find($propertyId)?->localTime() ?? now();

        return 'RCPT/'.$localNow->format('Y').'-'.substr((string) ((int) $localNow->format('y') + 1), -2).'/'
            .str_pad((string) (self::query()->where('property_id', $propertyId)->count() + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Log or update a payment record for a booking.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function logForBooking(Booking $booking, array $overrides = []): self
    {
        // Guests are attached after the booking is created (which already logged a payment), so re-read them.
        $booking->load('guests')->loadMissing('property');
        $primaryGuest = $booking->guests->first();
        $guestName = $primaryGuest ? trim("{$primaryGuest->first_name} {$primaryGuest->last_name}") : null;

        $total = (float) ($booking->total ?? 0.0);
        $payNow = array_key_exists('pay_now', $overrides)
            ? (float) $overrides['pay_now']
            : ($booking->pay_at_hotel ? 0.0 : max(0.0, $total - (float) ($booking->outstanding ?? 0.0)));
        unset($overrides['pay_now']);

        $outstanding = array_key_exists('outstanding', $overrides)
            ? (float) $overrides['outstanding']
            : (float) ($booking->outstanding ?? max(0.0, $total - $payNow));
        unset($overrides['outstanding']);

        $amountPaid = $payNow > 0
            ? $payNow
            : ($booking->pay_at_hotel ? 0.0 : max(0.0, $total - $outstanding));

        $existingPaidPayment = self::query()
            ->where('booking_id', $booking->id)
            ->where('type', 'receipt')
            ->where('total_txn_amount', '>', 0)
            ->first();

        if ($existingPaidPayment && $amountPaid <= 0 && empty($overrides)) {
            return $existingPaidPayment;
        }

        $created = $booking->created_at ?? now();
        $receiptYear = $created->format('Y');
        $receiptNextYear = substr((string) ($created->format('y') + 1), -2);
        $receiptNumber = 'RCPT/'.$receiptYear.'-'.$receiptNextYear.'/'.str_pad((string) $booking->id, 5, '0', STR_PAD_LEFT);

        // A booking just created without a status has none in memory yet (the column's default applies in the database).
        $bookingStatus = $booking->getAttribute('status') instanceof BookingStatus ? $booking->status->value : '';

        $status = match (strtolower($bookingStatus)) {
            'cancelled' => PaymentStatus::Cancelled,
            'confirmed', 'checked_in', 'checked_out' => ($amountPaid > 0 && $outstanding <= 0) ? PaymentStatus::Received : ($amountPaid > 0 ? PaymentStatus::Received : PaymentStatus::Pending),
            default => PaymentStatus::Pending,
        };

        $paymentMode = match ($booking->source) {
            'booking_engine' => PaymentMode::Online,
            'ota' => PaymentMode::OTA,
            default => self::frontDeskMode($booking),
        };

        $staff = auth()->user()->name ?? 'System';

        $data = array_merge([
            'property_id' => $booking->property_id,
            'voucher_number' => $booking->booking_ref_id,
            'receipt_number' => $receiptNumber,
            'payment_creation_date' => $created,
            'payment_transaction_date' => $created,
            'status' => $status,
            'guest_name' => $guestName,
            'payment_mode' => $paymentMode,
            'total_txn_amount' => $amountPaid,
            'tagged_amount' => $amountPaid,
            'untagged_amount' => 0.00,
            'booking_source' => $booking->source ?? 'Direct',
            'collected_by_staff' => $staff,
        ], $overrides);

        $payment = self::updateOrCreate(
            [
                'booking_id' => $booking->id,
                'type' => 'receipt',
            ],
            $data
        );

        if ($amountPaid > 0) {
            BookingPaymentMapping::firstOrCreate(
                [
                    'booking_id' => $booking->id,
                    'payment_id' => $payment->id,
                ],
                [
                    'tagged_amount' => $amountPaid,
                ]
            );
        }

        return $payment;
    }

    /**
     * The mode on a front-desk booking's own receipt: the property's first mode allowed for
     * payments under Payment Settings (Cash unless changed), never a system mode like E-Collect.
     */
    private static function frontDeskMode(Booking $booking): PaymentMode
    {
        $modes = $booking->property ? app(PaymentModeSettings::class)->paymentModes($booking->property) : [];

        return PaymentMode::tryFrom($modes[0] ?? '') ?? PaymentMode::Cash;
    }
}
