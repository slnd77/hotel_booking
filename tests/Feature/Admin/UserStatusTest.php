<?php

use App\Enums\UserStatus;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Role::firstOrCreate(['name' => 'user']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

test('the users list shows each user\'s status', function () {
    User::factory()->inactive()->create(['name' => 'Gone Away']);

    $this->actingAs($this->admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('users.data', fn ($users): bool => collect($users)->pluck('status', 'name')->get('Gone Away') === 'inactive'
            && collect($users)->firstWhere('id', $this->admin->id)['status'] === 'active'));
});

test('the user forms offer the statuses and save the chosen one', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.users.create'))
        ->assertInertia(fn ($page) => $page->where('statusOptions', UserStatus::options()));

    $this->actingAs($this->admin)
        ->post(route('admin.users.store'), ['name' => 'Desk', 'email' => 'desk@example.com', 'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'role' => 'user', 'status' => 'inactive'])
        ->assertSessionHasNoErrors();
    $user = User::query()->where('email', 'desk@example.com')->sole();
    expect($user->status)->toBe(UserStatus::Inactive);

    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $user), ['name' => 'Desk', 'email' => 'desk@example.com', 'role' => 'user', 'status' => 'active'])
        ->assertSessionHasNoErrors();
    expect($user->fresh()->status)->toBe(UserStatus::Active);

    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $user), ['name' => 'Desk', 'email' => 'desk@example.com', 'role' => 'user', 'status' => 'asleep'])
        ->assertSessionHasErrors('status');
});

test('users can\'t make themselves inactive', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.users.update', $this->admin), ['name' => 'Me', 'email' => $this->admin->email, 'role' => 'admin', 'status' => 'inactive'])
        ->assertSessionHasErrors(['status' => 'You can\'t make your own user inactive.']);

    expect($this->admin->fresh()->isActive())->toBeTrue();
});

test('an inactive user can\'t sign in', function () {
    $user = User::factory()->inactive()->create(['email' => 'off@example.com']);
    $user->assignRole('admin');

    $this->post('/login', ['email' => 'off@example.com', 'password' => 'password'])->assertRedirect();
    $this->get(route('admin.dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => EnsureUserIsActive::MESSAGE]);

    $this->assertGuest();
});

test('a user made inactive is signed out on their next request', function () {
    $this->actingAs($this->admin)->get(route('admin.users.index'))->assertOk();

    $this->admin->update(['status' => UserStatus::Inactive]);

    $this->get(route('admin.users.index'))->assertRedirect(route('login'));
    $this->assertGuest();
});
