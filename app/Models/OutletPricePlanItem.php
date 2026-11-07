<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item's price (before tax) in a price plan.
 */
class OutletPricePlanItem extends Model
{
    protected $fillable = [
        'outlet_price_plan_id',
        'outlet_item_id',
        'price',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<OutletPricePlan, $this>
     */
    public function pricePlan(): BelongsTo
    {
        return $this->belongsTo(OutletPricePlan::class, 'outlet_price_plan_id');
    }

    /**
     * @return BelongsTo<OutletItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(OutletItem::class, 'outlet_item_id');
    }
}
