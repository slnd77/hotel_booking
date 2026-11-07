<?php

namespace App\Models;

use App\Enums\TransactionType;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ledger entry on a billing window. Amounts are always positive; the type
 * says whether it adds to (debit) or pays off (credit) the balance.
 */
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'sub_folio_id',
        'transaction_type',
        'amount',
        'charge_code_id',
        'source_sub_booking_id',
        'payment_id',
        'outlet_bill_id',
        'booking_addon_id',
        'description',
        'posted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transaction_type' => TransactionType::class,
            'amount' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SubFolio, $this>
     */
    public function subFolio(): BelongsTo
    {
        return $this->belongsTo(SubFolio::class);
    }

    /**
     * @return BelongsTo<ChargeCode, $this>
     */
    public function chargeCode(): BelongsTo
    {
        return $this->belongsTo(ChargeCode::class);
    }

    /**
     * @return BelongsTo<SubBooking, $this>
     */
    public function sourceSubBooking(): BelongsTo
    {
        return $this->belongsTo(SubBooking::class, 'source_sub_booking_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * The POS bill behind the entry.
     *
     * @return BelongsTo<OutletBill, $this>
     */
    public function outletBill(): BelongsTo
    {
        return $this->belongsTo(OutletBill::class);
    }

    /**
     * The booking add-on behind the entry.
     *
     * @return BelongsTo<BookingAddon, $this>
     */
    public function bookingAddon(): BelongsTo
    {
        return $this->belongsTo(BookingAddon::class)->withTrashed();
    }

    /**
     * Debits minus credits for the transactions a query selects, in one SQL aggregate.
     *
     * @param  Builder<Transaction>  $query
     */
    public static function balanceOf(Builder $query): float
    {
        $debit = TransactionType::Debit->value;

        $row = $query->toBase()
            ->selectRaw("COALESCE(SUM(CASE WHEN transactions.transaction_type = '{$debit}' THEN transactions.amount ELSE -transactions.amount END), 0) as balance")
            ->first();

        return round((float) ($row->balance ?? 0), 2);
    }
}
