<?php

use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->first = Property::factory()->create(['name' => 'Alpha Hotel']);
    $this->other = Property::factory()->create(['name' => 'Zulu Hotel']);
});

test('the header picker shows the first property, then the ?property= one, which later pages remember', function () {
    $this->actingAs($this->admin)->get(route('admin.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('currentProperty', ['id' => $this->first->id, 'scoped' => false])
            ->where('selectedPropertyId', $this->first->id));

    $this->actingAs($this->admin)->get(route('admin.dashboard', ['property' => $this->other->id]))
        ->assertInertia(fn ($page) => $page
            ->where('currentProperty', ['id' => $this->other->id, 'scoped' => true])
            ->where('selectedPropertyId', $this->other->id));

    // A page about no property in particular, and the dashboard without ?property=, keep it.
    $this->actingAs($this->admin)->get(route('admin.users.index'))
        ->assertInertia(fn ($page) => $page->where('currentProperty', ['id' => $this->other->id, 'scoped' => false]));
    $this->actingAs($this->admin)->get(route('admin.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('currentProperty', ['id' => $this->other->id, 'scoped' => false])
            ->where('selectedPropertyId', $this->other->id));
});

test('a page about one property pins the picker to it, and an unknown ?property= falls back', function () {
    $this->actingAs($this->admin)->get(route('admin.rateSettings.edit', $this->other))
        ->assertInertia(fn ($page) => $page->where('currentProperty', ['id' => $this->other->id, 'scoped' => true]));

    $this->actingAs($this->admin)->get(route('admin.dashboard', ['property' => 999999]))
        ->assertInertia(fn ($page) => $page
            ->where('currentProperty', ['id' => $this->other->id, 'scoped' => false])
            ->where('selectedPropertyId', $this->other->id));
});
