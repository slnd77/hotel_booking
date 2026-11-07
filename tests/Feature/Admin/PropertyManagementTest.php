<?php

use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\PropertyGroups;
use App\Models\State;
use App\Models\SupportedMetasearchPlatform;
use App\Models\SupportedThirdPartyAnalytics;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->country = Country::create(['name' => 'Testland', 'iso3' => 'TST', 'iso2' => 'TL']);
    $this->state = State::create(['name' => 'Test State', 'country_id' => $this->country->id]);
    $this->city = City::create(['name' => 'Test City', 'state_id' => $this->state->id, 'country_id' => $this->country->id]);
    $this->propertyGroup = PropertyGroups::factory()->create();
    $this->currency = Currency::create(['code' => 'USD', 'name' => 'USD']);
    $this->language = Language::create(['code' => 'en', 'name' => 'English']);
});

function validPropertyPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Seaside Hotel',
        'property_legal_name' => 'Seaside Hotel Pvt Ltd',
        'property_group_id' => test()->propertyGroup->id,
        'abbreviation' => 'SEA',
        'property_type' => 1,

        'third_party_analytics_enabled' => false,
        'third_party_ad_tracking_enabled' => false,

        'property_contact_number' => '1234567890',
        'outlet_sac_code_enabled' => false,

        'address_line_1' => '123 Beach Road',
        'address_line_2' => 'Suite 1',
        'postal_code' => '00001',
        'city_id' => test()->city->id,
        'state_id' => test()->state->id,
        'country_id' => test()->country->id,

        'corporate_address_permanent_address_same' => true,
        'corporate_address_line_1' => '123 Beach Road',
        'corporate_address_line_2' => 'Suite 1',
        'corporate_postal_code' => '00001',
        'corporate_city_id' => test()->city->id,
        'corporate_state_id' => test()->state->id,
        'corporate_country_id' => test()->country->id,

        'currency_id' => test()->currency->id,
        'ignore_decimal_places_in_bill_settlement' => false,
        'checkout_tolerance_value' => 0.1,

        'language' => test()->language->id,

        'location_map_url' => 'https://maps.example.com/seaside',
        'latitude' => 12.34,
        'longitude' => 56.78,
        'property_segment' => 1,

        'star_rating' => 5,
        'tax_type' => 1,
        'metasearch_engines_listings_enabled' => false,

        'customise_name_for+room_invoice' => null,
    ], $overrides);
}

test('property index can be rendered', function () {
    Property::factory()->create(['property_group_id' => $this->propertyGroup->id]);

    $response = $this->actingAs($this->admin)->get('/admin/properties');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('admin/properties/Index'));
});

test('property create page is rendered with form options', function () {
    $response = $this->actingAs($this->admin)->get('/admin/properties/create');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/properties/Create')
        ->has('propertyGroups')
        ->has('countries')
        ->has('currencies')
        ->has('languages')
        ->has('supportedThirdPartyAnalytics')
        ->has('supportedThirdPartyAdTrackings')
        ->has('supportedMetasearchPlatforms')
        ->has('starRatingOptions')
        ->has('propertySegmentOptions')
        ->has('propertyTypeOptions')
        ->has('taxTypeOptions')
        ->has('mealPlanCatalog')
        ->has('metaFields')
        ->has('propertyAmenityCategories')
        ->has('roomCategoryAmenityCategories')
    );
});

test('a property can be created', function () {
    $response = $this->actingAs($this->admin)->post('/admin/properties', validPropertyPayload());

    $response->assertRedirect('/admin/properties');
    $this->assertDatabaseHas('properties', ['name' => 'Seaside Hotel', 'abbreviation' => 'SEA']);
});

test('a property can be created with third-party analytics enabled', function () {
    $analytics = SupportedThirdPartyAnalytics::query()->create(['name' => 'Google Analytics', 'slug' => 'google-analytics']);

    $response = $this->actingAs($this->admin)->post('/admin/properties', validPropertyPayload([
        'third_party_analytics_enabled' => true,
        'third_party_analytics' => [$analytics->id => 'UA-12345'],
    ]));

    $response->assertRedirect('/admin/properties');

    $property = Property::whereName('Seaside Hotel')->firstOrFail();
    $this->assertDatabaseHas('property_third_party_analytics', [
        'property_id' => $property->id,
        'supported_third_party_analytics_id' => $analytics->id,
        'tracking_id' => 'UA-12345',
    ]);
});

test('a property can be created with metasearch platforms enabled', function () {
    $platform = SupportedMetasearchPlatform::query()->create(['name' => 'Trivago', 'slug' => 'trivago']);

    $response = $this->actingAs($this->admin)->post('/admin/properties', validPropertyPayload([
        'metasearch_engines_listings_enabled' => true,
        'metasearch_platforms' => [$platform->id => 'LISTING-1'],
    ]));

    $response->assertRedirect('/admin/properties');

    $property = Property::whereName('Seaside Hotel')->firstOrFail();
    $this->assertDatabaseHas('property_meta_search_platform', [
        'property_id' => $property->id,
        'supported_metasearch_platform_id' => $platform->id,
        'listing_id' => 'LISTING-1',
    ]);
});

test('a property can be created with a meta field enabled', function () {
    $metaField = MetaField::query()->create(['key' => 'complementary_inclusions', 'key_label' => 'Complementary inclusions', 'status' => true]);

    $response = $this->actingAs($this->admin)->post('/admin/properties', validPropertyPayload([
        'meta_fields' => [$metaField->id => ['enabled' => true, 'data' => 'Free breakfast']],
    ]));

    $response->assertRedirect('/admin/properties');

    $property = Property::whereName('Seaside Hotel')->firstOrFail();
    $this->assertDatabaseHas('property_meta_fields', [
        'property_id' => $property->id,
        'meta_fields_key_id' => $metaField->id,
        'enabled' => true,
        'data' => 'Free breakfast',
    ]);
});

test('a property keeps the IANA timezone it is given, and rejects one that is not', function () {
    $this->actingAs($this->admin)->post('/admin/properties', validPropertyPayload(['timezone' => 'America/New_York']))
        ->assertRedirect('/admin/properties');

    expect(Property::whereName('Seaside Hotel')->value('timezone'))->toBe('America/New_York');

    $this->actingAs($this->admin)
        ->from('/admin/properties/create')
        ->post('/admin/properties', validPropertyPayload(['name' => 'Moon Hotel', 'timezone' => 'Mars/Olympus_Mons']))
        ->assertSessionHasErrors('timezone');
});

test('creating a property fails with an invalid country', function () {
    $response = $this->actingAs($this->admin)
        ->from('/admin/properties/create')
        ->post('/admin/properties', validPropertyPayload(['country_id' => 999999]));

    $response->assertRedirect('/admin/properties/create');
    $response->assertSessionHasErrors('country_id');
    $this->assertDatabaseMissing('properties', ['name' => 'Seaside Hotel']);
});

test('a property can be updated', function () {
    $property = Property::factory()->create(['property_group_id' => $this->propertyGroup->id, 'name' => 'Old Name']);

    $response = $this->actingAs($this->admin)->put("/admin/properties/{$property->id}", validPropertyPayload([
        'name' => 'New Name',
        'property_group_id' => $this->propertyGroup->id,
    ]));

    $response->assertRedirect("/admin/properties/{$property->id}/edit");
    expect($property->fresh()->name)->toBe('New Name');
});

test('a property can be shown', function () {
    $property = Property::factory()->create(['property_group_id' => $this->propertyGroup->id]);

    $response = $this->actingAs($this->admin)->get("/admin/properties/{$property->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('admin/properties/Show'));
});

test('the properties table sorts by any column and changes its page size', function () {
    foreach (['Charlie', 'Alpha', 'Bravo'] as $name) {
        Property::factory()->create(['name' => "{$name} Hotel"]);
    }

    $this->actingAs($this->admin)
        ->get(route('admin.properties.index', ['sort' => 'name', 'direction' => 'asc', 'per_page' => 25]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sort', 'name')
            ->where('direction', 'asc')
            ->where('perPage', 25)
            ->where('properties.data.0.name', 'Alpha Hotel')
            ->where('properties.data.2.name', 'Charlie Hotel'));

    $this->actingAs($this->admin)
        ->get(route('admin.properties.index', ['sort' => 'name', 'direction' => 'desc', 'per_page' => 999]))
        ->assertInertia(fn ($page) => $page
            ->where('perPage', 10)
            ->where('properties.data.0.name', 'Charlie Hotel'));

    $this->actingAs($this->admin)
        ->get(route('admin.properties.index', ['sort' => 'password', 'direction' => 'sideways']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('sort', 'created_at')->where('direction', 'desc'));
});

test('saving the policies logs each policy that changed, ignoring an empty editor', function () {
    $property = Property::factory()->create(['property_group_id' => $this->propertyGroup->id]);
    $time = MetaField::query()->create(['key' => 'checkin_checkout_time', 'key_label' => 'Check-in time', 'status' => true]);
    $rules = MetaField::query()->create(['key' => 'house_rules', 'key_label' => 'House rules', 'status' => true]);
    $payload = validPropertyPayload([
        'property_group_id' => $this->propertyGroup->id,
        'meta_fields' => [
            $time->id => ['enabled' => true, 'data' => json_encode(['check_in_hour' => 10, 'check_in_minute' => 30, 'check_out_hour' => 9, 'check_out_minute' => 0])],
            $rules->id => ['enabled' => true, 'data' => '<p><br></p>'],
        ],
    ]);

    $this->actingAs($this->admin)->put("/admin/properties/{$property->id}", $payload)->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->put("/admin/properties/{$property->id}", $payload)->assertSessionHasNoErrors();

    expect(ActivityLog::query()->where('property_id', $property->id)->pluck('action')->all())
        ->toBe(['Policy Check-In/Check-Out Time updated.']);

    $this->actingAs($this->admin)->get("/admin/properties/{$property->id}/edit")
        ->assertInertia(fn ($page) => $page
            ->has('policyLogs', 1)
            ->where('policyLogs.0.user', $this->admin->name)
            ->where('propertyToday', $property->today()));
});
