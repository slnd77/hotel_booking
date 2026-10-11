<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An add-on sold on a booking. Its charge and tax sit on the booking's master folio; a removed
 * line is soft deleted and the ledger sync reverses it.
 */
class BookingAddon extends Model
{
    use SoftDeletes;

    /** Lines charged by a room's arrival or departure time, not sold from the catalogue. @var list<string> */
    public const STAY_TIME_SOURCES = ['early_check_in', 'late_check_out'];

    protected $fillable = [
        'booking_id',
        'addon_id',
        'sub_booking_id',
        'name',
        'pricing',
        'unit_price',
        'quantity',
        'multiplier',
        'amount',
        'tax_rate',
        'tax_amount',
        'total',
        'sac_hsn_code',
        'added_from',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'quantity' => 'integer',
            'multiplier' => 'integer',
            'amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Addon, $this>
     */
    public function addon(): BelongsTo
    {
        return $this->belongsTo(Addon::class);
    }

    /**
     * The room an early check-in or late check-out charge is for.
     *
     * @return BelongsTo<SubBooking, $this>
     */
    public function subBooking(): BelongsTo
    {
        return $this->belongsTo(SubBooking::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toLine(): array
    {
        return [
            'id' => $this->id,
            'addon_id' => $this->addon_id,
            'name' => $this->name,
            'pricing' => $this->pricing,
            'pricing_label' => Addon::PRICING[$this->pricing] ?? $this->pricing,
            'unit_price' => (float) $this->unit_price,
            'quantity' => $this->quantity,
            'multiplier' => $this->multiplier,
            'amount' => (float) $this->amount,
            'tax_rate' => (float) $this->tax_rate,
            'tax_amount' => (float) $this->tax_amount,
            'total' => (float) $this->total,
            'added_from' => $this->added_from,
        ];
    }
}
