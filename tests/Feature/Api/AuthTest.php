<?php

use App\Enums\UserStatus;
use App\Mail\TwoFactorCodeMail;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\User;
use App\Services\ApiScopes;
use App\Services\PermissionCatalog;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Sanctum\TransientToken;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'desk@example.com', 'password' => Hash::make('secret-password-1')]);
    $this->user->assignRole(Role::findOrCreate(PermissionCatalog::ACCOUNT_ADMIN, 'web'));
});

function apiLogin(array $overrides = []): TestResponse
{
    return test()->postJson(route('api.v1.auth.login'), [...['email' => 'desk@example.com', 'password' => 'secret-password-1', 'device_name' => 'Pixel 9'], ...$overrides]);
}

test('a staff user signs in and gets a device token for the API', function () {
    $response = apiLogin()->assertOk()->assertJsonPath('token_type', 'Bearer')->assertJsonPath('user.email', 'desk@example.com');
    $token = $response->json('token');

    expect($token)->toMatch('/^\d+\|hb_/')
        ->and($this->user->tokens()->sole()->name)->toBe('app:Pixel 9');

    $this->withToken($token)->getJson(route('api.v1.me'))
        ->assertOk()
        ->assertJsonPath('data.roles', [PermissionCatalog::ACCOUNT_ADMIN])
        // Every scope the role allows: Account Admin can't view the Payments database.
        ->assertJsonPath('data.scopes', array_values(array_diff(ApiScopes::names(), ['payments:read'])));
});

test('wrong passwords, inactive users and users without panel access are refused', function () {
    apiLogin(['password' => 'nope'])->assertJsonValidationErrors('email');

    $this->user->update(['status' => UserStatus::Inactive]);
    apiLogin()->assertForbidden();

    $plain = User::factory()->create(['email' => 'plain@example.com', 'password' => Hash::make('secret-password-1')]);
    $plain->assignRole(Role::findOrCreate(PermissionCatalog::USER, 'web'));
    apiLogin(['email' => 'plain@example.com'])->assertForbidden();
});

test('the API needs a token, and a deactivated user\'s token stops working', function () {
    $this->getJson(route('api.v1.me'))->assertUnauthorized();

    $token = apiLogin()->json('token');
    $this->user->update(['status' => UserStatus::Inactive]);

    $this->withToken($token)->getJson(route('api.v1.me'))->assertForbidden();
});

test('signing out revokes the device token', function () {
    $token = apiLogin()->json('token');

    $this->withToken($token)->postJson(route('api.v1.auth.logout'))->assertOk();

    expect($this->user->tokens()->count())->toBe(0);
});

test('signing out of a browser session (no API token) succeeds and revokes nothing', function () {
    $token = apiLogin()->json('token');
    expect($this->user->tokens()->count())->toBe(1);

    // Sanctum's stateful (session) sign-in gives the user a TransientToken, which can't be deleted.
    $this->actingAs($this->user->withAccessToken(new TransientToken), 'sanctum')
        ->postJson(route('api.v1.auth.logout'))
        ->assertOk();

    expect($this->user->tokens()->count())->toBe(1)
        ->and($token)->not->toBeNull();
});

test('users with two-factor on trade a challenge token and a code for the device token', function () {
    Mail::fake();
    $this->user->forceFill(['two_factor_channels' => ['email']])->save();

    $login = apiLogin()->assertOk()->assertJsonPath('two_factor', true)->assertJsonPath('methods.0.value', 'email');
    $challenge = $login->json('challenge_token');
    expect($login->json('token'))->toBeNull();

    $this->postJson(route('api.v1.auth.twoFactor.code.send'), ['challenge_token' => $challenge, 'method' => 'email'])->assertOk();
    $code = null;
    Mail::assertSent(TwoFactorCodeMail::class, function (TwoFactorCodeMail $mail) use (&$code): bool {
        $code = $mail->code;

        return true;
    });

    $this->postJson(route('api.v1.auth.twoFactor.code.verify'), ['challenge_token' => $challenge, 'method' => 'email', 'code' => '000000'])->assertJsonValidationErrors('code');
    $this->postJson(route('api.v1.auth.twoFactor.code.verify'), ['challenge_token' => $challenge, 'method' => 'email', 'code' => $code])
        ->assertOk()
        ->assertJsonStructure(['token', 'user']);

    // The challenge is used up.
    $this->postJson(route('api.v1.auth.twoFactor.code.send'), ['challenge_token' => $challenge, 'method' => 'email'])->assertUnauthorized();
});

test('a challenge ends after too many wrong answers', function () {
    $this->user->forceFill(['two_factor_channels' => ['email']])->save();
    app(GenerateNewRecoveryCodes::class)($this->user);
    $challenge = apiLogin()->json('challenge_token');

    foreach (range(1, 5) as $attempt) {
        $this->withoutMiddleware(ThrottleRequests::class)
            ->postJson(route('api.v1.auth.twoFactor.recovery'), ['challenge_token' => $challenge, 'recovery_code' => 'wrong-'.$attempt]);
    }

    $this->withoutMiddleware(ThrottleRequests::class)
        ->postJson(route('api.v1.auth.twoFactor.recovery'), ['challenge_token' => $challenge, 'recovery_code' => $this->user->fresh()->recoveryCodes()[0]])
        ->assertUnauthorized();
});

test('a recovery code works once', function () {
    $this->user->forceFill(['two_factor_channels' => ['email']])->save();
    app(GenerateNewRecoveryCodes::class)($this->user);
    $recoveryCode = $this->user->fresh()->recoveryCodes()[0];

    $this->postJson(route('api.v1.auth.twoFactor.recovery'), ['challenge_token' => apiLogin()->json('challenge_token'), 'recovery_code' => $recoveryCode])->assertOk();
    $this->postJson(route('api.v1.auth.twoFactor.recovery'), ['challenge_token' => apiLogin()->json('challenge_token'), 'recovery_code' => $recoveryCode])->assertJsonValidationErrors('recovery_code');
});

test('the app lists the properties with their timezone', function () {
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);
    Property::factory()->create(['name' => 'Bhavan Hotel']);

    $this->withToken(apiLogin()->json('token'))->getJson(route('api.v1.properties.index'))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Bhavan Hotel')
        ->assertJsonStructure(['data' => [['id', 'name', 'abbreviation', 'timezone', 'today']]]);
});
