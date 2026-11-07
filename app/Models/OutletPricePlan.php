<?php

namespace App\Models;

use Database\Factories\OutletPricePlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An outlet's price list for a range of business dates.
 */
class OutletPricePlan extends Model
{
    /** @use HasFactory<OutletPricePlanFactory> */
    use HasFactory;

    protected $fillable = [
        'outlet_id',
        'name',
        'start_date',
        'end_date',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
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
     * @return HasMany<OutletPricePlanItem, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(OutletPricePlanItem::class);
    }
}
