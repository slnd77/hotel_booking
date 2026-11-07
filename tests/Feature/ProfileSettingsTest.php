<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    // Two-factor codes and password confirmation read the real clock (time()), not the pinned test clock.
    $this->travelBack();

    $this->user = User::factory()->create(['name' => 'Asha Rao', 'password' => Hash::make('old-password-123')]);
});

test('guests are sent to login from profile settings', function () {
    $this->get(route('profile.edit'))->assertRedirect(route('login'));
});

test('users can open profile settings and change their own name', function () {
    $this->actingAs($this->user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('profile/Edit')->where('user.name', 'Asha Rao'));

    $originalEmail = $this->user->email;

    $this->actingAs($this->user)
        ->from(route('profile.edit'))
        ->put('/user/profile-information', ['name' => 'Asha R.', 'email' => 'changed@example.com'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($this->user->fresh()->name)->toBe('Asha R.')
        ->and($this->user->fresh()->email)->toBe($originalEmail);
});

test('users can change their password only with the current password', function () {
    $this->actingAs($this->user)
        ->from(route('profile.edit'))
        ->put('/user/password', ['current_password' => 'wrong-password', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456'])
        ->assertSessionHasErrorsIn('updatePassword', 'current_password');

    $this->actingAs($this->user)
        ->from(route('profile.edit'))
        ->put('/user/password', ['current_password' => 'old-password-123', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456'])
        ->assertSessionHasNoErrors();

    expect(Hash::check('new-password-456', $this->user->fresh()->password))->toBeTrue();
});

test('the two-factor page asks for the password first', function () {
    $this->actingAs($this->user)
        ->get(route('profile.twoFactor'))
        ->assertRedirect(route('password.confirm'));

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('profile.twoFactor'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('profile/TwoFactor')->where('twoFactorEnabled', false)->where('twoFactorConfirmed', false));
});

test('users can turn on two-factor authentication, which is then required at login', function () {
    $session = ['auth.password_confirmed_at' => time()];

    $this->actingAs($this->user)->withSession($session)->post('/user/two-factor-authentication')->assertSessionHasNoErrors();

    $secret = decrypt($this->user->fresh()->two_factor_secret);
    $code = app(Google2FA::class)->getCurrentOtp($secret);

    $this->actingAs($this->user)->withSession($session)
        ->post('/user/confirmed-two-factor-authentication', ['code' => $code])
        ->assertSessionHasNoErrors();

    expect($this->user->fresh()->two_factor_confirmed_at)->not->toBeNull();

    auth()->logout();

    $this->post('/login', ['email' => $this->user->email, 'password' => 'old-password-123'])
        ->assertRedirect(route('two-factor.login'));
    $this->assertGuest();

    $this->get(route('two-factor.login'))->assertInertia(fn ($page) => $page->component('auth/TwoFactorChallenge'));

    // The setup code can't be replayed, so finish the login with a one-time recovery code.
    $this->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();

    $recoveryCode = $this->user->fresh()->recoveryCodes()[0];

    $this->post('/two-factor-challenge', ['recovery_code' => $recoveryCode])->assertRedirect();
    $this->assertAuthenticatedAs($this->user->fresh());
    expect($this->user->fresh()->recoveryCodes())->not->toContain($recoveryCode);
});

test('users can turn two-factor authentication off', function () {
    $session = ['auth.password_confirmed_at' => time()];
    $this->actingAs($this->user)->withSession($session)->post('/user/two-factor-authentication');

    $this->actingAs($this->user)->withSession($session)->delete('/user/two-factor-authentication')->assertSessionHasNoErrors();

    expect($this->user->fresh()->two_factor_secret)->toBeNull();
});
