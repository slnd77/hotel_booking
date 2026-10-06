<?php

namespace App\Models;

use Database\Factories\AgencyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A travel agency or corporate a property takes bookings from (Databases → Agents).
 * Each gets a six-digit code per property (000001, 000002…) and has one or more contacts.
 */
class Agency extends Model
{
    /** @use HasFactory<AgencyFactory> */
    use HasFactory;

    public const SOURCE_AGENT = 'agent';

    public const SOURCE_CORPORATE = 'corporate';

    /** @var array<string, string> */
    public const SOURCES = [
        self::SOURCE_AGENT => 'Agent',
        self::SOURCE_CORPORATE => 'Corporate',
    ];

    protected $fillable = [
        'property_id',
        'agent_rate_code_id',
        'source',
        'name',
        'code',
        'address',
        'city',
    ];

    protected static function booted(): void
    {
        static::creating(function (Agency $agency): void {
            $agency->code ??= self::nextCode($agency->property_id);
        });
    }

    /**
     * The property's next agency code: one more than its highest, six digits.
     */
    public static function nextCode(int $propertyId): string
    {
        $highest = (int) self::query()->where('property_id', $propertyId)->max('code');

        return str_pad((string) ($highest + 1), 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<AgentRateCode, $this>
     */
    public function rateCode(): BelongsTo
    {
        return $this->belongsTo(AgentRateCode::class, 'agent_rate_code_id');
    }

    /**
     * @return HasMany<Agent, $this>
     */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }
}
