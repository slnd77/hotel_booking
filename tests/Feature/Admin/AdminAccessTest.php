<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

test('guests are redirected to login', function () {
    $response = $this->get('/admin');

    $response->assertRedirect('/login');
});

test('non-admin authenticated users cannot view the admin panel', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/admin');

    $response->assertForbidden();
});

test('admins can view the admin panel', function () {
    Role::firstOrCreate(['name' => 'admin']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $response = $this->actingAs($admin)->get('/admin');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('admin/Dashboard'));
});
