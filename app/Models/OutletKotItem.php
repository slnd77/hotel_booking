<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a kitchen ticket; a negative quantity cancels items sent before.
 */
class OutletKotItem extends Model
{
    protected $fillable = [
        'outlet_kot_id',
        'outlet_item_id',
        'name',
        'quantity',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<OutletKot, $this>
     */
    public function kot(): BelongsTo
    {
        return $this->belongsTo(OutletKot::class, 'outlet_kot_id');
    }
}
