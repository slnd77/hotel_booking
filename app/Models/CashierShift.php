<?php

namespace App\Models;

use Database\Factories\CashierShiftFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cashier's shift at a property: opened with a cash float, closed with the cash counted.
 * Receipts and refunds the cashier takes while it is open are tagged with it.
 */
class CashierShift extends Model
{
    /** @use HasFactory<CashierShiftFactory> */
    use HasFactory;

    protected $fillable = [
        'property_id',
        'user_id',
        'opened_at',
        'closed_at',
        'opening_float',
        'cash_receipts',
        'cash_refunds',
        'expected_cash',
        'cash_counted',
        'variance',
        'totals_by_mode',
        'handed_over_to',
        'opening_notes',
        'closing_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_float' => 'decimal:2',
            'cash_receipts' => 'decimal:2',
            'cash_refunds' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'cash_counted' => 'decimal:2',
            'variance' => 'decimal:2',
            'totals_by_mode' => 'array',
        ];
    }

    /**
     * @param  Builder<CashierShift>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handedOverTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handed_over_to');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
