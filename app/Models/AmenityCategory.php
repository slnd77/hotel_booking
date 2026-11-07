<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AmenityCategory extends Model
{
    protected $fillable = [
        'name',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Amenity, $this>
     */
    public function amenities(): HasMany
    {
        return $this->hasMany(Amenity::class);
    }
}
