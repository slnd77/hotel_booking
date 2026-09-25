<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePropertyRequest;
use App\Http\Requests\Admin\UpdatePropertyRequest;
use App\Models\ActivityLog;
use App\Models\Addon;
use App\Models\AmenityCategory;
use App\Models\Country;
use App\Models\Currency;
use App\Models\FormField;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\PropertyGroups;
use App\Models\RoomCategory;
use App\Models\RoomCategoryAmenity;
use App\Models\SupportedMetasearchPlatform;
use App\Models\SupportedThirdPartyAdTracking;
use App\Models\SupportedThirdPartyAnalytics;
use App\Models\User;
use App\Services\BookingEngineService;
use App\Services\PropertyBranding;
use App\Services\StayTimeCharges;
use App\Services\WebCheckins;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PropertyController extends Controller
{
    /**
     * Sortable columns of the properties table, mapped to the SQL they sort by.
     *
     * @var array<string, list<string>>
     */
    private const SORTABLE_COLUMNS = [
        'name' => ['properties.name'],
        'abbreviation' => ['properties.abbreviation'],
        'group' => ['property_groups.name'],
        'location' => ['cities.name', 'countries.name'],
        'created_at' => ['properties.created_at'],
    ];

    /**
     * @var list<int>
     */
    private const PAGE_SIZES = [10, 25, 50, 100];

    public function index(Request $request): Response
    {
        $sort = array_key_exists($request->query('sort'), self::SORTABLE_COLUMNS) ? $request->query('sort') : 'created_at';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $perPage = in_array($request->integer('per_page'), self::PAGE_SIZES, true) ? $request->integer('per_page') : self::PAGE_SIZES[0];

        $query = Property::query()
            ->select('properties.*')
            ->with(['propertyGroup', 'country', 'city'])
            ->leftJoin('property_groups', 'property_groups.id', '=', 'properties.property_group_id')
            ->leftJoin('cities', 'cities.id', '=', 'properties.city_id')
            ->leftJoin('countries', 'countries.id', '=', 'properties.country_id');

        foreach (self::SORTABLE_COLUMNS[$sort] as $column) {
            $query->orderBy($column, $direction);
        }

        return Inertia::render('admin/properties/Index', [
            'properties' => $query->orderBy('properties.id', $direction)->paginate($perPage)->withQueryString(),
            'sort' => $sort,
            'direction' => $direction,
            'perPage' => $perPage,
            'pageSizes' => self::PAGE_SIZES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/properties/Create', $this->formProps());
    }

    public function store(StorePropertyRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $property = Property::create(collect($data)->except(['third_party_analytics', 'third_party_ad_tracking', 'metasearch_platforms', 'meta_fields'])->all());

        $this->syncThirdPartyIntegrations($property, $data);
        $this->syncMetasearchPlatforms($property, $data);
        $this->syncMetaFields($property, $data);

        return redirect()->route('admin.properties.index')->with('success', 'Property created.');
    }

    public function show(Property $property): Response
    {
        return Inertia::render('admin/properties/Show', [
            'property' => $property->load([
                'propertyGroup',
                'country',
                'state',
                'city',
                'corporateCountry',
                'corporateState',
                'corporateCity',
                'thirdPartyAnalytics',
                'thirdPartyAdTracking',
                'metasearchPlatforms',
                'metaFieldValues.metaField',
            ]),
        ]);
    }

    /** Prefix of the activity entries Policies → Activity Logs lists. */
    public const POLICY_LOG_PREFIX = 'Policy';

    /** @var array<string, string> the Policies tab's settings, by meta field key */
    private const POLICY_LABELS = [
        'checkin_checkout_time' => 'Check-In/Check-Out Time',
        'weekend_holidays' => 'Weekends & Holidays',
        'card_types' => 'Card Types',
        'checkin_checkout_policy' => 'Check-In/Check-Out Policy',
        'payment_policy' => 'Payment Policy',
        'house_rules' => 'House Rules',
    ];

    public function edit(Property $property): Response
    {
        return Inertia::render('admin/properties/Edit', [
            ...$this->formProps(),
            'property' => $property->load([
                'thirdPartyAnalytics',
                'thirdPartyAdTracking',
                'metasearchPlatforms',
                'metaFieldValues',
                'cancellationRules',
                'amenities',
                'roomCategories.rooms',
                'roomCategories.images',
                'roomCategories.bedTypes',
            ]),
            'upsellCategoryIds' => $this->upsellCategoryIds($property),
            'branding' => app(PropertyBranding::class)->forDisplay($property),
            'brandingLogs' => app(PropertyBranding::class)->logs($property),
            // Add-ons tab: the add-on services sold with bookings.
            'addons' => $property->addons()->get()->map(fn (Addon $addon): array => [...$addon->toOption(), 'sac_hsn_code' => $addon->sac_hsn_code, 'show_on_booking_engine' => $addon->show_on_booking_engine, 'status' => $addon->status])->values(),
            'addonPricing' => collect(Addon::PRICING)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values(),
            // Web Check-in tab.
            'webCheckin' => app(WebCheckins::class)->settings($property),
            // Early / Late charges tab, with the policy's check-in and check-out times.
            'stayTimeCharges' => app(StayTimeCharges::class)->settings($property),
            'standardStayTimes' => app(StayTimeCharges::class)->standardTimes($property),
            'propertyToday' => $property->today(),
            // Policies → Activity Logs.
            'policyLogs' => ActivityLog::query()
                ->where('property_id', $property->id)
                ->whereLike('action', self::POLICY_LOG_PREFIX.' %')
                ->with('user:id,name')
                ->latest('date')
                ->latest('id')
                ->limit(100)
                ->get()
                ->map(fn (ActivityLog $log): array => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'user' => $log->user?->name,
                    'date' => $log->date->toIso8601ZuluString(),
                ]),
            'roomCategoryAmenities' => RoomCategoryAmenity::query()->where('property_id', $property->id)->get(['room_category_id', 'amenity_id']),
            // Rooms Setup → Activity Logs.
            'roomCategoryLogs' => ActivityLog::query()
                ->where('property_id', $property->id)
                ->whereLike('action', PropertyRoomCategoryController::LOG_PREFIX.' %')
                ->with('user:id,name')
                ->latest('date')
                ->latest('id')
                ->limit(100)
                ->get()
                ->map(fn (ActivityLog $log): array => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'user' => $log->user?->name,
                    'date' => $log->date->toIso8601ZuluString(),
                ]),
        ]);
    }

    /**
     * Rooms Setup → upsell ladder, highest price first: the order saved by dragging, and by
     * default the categories by today's direct rack rate (base occupancy, first meal plan),
     * dearest first; ties, and categories without a rate, by display order, last first.
     *
     * @return list<int>
     */
    private function upsellCategoryIds(Property $property): array
    {
        $today = CarbonImmutable::parse($property->today());
        $engine = app(BookingEngineService::class);

        return array_values($property->roomCategories
            ->map(fn (RoomCategory $category): array => [
                'id' => $category->id,
                'upsell_order' => $category->upsell_order,
                'display_order' => (int) $category->display_order,
                'rate' => $category->upsell_order === null
                    ? $engine->averageNightlyRate($property, $category, (string) ($category->meal_plans[0] ?? 'EP'), max(1, (int) $category->base_occupancy), $today, $today->addDay())
                    : 0.0,
            ])
            ->sort(fn (array $a, array $b): int => [$a['upsell_order'] === null, $a['upsell_order'], -$a['rate'], -$a['display_order']]
                <=> [$b['upsell_order'] === null, $b['upsell_order'], -$b['rate'], -$b['display_order']])
            ->map(fn (array $category): int => $category['id'])
            ->all());
    }

    public function update(UpdatePropertyRequest $request, Property $property): RedirectResponse
    {
        $data = $request->validated();

        $property->update(collect($data)->except(['third_party_analytics', 'third_party_ad_tracking', 'metasearch_platforms', 'meta_fields'])->all());

        $this->syncThirdPartyIntegrations($property, $data);
        $this->syncMetasearchPlatforms($property, $data);
        $this->syncMetaFields($property, $data, $request->user());

        return redirect()->route('admin.properties.edit', $property)->with('success', 'Property updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formProps(): array
    {
        return [
            'propertyGroups' => PropertyGroups::query()->orderBy('name')->get(['id', 'name']),
            'countries' => Country::query()->orderBy('name')->get(['id', 'name']),
            'currencies' => Currency::query()->orderBy('code')->get(['id', 'name']),
            'languages' => Language::query()->orderBy('name')->get(['id', 'name']),
            'supportedThirdPartyAnalytics' => SupportedThirdPartyAnalytics::query()->orderBy('name')->get(['id', 'name']),
            'supportedThirdPartyAdTrackings' => SupportedThirdPartyAdTracking::query()->orderBy('name')->get(['id', 'name']),
            'supportedMetasearchPlatforms' => SupportedMetasearchPlatform::query()->orderBy('name')->get(['id', 'name']),
            'starRatingOptions' => FormField::options('star_rating'),
            'propertySegmentOptions' => FormField::options('property_segment'),
            'propertyTypeOptions' => FormField::options('property_type'),
            'taxTypeOptions' => FormField::options('tax_type'),
            'mealPlanCatalog' => FormField::options('meal_plans'),
            'bedTypeCatalog' => FormField::options('bed_types'),
            'bookingTypeCatalog' => FormField::options('booking_type'),
            // Branding saves through its own form (with uploads): keep the main form from resaving it.
            'metaFields' => MetaField::query()->where('status', true)->where('key', '!=', PropertyBranding::KEY)->orderBy('id')->get(['id', 'key', 'key_label']),
            'propertyAmenityCategories' => AmenityCategory::query()
                ->where('status', true)
                ->whereHas('amenities', fn ($query) => $query->where('type', 'property'))
                ->with(['amenities' => fn ($query) => $query->where('type', 'property')->orderBy('id')])
                ->orderBy('id')
                ->get(),
            'roomCategoryAmenityCategories' => AmenityCategory::query()
                ->where('status', true)
                ->whereHas('amenities', fn ($query) => $query->where('type', 'room_category'))
                ->with(['amenities' => fn ($query) => $query->where('type', 'room_category')->orderBy('id')])
                ->orderBy('id')
                ->get(),
        ];
    }

    /**
     * @param  array{third_party_analytics_enabled?: bool|null, third_party_analytics?: array<array-key, mixed>|null, third_party_ad_tracking_enabled?: bool|null, third_party_ad_tracking?: array<array-key, mixed>|null}  $data
     */
    private function syncThirdPartyIntegrations(Property $property, array $data): void
    {
        $analytics = $data['third_party_analytics_enabled'] ?? false
            ? collect($data['third_party_analytics'] ?? [])->filter()->map(fn ($trackingId) => ['tracking_id' => $trackingId])
            : collect();

        $property->thirdPartyAnalytics()->sync($analytics->all());

        $adTracking = $data['third_party_ad_tracking_enabled'] ?? false
            ? collect($data['third_party_ad_tracking'] ?? [])->filter()->map(fn ($trackingId) => ['tracking_id' => $trackingId])
            : collect();

        $property->thirdPartyAdTracking()->sync($adTracking->all());
    }

    /**
     * @param  array{metasearch_engines_listings_enabled?: bool|null, metasearch_platforms?: array<array-key, mixed>|null}  $data
     */
    private function syncMetasearchPlatforms(Property $property, array $data): void
    {
        $platforms = $data['metasearch_engines_listings_enabled'] ?? false
            ? collect($data['metasearch_platforms'] ?? [])->filter()->map(fn ($listingId) => ['listing_id' => $listingId])
            : collect();

        $property->metasearchPlatforms()->sync($platforms->all());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncMetaFields(Property $property, array $data, ?User $user = null): void
    {
        $policyKeys = MetaField::query()->whereIn('key', array_keys(self::POLICY_LABELS))->pluck('key', 'id');
        $before = $property->metaFieldValues()->pluck('data', 'meta_fields_key_id');

        foreach ($data['meta_fields'] ?? [] as $metaFieldKeyId => $value) {
            $property->metaFieldValues()->updateOrCreate(
                ['meta_fields_key_id' => $metaFieldKeyId],
                ['enabled' => $value['enabled'] ?? false, 'data' => $value['data'] ?? null],
            );

            if ($user !== null && $policyKeys->has($metaFieldKeyId) && $this->policyText($before[$metaFieldKeyId] ?? null) !== $this->policyText($value['data'] ?? null)) {
                ActivityLog::create([
                    'user_id' => $user->id,
                    'property_id' => $property->id,
                    'action' => self::POLICY_LOG_PREFIX.' '.self::POLICY_LABELS[$policyKeys[$metaFieldKeyId]].' updated.',
                    'date' => now(),
                ]);
            }
        }
    }

    /**
     * A policy value for change detection: an editor's empty markup ("<p><br></p>") is no text.
     */
    private function policyText(?string $value): string
    {
        $value = trim((string) $value);

        return trim(strip_tags($value)) === '' ? '' : $value;
    }
}
