<?php

namespace App\Models;

use Database\Factories\OutletItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A catalogue item of an outlet. Its price comes from the outlet's price plans.
 */
class OutletItem extends Model
{
    /** @use HasFactory<OutletItemFactory> */
    use HasFactory;

    /** @var array<string, string> */
    public const ITEM_TYPES = [
        'none' => 'None',
        'veg' => 'Veg',
        'non_veg' => 'Non-Veg',
        'beverage' => 'Beverage',
        'alcoholic' => 'Alcoholic',
        'service' => 'Service',
    ];

    protected $fillable = [
        'outlet_id',
        'outlet_display_category_id',
        'name',
        'tax_category_id',
        'outlet_tax_rate_id',
        'sac_hsn_code',
        'item_type',
        'status',
        'display_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * @return BelongsTo<OutletDisplayCategory, $this>
     */
    public function displayCategory(): BelongsTo
    {
        return $this->belongsTo(OutletDisplayCategory::class, 'outlet_display_category_id');
    }

    /**
     * @return BelongsTo<OutletTaxRate, $this>
     */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(OutletTaxRate::class, 'outlet_tax_rate_id');
    }

    /**
     * @return HasMany<OutletPricePlanItem, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(OutletPricePlanItem::class);
    }
}
