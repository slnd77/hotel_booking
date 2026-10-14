<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A room of a booking moved to a better room category: complimentary (the rate stays) or paid
 * (a supplement per night for the nights left), with the reason and who did it, for the audit.
 */
class RoomUpgrade extends Model
{
    public const COMPLIMENTARY = 'complimentary';

    public const PAID = 'paid';

    protected $fillable = [
        'property_id',
        'booking_id',
        'sub_booking_id',
        'from_room_category_id',
        'to_room_category_id',
        'from_room_id',
        'to_room_id',
        'type',
        'supplement_per_night',
        'nights',
        'rate_before',
        'rate_after',
        'reason',
        'upgraded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'supplement_per_night' => 'decimal:2',
            'rate_before' => 'decimal:2',
            'rate_after' => 'decimal:2',
            'nights' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<RoomCategory, $this>
     */
    public function fromCategory(): BelongsTo
    {
        return $this->belongsTo(RoomCategory::class, 'from_room_category_id');
    }

    /**
     * @return BelongsTo<RoomCategory, $this>
     */
    public function toCategory(): BelongsTo
    {
        return $this->belongsTo(RoomCategory::class, 'to_room_category_id');
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function fromRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'from_room_id');
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function toRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'to_room_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function upgrader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'upgraded_by');
    }
}
