<?php

namespace App\Models;

use Database\Factories\RoomCategoryBedTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomCategoryBedType extends Model
{
    /** @use HasFactory<RoomCategoryBedTypeFactory> */
    use HasFactory;

    protected $fillable = [
        'room_category_id',
        'name',
        'type',
        'quantity',
    ];

    /**
     * @return BelongsTo<RoomCategory, $this>
     */
    public function roomCategory(): BelongsTo
    {
        return $this->belongsTo(RoomCategory::class);
    }
}
