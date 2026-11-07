<?php

namespace App\Models;

use Database\Factories\RoomCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoomCategory extends Model
{
    /** @use HasFactory<RoomCategoryFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'property_id',
        'display_order',
        'upsell_order',
        'description',
        'abbreviation',
        'integration_code',
        'inclusion_details',
        'base_occupancy',
        'ideal_occupancy',
        'max_adults',
        'max_children',
        'default_number_of_children',
        'total_number_of_guests',
        'sync_category_on_ota',
        'exclude_from_revenue',
        'exclude_from_occupancy',
        'exclude_from_upgrade',
        'sell_by_bed',
        'meal_plans',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sync_category_on_ota' => 'boolean',
            'exclude_from_revenue' => 'boolean',
            'exclude_from_occupancy' => 'boolean',
            'exclude_from_upgrade' => 'boolean',
            'sell_by_bed' => 'boolean',
            'meal_plans' => 'array',
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
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class)->orderBy('display_order');
    }

    /**
     * @return HasMany<RoomCategoryImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(RoomCategoryImage::class);
    }

    /**
     * @return HasMany<RoomCategoryBedType, $this>
     */
    public function bedTypes(): HasMany
    {
        return $this->hasMany(RoomCategoryBedType::class);
    }

    /**
     * @return BelongsToMany<Amenity, $this>
     */
    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'room_category_amenities', 'room_category_id', 'amenity_id')->withTimestamps();
    }
}
