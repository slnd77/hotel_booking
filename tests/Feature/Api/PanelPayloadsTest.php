<?php

use App\Models\CashierShift;
use App\Models\Currency;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Outlet;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\ApiScopes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

/*
 * The staff app sends the admin panel's actions through /api/v1/panel: these are the bodies it
 * builds, as the screens build them.
 */
beforeEach(function () {
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->property = Property::factory()->create(['timezone' => 'Asia/Kolkata']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::findOrCreate('admin', 'web'));
    $this->withToken($this->admin->createToken(ApiScopes::DEVICE_PREFIX.'Pixel', ['*'])->plainTextToken);
});

test('a meta-field settings page saves the JSON of each tab', function () {
    $field = MetaField::query()->firstOrCreate(['key' => 'booking_settings_printer'], ['key_label' => 'Printer', 'status' => true]);

    $this->getJson(route('api.v1.panel.bookingSettings.edit', $this->property))
        ->assertOk()
        ->assertJsonPath('component', 'admin/booking-settings/Edit');

    $this->putJson(route('api.v1.panel.bookingSettings.update', $this->property), [
        'meta_fields' => [(string) $field->id => ['enabled' => true, 'data' => json_encode(['enable_auto_print' => true, 'printer_name' => 'Desk', 'paper_size' => 'a4'])]],
    ])->assertOk()->assertJsonPath('message', 'Booking settings updated.');

    expect(json_decode((string) $this->property->metaFieldValues()->where('meta_fields_key_id', $field->id)->value('data'), true))
        ->toMatchArray(['printer_name' => 'Desk', 'enable_auto_print' => true]);
});

test('a cashier opens and closes a shift', function () {
    $this->postJson(route('api.v1.panel.cashierShifts.open', $this->property), ['opening_float' => 500, 'opening_notes' => null])
        ->assertOk()->assertJsonPath('message', 'Shift opened.');

    $this->postJson(route('api.v1.panel.cashierShifts.close', $this->property), ['cash_counted' => 500, 'handed_over_to' => null, 'closing_notes' => null])
        ->assertOk()->assertJsonPath('message', 'Shift closed. The cash matches.');

    expect(CashierShift::query()->whereNotNull('closed_at')->count())->toBe(1);
});

test('the night audit settings save as the whole object the page loaded', function () {
    $settings = $this->getJson(route('api.v1.panel.nightAuditSettings.edit', $this->property))->assertOk()->json('props.settings');

    $this->putJson(route('api.v1.panel.nightAuditSettings.update', $this->property), [...$settings, 'restrict_reports' => true])
        ->assertOk();
});

test('privacy & terms save without the version number', function () {
    $settings = $this->getJson(route('api.v1.panel.legalSettings.edit', $this->property))->assertOk()->json('props.settings');
    unset($settings['version']);

    $this->putJson(route('api.v1.panel.legalSettings.update', $this->property), [...$settings, 'grievance_officer_email' => 'dpo@example.com'])
        ->assertOk()->assertJsonPath('message', 'Privacy & terms saved.');
});

test('stay restrictions are set over a range, weekdays and categories', function () {
    $category = RoomCategory::factory()->create(['property_id' => $this->property->id]);

    $this->putJson(route('api.v1.panel.rateRestrictions.update', $this->property), [
        'action' => 'set',
        'from' => now()->toDateString(),
        'to' => now()->addDays(3)->toDateString(),
        'weekdays' => [0, 1, 2, 3, 4, 5, 6],
        'room_category_ids' => [$category->id],
        'values' => ['min_stay' => 2, 'max_stay' => null, 'closed_to_arrival' => false, 'closed_to_departure' => false, 'stop_sell' => false],
    ])->assertOk();
});

test('an exchange rate is set', function () {
    Currency::firstOrCreate(['code' => 'EUR'], ['name' => 'EUR']);

    $this->postJson(route('api.v1.panel.exchangeRates.store', $this->property), ['currency_code' => 'EUR', 'rate' => 90.5, 'effective_date' => now()->toDateString()])
        ->assertOk();
});

test('application settings save a section', function () {
    $this->putJson(route('api.v1.panel.applicationSettings.update', 'smart_chart'), ['days_to_show' => 10, 'auto_refresh_minutes' => 5])
        ->assertOk()->assertJsonPath('message', 'Smart Chart settings saved.');
});

test('users and custom roles are managed', function () {
    $this->postJson(route('api.v1.panel.roles.store'), ['name' => 'Night Clerk', 'permissions' => []])->assertOk();

    $this->postJson(route('api.v1.panel.users.store'), [
        'name' => 'Asha', 'email' => 'asha@example.com', 'mobile_country_code' => '+91', 'mobile' => null,
        'password' => 'A-strong-pass-123', 'status' => 'active', 'role' => 'Night Clerk',
    ])->assertOk()->assertJsonPath('message', 'User created.');

    expect(User::query()->where('email', 'asha@example.com')->first()->hasRole('Night Clerk'))->toBeTrue();
});

test('an outlet and its menu item are added', function () {
    $this->postJson(route('api.v1.panel.outlets.store', $this->property), [
        'name' => 'Cafe', 'type' => 'FoodAndBeverages', 'printer_setting' => 'a4', 'printer_host' => null, 'printer_port' => null,
        'cash_drawer' => false, 'kot_printer_setting' => 'thermal_80', 'kot_printer_host' => null, 'kot_printer_port' => null,
        'display_order' => null, 'status' => true,
    ])->assertOk();

    $outlet = Outlet::query()->where('name', 'Cafe')->firstOrFail();

    $this->postJson(route('api.v1.panel.outlets.items.store', [$this->property, $outlet]), [
        'name' => 'Tea', 'outlet_display_category_id' => null, 'item_type' => 'beverage', 'tax_category_id' => null,
        'outlet_tax_rate_id' => null, 'sac_hsn_code' => null, 'display_order' => null, 'status' => true,
    ])->assertOk();

    expect($outlet->items()->where('name', 'Tea')->exists())->toBeTrue();
});

test('a property saves from the fields the edit page loads', function () {
    $page = $this->getJson(route('api.v1.panel.properties.edit', $this->property))->assertOk()->json('props.property');

    $body = collect($page)->only([
        'name', 'property_legal_name', 'property_group_id', 'abbreviation', 'property_type', 'property_contact_number',
        'address_line_1', 'address_line_2', 'postal_code', 'country_id', 'state_id', 'city_id',
        'corporate_address_permanent_address_same', 'corporate_address_line_1', 'corporate_address_line_2', 'corporate_postal_code',
        'corporate_country_id', 'corporate_state_id', 'corporate_city_id', 'currency_id', 'timezone', 'language',
        'ignore_decimal_places_in_bill_settlement', 'checkout_tolerance_value', 'location_map_url', 'latitude', 'longitude',
        'property_segment', 'star_rating', 'tax_type', 'metasearch_engines_listings_enabled', 'outlet_sac_code_enabled',
        'customise_name_for+room_invoice', 'third_party_analytics_enabled', 'third_party_ad_tracking_enabled',
        'third_party_analytics', 'third_party_ad_tracking', 'metasearch_platforms',
    ])->all();

    $this->putJson(route('api.v1.panel.properties.update', $this->property), [...$body, 'name' => 'Harbor House'])
        ->assertOk()->assertJsonPath('message', 'Property updated.');

    expect($this->property->fresh()->name)->toBe('Harbor House');
});

test('branding saves with a logo uploaded as multipart', function () {
    Storage::fake('public');

    $this->post(route('api.v1.panel.properties.branding.update', $this->property), [
        'website_url' => 'https://harbor.example.com',
        'accommodation_nomenclature' => 'Room',
        'show_property_name' => '1',
        'theme_color' => '#f53003',
        'header_color' => '#1b1b18',
        'text_color' => '#ffffff',
        'phone_numbers' => ['+91 98765 43210'],
        'support_emails' => ['desk@example.com'],
        'remove_logo' => '0',
        'remove_banner_image' => '0',
        'logo' => UploadedFile::fake()->image('logo.png', 200, 80),
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('message', 'Branding saved.');
});

test('a room category gets a photo, beds and amenities; the property its amenities and holidays', function () {
    Storage::fake('public');
    $category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    $base = "/api/v1/panel/properties/{$this->property->id}/room-categories/{$category->id}";
    $photos = $category->images()->count();

    $this->post("{$base}/images", ['image' => UploadedFile::fake()->image('room.jpg', 800, 600)], ['Accept' => 'application/json'])->assertOk();
    expect($category->images()->count())->toBe($photos + 1);

    $this->putJson("{$base}/bed-types", ['items' => [['name' => 'Double Bed', 'enabled' => true, 'quantity' => 2], ['name' => 'Single Bed', 'enabled' => false, 'quantity' => null]]])->assertOk();
    expect($category->bedTypes()->where('type', 'bed')->pluck('quantity', 'name')->all())->toBe(['Double Bed' => 2]);

    $this->putJson("{$base}/amenities", ['amenity_ids' => []])->assertOk();
    $this->putJson(route('api.v1.panel.properties.amenities.update', $this->property), ['amenity_ids' => []])->assertOk();

    $this->putJson(route('api.v1.panel.properties.holidays.update', $this->property), [
        'holidays' => [['name' => 'Diwali', 'type' => 'festival', 'description' => '', 'from' => '2026-11-08', 'to' => '2026-11-09', 'day_wise' => false, 'days' => []]],
    ])->assertOk();
});
