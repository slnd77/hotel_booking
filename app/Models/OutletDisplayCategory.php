<?php

namespace App\Models;

use Database\Factories\OutletDisplayCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A menu section of an outlet ("Special Items", "Beverages"…).
 */
class OutletDisplayCategory extends Model
{
    /** @use HasFactory<OutletDisplayCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'outlet_id',
        'name',
        'display_order',
    ];

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
