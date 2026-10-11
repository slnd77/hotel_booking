<?php

namespace App\Models;

use Database\Factories\OutletKotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kitchen order ticket of a POS bill: the items added to (or taken off) the bill since its
 * previous ticket.
 */
class OutletKot extends Model
{
    /** @use HasFactory<OutletKotFactory> */
    use HasFactory;

    protected $fillable = [
        'property_id',
        'outlet_id',
        'outlet_bill_id',
        'kot_number',
        'business_date',
        'notes',
        'created_by',
        'printed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_date' => 'date',
            'printed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Outlet, $this>
     */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /**
     * @return BelongsTo<OutletBill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(OutletBill::class, 'outlet_bill_id');
    }

    /**
     * @return HasMany<OutletKotItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OutletKotItem::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether the ticket only takes items off (the bill was cut down or voided).
     */
    public function isCancellation(): bool
    {
        return $this->items->isNotEmpty() && $this->items->every(fn (OutletKotItem $line): bool => $line->quantity < 0);
    }
}
