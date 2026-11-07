<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Role::firstOrCreate(['name' => 'user']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

test('user index can be rendered', function () {
    User::factory()->count(3)->create();

    $response = $this->actingAs($this->admin)->get('/admin/users');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('admin/users/Index'));
});

test('a user can be created with a role', function () {
    $response = $this->actingAs($this->admin)->post('/admin/users', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'role' => 'admin',
        'status' => 'active',
    ]);

    $response->assertRedirect('/admin/users');
    $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);

    $created = User::whereEmail('jane@example.com')->firstOrFail();
    expect($created->password)->not->toBe('password');
    expect($created->hasRole('admin'))->toBeTrue();
});

test('creating a user fails with an invalid role', function () {
    $response = $this->actingAs($this->admin)->from('/admin/users/create')->post('/admin/users', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'password',
        'role' => 'superadmin',
        'status' => 'active',
    ]);

    $response->assertRedirect('/admin/users/create');
    $response->assertSessionHasErrors('role');
    $this->assertDatabaseMissing('users', ['email' => 'jane@example.com']);
});

test('a user can be updated without changing the password', function () {
    $user = User::factory()->create(['name' => 'Old Name']);
    $user->assignRole('user');
    $originalPassword = $user->password;

    $response = $this->actingAs($this->admin)->put("/admin/users/{$user->id}", [
        'name' => 'New Name',
        'email' => $user->email,
        'password' => '',
        'role' => 'user',
        'status' => 'active',
    ]);

    $response->assertRedirect('/admin/users');
    $user->refresh();

    expect($user->name)->toBe('New Name');
    expect($user->password)->toBe($originalPassword);
});

test('updating a user changes their role', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $response = $this->actingAs($this->admin)->put("/admin/users/{$user->id}", [
        'name' => $user->name,
        'email' => $user->email,
        'password' => '',
        'role' => 'admin',
        'status' => 'active',
    ]);

    $response->assertRedirect('/admin/users');
    $user->refresh();

    expect($user->hasRole('admin'))->toBeTrue();
    expect($user->hasRole('user'))->toBeFalse();
});

test('a user can be deleted', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($this->admin)->delete("/admin/users/{$user->id}");

    $response->assertRedirect();
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});

test('an admin cannot delete their own account', function () {
    $response = $this->actingAs($this->admin)->delete("/admin/users/{$this->admin->id}");

    $response->assertRedirect();
    $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
});

test('guests cannot access user management', function () {
    $user = User::factory()->create();

    $this->get('/admin/users')->assertRedirect('/login');
    $this->post('/admin/users', [])->assertRedirect('/login');
    $this->delete("/admin/users/{$user->id}")->assertRedirect('/login');
});
