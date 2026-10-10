<?php

namespace App\Models;

use Database\Factories\ChannexConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A property linked to its Channex property: availability and rates are pushed to it, and
 * OTA bookings come back from it.
 */
class ChannexConnection extends Model
{
    /** @use HasFactory<ChannexConnectionFactory> */
    use HasFactory;

    protected $fillable = [
        'property_id',
        'channex_property_id',
        'api_key',
        'webhook_token',
        'channex_webhook_id',
        'is_active',
        'sync_days',
        'last_pushed_at',
        'last_pulled_at',
        'last_error',
    ];

    protected $hidden = ['api_key', 'webhook_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
            'sync_days' => 'integer',
            'last_pushed_at' => 'datetime',
            'last_pulled_at' => 'datetime',
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
     * @return HasMany<ChannexRoomMapping, $this>
     */
    public function roomMappings(): HasMany
    {
        return $this->hasMany(ChannexRoomMapping::class);
    }

    /**
     * @return HasMany<ChannexRateMapping, $this>
     */
    public function rateMappings(): HasMany
    {
        return $this->hasMany(ChannexRateMapping::class);
    }

    /**
     * @return HasMany<ChannexBookingRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(ChannexBookingRevision::class);
    }
}
