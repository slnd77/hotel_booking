<?php

use App\Models\PropertyGroups;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

test('property group index can be rendered', function () {
    PropertyGroups::factory()->count(3)->create();

    $response = $this->actingAs($this->admin)->get('/admin/property-groups');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('admin/property-groups/Index'));
});

test('a property group can be created', function () {
    $response = $this->actingAs($this->admin)->post('/admin/property-groups', [
        'name' => 'Coastal Resorts',
    ]);

    $response->assertRedirect('/admin/property-groups');
    $this->assertDatabaseHas('property_groups', ['name' => 'Coastal Resorts']);
});

test('creating a property group fails when the name is already taken', function () {
    PropertyGroups::factory()->create(['name' => 'Coastal Resorts']);

    $response = $this->actingAs($this->admin)
        ->from('/admin/property-groups/create')
        ->post('/admin/property-groups', ['name' => 'Coastal Resorts']);

    $response->assertRedirect('/admin/property-groups/create');
    $response->assertSessionHasErrors('name');
});

test('a property group can be updated', function () {
    $propertyGroup = PropertyGroups::factory()->create(['name' => 'Old Name']);

    $response = $this->actingAs($this->admin)->put("/admin/property-groups/{$propertyGroup->id}", [
        'name' => 'New Name',
    ]);

    $response->assertRedirect('/admin/property-groups');
    expect($propertyGroup->fresh()->name)->toBe('New Name');
});

test('a property group can be shown', function () {
    $propertyGroup = PropertyGroups::factory()->create();

    $response = $this->actingAs($this->admin)->get("/admin/property-groups/{$propertyGroup->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('admin/property-groups/Show'));
});
