<?php

namespace App\Models;

use App\Jobs\RefreshWaitlists;
use Database\Factories\RoomBlockFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A room taken out of inventory for a date range. `end_date` is exclusive,
 * matching how a booking's check-out night is not occupied.
 */
class RoomBlock extends Model
{
    /** @use HasFactory<RoomBlockFactory> */
    use HasFactory;

    protected $fillable = [
        'property_id',
        'room_id',
        'user_id',
        'block_reason_id',
        'start_date',
        'end_date',
        'name',
        'remarks',
    ];

    protected static function booted(): void
    {
        // A room unblocked (or blocked for fewer nights) frees inventory: re-check the waitlist.
        static::deleted(fn (RoomBlock $block) => RefreshWaitlists::dispatch((int) $block->property_id));
        static::updated(function (RoomBlock $block): void {
            if ($block->wasChanged(['start_date', 'end_date', 'room_id'])) {
                RefreshWaitlists::dispatch((int) $block->property_id);
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /**
     * Blocks occupying at least one night in [$startDate, $endDate).
     *
     * @param  Builder<RoomBlock>  $query
     */
    public function scopeOverlapping(Builder $query, string $startDate, string $endDate): void
    {
        $query->whereDate('start_date', '<', $endDate)->whereDate('end_date', '>', $startDate);
    }

    /**
     * @return BelongsTo<BlockReason, $this>
     */
    public function blockReason(): BelongsTo
    {
        return $this->belongsTo(BlockReason::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
