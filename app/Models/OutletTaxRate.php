<?php

namespace App\Models;

use Database\Factories\OutletTaxRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An outlet tax rate (Outlet → Tax Settings), a percentage on the item price.
 */
class OutletTaxRate extends Model
{
    /** @use HasFactory<OutletTaxRateFactory> */
    use HasFactory;

    protected $fillable = [
        'outlet_id',
        'name',
        'rate',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:3',
            'status' => 'boolean',
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
     * @return HasMany<OutletItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OutletItem::class);
    }
}
