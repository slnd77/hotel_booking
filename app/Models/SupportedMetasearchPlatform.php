<?php

namespace App\Models;

use Database\Factories\SupportedMetasearchPlatformFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SupportedMetasearchPlatform extends Model
{
    /** @use HasFactory<SupportedMetasearchPlatformFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
    ];

    /**
     * @return BelongsToMany<Property, $this>
     */
    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(
            Property::class,
            'property_meta_search_platform',
        )->withPivot('listing_id')->withTimestamps();
    }
}
