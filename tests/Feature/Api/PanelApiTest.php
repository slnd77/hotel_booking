<?php

use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\User;
use App\Services\ApiScopes;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->property = Property::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->assignRole(Role::findOrCreate('admin', 'web'));
    $this->token = $this->admin->createToken(ApiScopes::DEVICE_PREFIX.'Pixel', ['*'])->plainTextToken;
});

test('a panel page answers its props as JSON', function () {
    $this->withToken($this->token)->getJson(route('api.v1.panel.legalSettings.edit', $this->property))
        ->assertOk()
        ->assertJsonPath('component', 'admin/legal-settings/Edit')
        ->assertJsonPath('props.property.id', $this->property->id)
        ->assertJsonStructure(['props' => ['settings', 'publicUrls', 'gstChecks']]);
});

test('pages that load roles work through the panel', function () {
    $this->withToken($this->token)->getJson(route('api.v1.panel.roles.index'))
        ->assertOk()
        ->assertJsonPath('component', 'admin/roles/Index');
});

test('a panel action answers its flash message, and its validation errors with 422', function () {
    $url = route('api.v1.panel.legalSettings.update', $this->property);

    $this->withToken($this->token)->putJson($url, ['privacy_policy' => 'P'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['terms', 'grievance_officer_email']);

    $this->withToken($this->token)->putJson($url, ['privacy_policy' => 'Our policy', 'terms' => 'Our terms', 'grievance_officer_email' => 'dpo@example.com'])
        ->assertOk()
        ->assertExactJson(['message' => 'Privacy & terms saved.']);
});

test('the panel checks the role\'s permissions as the web does', function () {
    $clerk = User::factory()->create();
    $role = Role::findOrCreate('Front Office', 'web');
    $role->givePermissionTo(Permission::findOrCreate('front_office.access', 'web'));
    $clerk->assignRole($role);
    $token = $clerk->createToken(ApiScopes::DEVICE_PREFIX.'Phone', ['*'])->plainTextToken;

    $this->withToken($token)->getJson(route('api.v1.panel.legalSettings.edit', $this->property))->assertForbidden();
    $this->withToken($token)->getJson(route('api.v1.panel.frontDesk.show', $this->property))->assertOk()->assertJsonPath('component', 'admin/front-desk/Show');
});

test('a key without the panel scope can\'t use the panel', function () {
    $key = $this->admin->createToken(ApiScopes::KEY_PREFIX.'Sync', ['bookings:read'])->plainTextToken;

    $this->withToken($key)->getJson(route('api.v1.panel.legalSettings.edit', $this->property))->assertForbidden();
    app('auth')->forgetGuards();
    $this->flushHeaders()->getJson(route('api.v1.panel.legalSettings.edit', $this->property))->assertUnauthorized();
});

test('the web panel still answers with pages and redirects', function () {
    $this->actingAs($this->admin)->get(route('admin.legalSettings.edit', $this->property))->assertOk();
    expect(route('admin.legalSettings.edit', $this->property))->toEndWith("/admin/legal-settings/{$this->property->id}");
});

test('/me tells the app what the plan includes and which package pages to list', function () {
    $this->withToken($this->token)->getJson(route('api.v1.me'))
        ->assertOk()
        ->assertJsonPath('data.scopes', fn (array $scopes): bool => in_array('panel', $scopes, true))
        ->assertJsonStructure(['data' => ['plan_features', 'package_menus']]);
});
