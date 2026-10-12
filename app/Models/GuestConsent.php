<?php

namespace App\Models;

use Database\Factories\GuestConsentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guest's consent (or refusal) to one purpose: the property's terms, its privacy policy, or
 * marketing. Kept as evidence (when, what version, from where); withdrawing sets withdrawn_at.
 */
class GuestConsent extends Model
{
    /** @use HasFactory<GuestConsentFactory> */
    use HasFactory;

    public const TERMS = 'terms';

    public const PRIVACY = 'privacy';

    public const MARKETING = 'marketing';

    protected $fillable = [
        'property_id',
        'guest_id',
        'booking_id',
        'purpose',
        'granted',
        'policy_version',
        'source',
        'ip_address',
        'user_agent',
        'withdrawn_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
            'withdrawn_at' => 'datetime',
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
     * @return BelongsTo<Guest, $this>
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
