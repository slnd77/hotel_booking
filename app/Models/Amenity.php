<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Amenity extends Model
{
    protected $fillable = [
        'key',
        'name',
        'amenity_category_id',
        'type',
    ];

    /**
     * @return BelongsTo<AmenityCategory, $this>
     */
    public function amenityCategory(): BelongsTo
    {
        return $this->belongsTo(AmenityCategory::class);
    }
}
