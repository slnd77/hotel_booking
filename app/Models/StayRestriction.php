<?php

namespace App\Models;

use Database\Factories\StayRestrictionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A date's stay restrictions for a room category, or for the whole property when the category
 * is null: minimum / maximum stay and closed to arrival for stays arriving that day, closed to
 * departure for stays leaving that day, and stop-sell for any stay including the night.
 */
class StayRestriction extends Model
{
    /** @use HasFactory<StayRestrictionFactory> */
    use HasFactory;

    protected $fillable = [
        'property_id',
        'room_category_id',
        'date',
        'min_stay',
        'max_stay',
        'closed_to_arrival',
        'closed_to_departure',
        'stop_sell',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'min_stay' => 'integer',
            'max_stay' => 'integer',
            'closed_to_arrival' => 'boolean',
            'closed_to_departure' => 'boolean',
            'stop_sell' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<RoomCategory, $this>
     */
    public function roomCategory(): BelongsTo
    {
        return $this->belongsTo(RoomCategory::class);
    }
}
