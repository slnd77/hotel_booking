<?php

use App\Models\User;

test('it creates a new user with the admin role', function () {
    $this->artisan('admin:create', [
        'email' => 'new-admin@example.com',
        '--name' => 'New Admin',
        '--password' => 'password123',
    ])->assertSuccessful();

    $user = User::whereEmail('new-admin@example.com')->firstOrFail();

    expect($user->name)->toBe('New Admin');
    expect($user->hasRole('admin'))->toBeTrue();
});

test('it promotes an existing user to admin when confirmed', function () {
    $user = User::factory()->create(['email' => 'existing@example.com']);

    $this->artisan('admin:create', [
        'email' => 'existing@example.com',
        '--name' => 'Existing User',
        '--password' => 'password123',
    ])
        ->expectsConfirmation('A user with email [existing@example.com] already exists. Promote them to admin?', 'yes')
        ->assertSuccessful();

    expect($user->fresh()->hasRole('admin'))->toBeTrue();
});

test('it does not promote an existing user when declined', function () {
    $user = User::factory()->create(['email' => 'existing@example.com']);

    $this->artisan('admin:create', [
        'email' => 'existing@example.com',
    ])
        ->expectsConfirmation('A user with email [existing@example.com] already exists. Promote them to admin?', 'no')
        ->assertSuccessful();

    expect($user->fresh()->hasRole('admin'))->toBeFalse();
});

test('it rejects an invalid email', function () {
    $this->artisan('admin:create', [
        'email' => 'not-an-email',
    ])->assertFailed();
});
