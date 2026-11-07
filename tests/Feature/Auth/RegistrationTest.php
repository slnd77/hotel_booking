<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'user']);
});

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/dashboard');
    $this->assertDatabaseHas('users', ['email' => 'test@example.com']);

    $user = User::whereEmail('test@example.com')->firstOrFail();
    expect($user->hasRole('user'))->toBeTrue();
});

test('registration fails with a duplicate email', function () {
    User::factory()->create(['email' => 'test@example.com']);

    $response = $this->from('/register')->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect('/register');
    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});
