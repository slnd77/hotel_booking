<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line on a city ledger account: a charge (+), a payment or credit (−).
 */
class CityLedgerEntry extends Model
{
    public const CHARGE = 'charge';

    public const PAYMENT = 'payment';

    public const ADJUSTMENT = 'adjustment';

    protected $fillable = [
        'property_id',
        'city_ledger_account_id',
        'entry_type',
        'entry_date',
        'due_date',
        'amount',
        'booking_id',
        'payment_id',
        'transaction_id',
        'reference',
        'description',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'due_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<CityLedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CityLedgerAccount::class, 'city_ledger_account_id');
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
