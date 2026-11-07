<?php

namespace App\Models;

use Database\Factories\InventoryUnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sellable unit of inventory: a whole room, or one bed in a shared room.
 */
class InventoryUnit extends Model
{
    /** @use HasFactory<InventoryUnitFactory> */
    use HasFactory;

    public const TYPE_ROOM = 'room';

    public const TYPE_BED = 'bed';

    protected $fillable = [
        'property_id',
        'room_category_id',
        'room_id',
        'unit_type',
        'name',
        'capacity',
        'is_active',
    ];

    /**
     * The whole-room unit of a physical room, created when the room has none yet
     * (rooms added after the inventory layer was introduced).
     */
    public static function forRoom(int $roomId): self
    {
        $unit = self::query()->where('room_id', $roomId)->where('unit_type', self::TYPE_ROOM)->first();

        if ($unit !== null) {
            return $unit;
        }

        $room = Room::query()->with('roomCategory:id,property_id')->findOrFail($roomId);

        return self::query()->create([
            'property_id' => $room->roomCategory->property_id,
            'room_category_id' => $room->room_category_id,
            'room_id' => $room->id,
            'unit_type' => self::TYPE_ROOM,
            'name' => $room->name,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_active' => 'boolean',
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

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return HasMany<SubBooking, $this>
     */
    public function subBookings(): HasMany
    {
        return $this->hasMany(SubBooking::class);
    }
}
