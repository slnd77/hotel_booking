<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\PropertyFactory;
use DateTimeZone;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    /** @use HasFactory<PropertyFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * The public booking engine URL binds by `ulid`, not the auto-increment `id`.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected $fillable = [
        'name',
        'property_legal_name',
        'property_group_id',
        'abbreviation',
        'property_type',
        'third_party_analytics_enabled',
        'third_party_ad_tracking_enabled',
        'property_contact_number',
        'outlet_sac_code_enabled',
        'address_line_1',
        'address_line_2',
        'postal_code',
        'city_id',
        'state_id',
        'country_id',
        'corporate_address_permanent_address_same',
        'corporate_address_line_1',
        'corporate_address_line_2',
        'corporate_postal_code',
        'corporate_city_id',
        'corporate_state_id',
        'corporate_country_id',
        'currency_id',
        'timezone',
        'ignore_decimal_places_in_bill_settlement',
        'checkout_tolerance_value',
        'language',
        'location_map_url',
        'latitude',
        'longitude',
        'property_segment',
        'star_rating',
        'tax_type',
        'metasearch_engines_listings_enabled',
        'customise_name_for+room_invoice',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'third_party_analytics_enabled' => 'boolean',
            'third_party_ad_tracking_enabled' => 'boolean',
            'outlet_sac_code_enabled' => 'boolean',
            'corporate_address_permanent_address_same' => 'boolean',
            'ignore_decimal_places_in_bill_settlement' => 'boolean',
            'metasearch_engines_listings_enabled' => 'boolean',
            'checkout_tolerance_value' => 'decimal:4',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
        ];
    }

    /** Used when a property has no timezone set. */
    public const DEFAULT_TIMEZONE = 'Asia/Kolkata';

    /**
     * The property's IANA timezone. Times are stored in UTC; this only turns them into the
     * property's wall-clock time and business date.
     */
    public function timezoneName(): string
    {
        return in_array($this->timezone, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true) ? $this->timezone : self::DEFAULT_TIMEZONE;
    }

    /**
     * An instant (default: now) in the property's local time — for business dates and
     * server-rendered documents only; never store the result.
     */
    public function localTime(?CarbonInterface $at = null): CarbonImmutable
    {
        return CarbonImmutable::instance($at ?? now())->setTimezone($this->timezoneName());
    }

    /**
     * The property's business date today (Y-m-d): at 01:00 in India it's already the next
     * day there while UTC is still on the previous one.
     */
    public function today(): string
    {
        return $this->localTime()->toDateString();
    }

    /**
     * @return BelongsTo<PropertyGroups, $this>
     */
    public function propertyGroup(): BelongsTo
    {
        return $this->belongsTo(PropertyGroups::class, 'property_group_id');
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return BelongsTo<Country, $this>
     */
    public function corporateCountry(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'corporate_country_id');
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function corporateState(): BelongsTo
    {
        return $this->belongsTo(State::class, 'corporate_state_id');
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function corporateCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'corporate_city_id');
    }

    /**
     * @return BelongsToMany<SupportedThirdPartyAnalytics, $this>
     */
    public function thirdPartyAnalytics(): BelongsToMany
    {
        return $this->belongsToMany(
            SupportedThirdPartyAnalytics::class,
            'property_third_party_analytics',
        )->withPivot('tracking_id')->withTimestamps();
    }

    /**
     * @return BelongsToMany<SupportedThirdPartyAdTracking, $this>
     */
    public function thirdPartyAdTracking(): BelongsToMany
    {
        return $this->belongsToMany(
            SupportedThirdPartyAdTracking::class,
            'property_third_party_ad_trackings',
        )->withPivot('tracking_id')->withTimestamps();
    }

    /**
     * @return BelongsToMany<SupportedMetasearchPlatform, $this>
     */
    public function metasearchPlatforms(): BelongsToMany
    {
        return $this->belongsToMany(
            SupportedMetasearchPlatform::class,
            'property_meta_search_platform',
        )->withPivot('listing_id')->withTimestamps();
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /**
     * @return BelongsTo<Language, $this>
     */
    public function languageDetails(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'language');
    }

    /**
     * @return HasMany<PropertyMetaField, $this>
     */
    public function metaFieldValues(): HasMany
    {
        return $this->hasMany(PropertyMetaField::class);
    }

    /**
     * @return HasMany<PropertyCancellationRule, $this>
     */
    public function cancellationRules(): HasMany
    {
        return $this->hasMany(PropertyCancellationRule::class);
    }

    /**
     * @return BelongsToMany<Amenity, $this>
     */
    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'property_amenities')->withTimestamps();
    }

    /**
     * @return HasMany<RoomCategory, $this>
     */
    public function roomCategories(): HasMany
    {
        return $this->hasMany(RoomCategory::class)->orderBy('display_order');
    }

    /**
     * @return HasMany<Outlet, $this>
     */
    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class)->orderBy('display_order')->orderBy('id');
    }

    /**
     * @return HasMany<OutletBill, $this>
     */
    public function outletBills(): HasMany
    {
        return $this->hasMany(OutletBill::class);
    }

    /**
     * Orders guests placed from table QR codes.
     *
     * @return HasMany<OutletGuestOrder, $this>
     */
    public function outletGuestOrders(): HasMany
    {
        return $this->hasMany(OutletGuestOrder::class);
    }

    /**
     * Add-on services sold with bookings.
     *
     * @return HasMany<Addon, $this>
     */
    public function addons(): HasMany
    {
        return $this->hasMany(Addon::class)->orderBy('display_order')->orderBy('id');
    }

    /**
     * @return HasMany<OutletKot, $this>
     */
    public function outletKots(): HasMany
    {
        return $this->hasMany(OutletKot::class);
    }

    /**
     * @return HasMany<NightAudit, $this>
     */
    public function nightAudits(): HasMany
    {
        return $this->hasMany(NightAudit::class);
    }

    /**
     * @return HasMany<Agency, $this>
     */
    public function agencies(): HasMany
    {
        return $this->hasMany(Agency::class);
    }

    /**
     * @return HasMany<AgentRateCode, $this>
     */
    public function agentRateCodes(): HasMany
    {
        return $this->hasMany(AgentRateCode::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * @return HasMany<WaitlistRequest, $this>
     */
    public function waitlistRequests(): HasMany
    {
        return $this->hasMany(WaitlistRequest::class);
    }

    /**
     * @return HasMany<Guest, $this>
     */
    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class);
    }
}
