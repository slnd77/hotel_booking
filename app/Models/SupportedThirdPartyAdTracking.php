<?php

namespace App\Models;

use Database\Factories\SupportedThirdPartyAdTrackingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SupportedThirdPartyAdTracking extends Model
{
    /** @use HasFactory<SupportedThirdPartyAdTrackingFactory> */
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
            'property_third_party_ad_trackings',
        )->withPivot('tracking_id')->withTimestamps();
    }
}
