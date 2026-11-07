<?php

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'user']);
    config(['services.google.client_id' => 'test-client', 'services.google.client_secret' => 'test-secret', 'services.google.redirect' => null]);
});

/**
 * Google answers the code exchange with this account.
 *
 * @param  array<string, mixed>  $profile
 */
function fakeGoogleAccount(array $profile = []): void
{
    // A fresh client each time: stubs added by an earlier fake would otherwise answer first.
    Http::swap(new Factory);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'token-123', 'token_type' => 'Bearer']),
        'openidconnect.googleapis.com/v1/userinfo' => Http::response(['sub' => 'google-1', 'email' => 'Asha@Example.com', 'email_verified' => true, 'name' => 'Asha Rao', ...$profile]),
    ]);
}

/**
 * Starts the sign-in and returns the state Google would echo back.
 */
function startGoogleSignIn(string $intent = 'login'): string
{
    $location = test()->get(route('auth.google.redirect', ['intent' => $intent]))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth')
        ->and($query['client_id'])->toBe('test-client')
        ->and($query['redirect_uri'])->toBe(route('auth.google.callback'));

    return $query['state'];
}

test('the log-in and create-account pages offer Google only when it is configured', function () {
    $this->get(route('login'))->assertInertia(fn ($page) => $page->component('auth/Login')->where('googleSignIn', true));
    $this->get(route('register'))->assertInertia(fn ($page) => $page->component('auth/Register')->where('googleSignIn', true));

    config(['services.google.client_id' => null]);

    $this->get(route('login'))->assertInertia(fn ($page) => $page->where('googleSignIn', false));
    $this->get(route('auth.google.redirect'))->assertNotFound();
});

test('an existing user signs in with Google, which links the Google account', function () {
    $user = User::factory()->create(['email' => 'asha@example.com']);
    fakeGoogleAccount();

    $state = startGoogleSignIn();
    $this->get(route('auth.google.callback', ['state' => $state, 'code' => 'abc']))->assertRedirect(config('fortify.home'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->google_id)->toBe('google-1');
});

test('the create-account page opens an account from Google', function () {
    fakeGoogleAccount();

    $state = startGoogleSignIn('register');
    $this->get(route('auth.google.callback', ['state' => $state, 'code' => 'abc']))->assertRedirect(config('fortify.home'));

    $user = User::query()->where('email', 'asha@example.com')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Asha Rao')
        ->and($user->google_id)->toBe('google-1')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->hasRole('user'))->toBeTrue();
});

test('the log-in page does not open accounts, and unverified Google e-mails or a wrong state are refused', function () {
    fakeGoogleAccount();
    $state = startGoogleSignIn();
    $this->get(route('auth.google.callback', ['state' => $state, 'code' => 'abc']))->assertRedirect(route('login'))->assertSessionHasErrors('google');
    expect(User::query()->count())->toBe(0);

    fakeGoogleAccount(['email_verified' => false]);
    $state = startGoogleSignIn('register');
    $this->get(route('auth.google.callback', ['state' => $state, 'code' => 'abc']))->assertSessionHasErrors('google');
    expect(User::query()->count())->toBe(0);

    startGoogleSignIn();
    $this->get(route('auth.google.callback', ['state' => 'forged', 'code' => 'abc']))->assertSessionHasErrors('google');
    $this->assertGuest();
});

test('an inactive user is kept out, and a user with a second factor goes to the challenge', function () {
    User::factory()->create(['email' => 'asha@example.com', 'status' => UserStatus::Inactive]);
    fakeGoogleAccount();

    $state = startGoogleSignIn();
    $this->get(route('auth.google.callback', ['state' => $state, 'code' => 'abc']))->assertSessionHasErrors('google');
    $this->assertGuest();

    $twoFactor = User::factory()->create(['email' => 'ravi@example.com']);
    $twoFactor->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    fakeGoogleAccount(['sub' => 'google-2', 'email' => 'ravi@example.com']);

    $state = startGoogleSignIn();
    $this->get(route('auth.google.callback', ['state' => $state, 'code' => 'abc']))->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect(session('login.id'))->toBe($twoFactor->id);
});
