<?php

namespace App\Models;

use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    protected $fillable = [
        'room_category_id',
        'name',
        'display_order',
        'bed_count',
        'housekeeping_status',
        'housekeeping_updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'housekeeping_updated_at' => 'datetime',
            'bed_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<RoomCategory, $this>
     */
    public function roomCategory(): BelongsTo
    {
        return $this->belongsTo(RoomCategory::class);
    }

    /**
     * The room's sellable units: the whole room, and one per bed in a dormitory.
     *
     * @return HasMany<InventoryUnit, $this>
     */
    public function inventoryUnits(): HasMany
    {
        return $this->hasMany(InventoryUnit::class);
    }
}
