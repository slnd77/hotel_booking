<?php

use App\Models\Currency;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\User;
use App\Services\PropertySettings;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->property = Property::factory()->create();
});

test('the rate settings page offers the packages and packages calendar settings', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.rateSettings.edit', $this->property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/rate-settings/Edit')
            ->where('metaFields', fn ($fields): bool => collect($fields)->pluck('key')->contains('rate_settings_packages')
                && collect($fields)->pluck('key')->contains('rate_settings_packages_planner')));
});

test('packages and their calendar are saved with the property', function () {
    $this->actingAs($this->admin)->get(route('admin.rateSettings.edit', $this->property));
    $packages = MetaField::query()->where('key', 'rate_settings_packages')->sole();
    $planner = MetaField::query()->where('key', 'rate_settings_packages_planner')->sole();

    $package = ['id' => 1, 'name' => 'Honeymoon', 'color' => '#7c3aed', 'rooms' => ['5' => ['EP', 'CP']], 'length_of_stay_linked' => true, 'min_nights' => 2, 'max_nights' => 4];

    $this->actingAs($this->admin)
        ->put(route('admin.rateSettings.update', $this->property), ['meta_fields' => [
            $packages->id => ['enabled' => true, 'data' => json_encode(['packages' => [$package]])],
            $planner->id => ['enabled' => true, 'data' => json_encode(['assignments' => ['2026-12-24' => [1]]])],
        ]])
        ->assertRedirect(route('admin.rateSettings.edit', $this->property));

    $settings = app(PropertySettings::class);

    expect($settings->json($this->property, 'rate_settings_packages')['packages'][0])->toMatchArray(['name' => 'Honeymoon', 'rooms' => ['5' => ['EP', 'CP']]])
        ->and($settings->json($this->property, 'rate_settings_packages_planner')['assignments'])->toBe(['2026-12-24' => [1]]);
});
