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
    $this->property = Property::factory()->create();
});

test("a table's column choices are saved to the user and come back on every page", function () {
    $this->actingAs($this->admin)
        ->putJson(route('admin.listViews.update', 'payments-column-visibility'), ['columns' => ['guest_name' => false, 'payment_mode' => true]])
        ->assertOk();

    expect($this->admin->refresh()->list_views)->toBe(['payments-column-visibility' => ['guest_name' => false, 'payment_mode' => true]]);

    $this->actingAs($this->admin)
        ->get("/admin/databases/payments?property={$this->property->id}")
        ->assertInertia(fn ($page) => $page->where('listViews.payments-column-visibility.guest_name', false));

    // Another table's choices sit beside it; the user's own only.
    $this->actingAs($this->admin)->putJson(route('admin.listViews.update', 'folios-column-visibility'), ['columns' => ['status' => false]])->assertOk();
    expect($this->admin->refresh()->list_views)->toHaveKeys(['payments-column-visibility', 'folios-column-visibility'])
        ->and(User::factory()->create()->list_views)->toBeNull();
});

test('a bad table key or a non-boolean choice is refused', function () {
    $this->actingAs($this->admin)->putJson(route('admin.listViews.update', 'Bad Key!'), ['columns' => []])->assertNotFound();
    $this->actingAs($this->admin)->putJson(route('admin.listViews.update', 'payments'), ['columns' => ['guest_name' => 'maybe']])->assertUnprocessable();

    expect($this->admin->refresh()->list_views)->toBeNull();
});
