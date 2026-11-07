<?php

namespace App\Models;

use Database\Factories\ChannexRoomMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A room category sold as a Channex room type: its availability is pushed there, and OTA
 * bookings for that room type land in the category.
 */
class ChannexRoomMapping extends Model
{
    /** @use HasFactory<ChannexRoomMappingFactory> */
    use HasFactory;

    protected $fillable = [
        'channex_connection_id',
        'room_category_id',
        'channex_room_type_id',
    ];

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
