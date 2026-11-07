<?php

namespace App\Models;

use Database\Factories\ChannexRateMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rate row (room category × meal plan × adults) sold as a Channex rate plan: its OTA rates
 * from Rate Setup are pushed there, and OTA bookings on that plan get its meal plan.
 */
class ChannexRateMapping extends Model
{
    /** @use HasFactory<ChannexRateMappingFactory> */
    use HasFactory;

    protected $fillable = [
        'channex_connection_id',
        'room_category_id',
        'meal_plan',
        'adults',
        'package_id',
        'channex_rate_plan_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'adults' => 'integer',
            'package_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ChannexConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ChannexConnection::class, 'channex_connection_id');
    }

    /**
     * @return BelongsTo<RoomCategory, $this>
     */
    public function roomCategory(): BelongsTo
    {
        return $this->belongsTo(RoomCategory::class);
    }
}
