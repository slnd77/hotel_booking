<?php

namespace App\Models;

use Database\Factories\SupportedThirdPartyAnalyticsFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SupportedThirdPartyAnalytics extends Model
{
    /** @use HasFactory<SupportedThirdPartyAnalyticsFactory> */
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
            'property_third_party_analytics',
        )->withPivot('tracking_id')->withTimestamps();
    }
}
