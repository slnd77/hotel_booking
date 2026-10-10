<?php

use App\Http\Controllers\PasskeyRegistrationController;
use App\Mail\PasskeyChangedMail;
use App\Models\User;
use App\Services\ApiTwoFactorChallenges;
use App\Services\PasskeyChallenges;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Passkeys\Events\PasskeyRegistered;

beforeEach(function () {
    // Password confirmation reads the real clock (time()), not the pinned test clock.
    $this->travelBack();

    $this->user = User::factory()->create(['email' => 'asha@example.com', 'password' => Hash::make('secret-password-1')]);
    $this->confirmed = ['auth.password_confirmed_at' => time()];
});

/**
 * A made-up passkey answer: well-formed, so only the challenge decides whether it's looked at.
 *
 * @return array<string, mixed>
 */
function madeUpCredential(): array
{
    return ['id' => 'x', 'rawId' => 'x', 'type' => 'public-key', 'response' => ['clientDataJSON' => 'x', 'authenticatorData' => 'x', 'signature' => 'x']];
}

test('a user can hold only so many passkeys', function () {
    foreach (range(1, PasskeyRegistrationController::MAX_PASSKEYS) as $number) {
        $this->user->passkeys()->create(['name' => "Key {$number}", 'credential_id' => "KEY{$number}", 'credential' => []]);
    }

    $this->actingAs($this->user)->withSession($this->confirmed)
        ->getJson(route('profile.passkeys.options'))
        ->assertJsonValidationErrors(['name' => 'Remove one before adding another']);
});

test('passkey registration is rate limited per user', function () {
    foreach (range(1, 6) as $attempt) {
        $this->actingAs($this->user)->withSession($this->confirmed)->getJson(route('profile.passkeys.options'))->assertOk();
    }

    $this->actingAs($this->user)->withSession($this->confirmed)->getJson(route('profile.passkeys.options'))->assertTooManyRequests();
});

test('a passkey challenge is refused once its time is up, and answers only once', function () {
    $challenges = app(PasskeyChallenges::class);
    $session = app('session.store');

    $challenges->remember($session, 'challenge', 'options');
    expect($challenges->pull($session, 'challenge'))->toBe('options')
        ->and($challenges->pull($session, 'challenge'))->toBeNull();

    $challenges->remember($session, 'challenge', 'options');
    $this->travel(61 + PasskeyChallenges::GRACE_SECONDS)->seconds();

    expect($challenges->pull($session, 'challenge'))->toBeNull();
});

test('the app sign-in passkey challenge expires and answers only once', function () {
    $this->user->passkeys()->create(['name' => 'Phone', 'credential_id' => 'AQIDBA', 'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000']]);
    $token = app(ApiTwoFactorChallenges::class)->create($this->user, 'Pixel');

    $this->postJson(route('api.v1.auth.twoFactor.passkey.options'), ['challenge_token' => $token])->assertOk();
    $this->postJson(route('api.v1.auth.twoFactor.passkey.verify'), ['challenge_token' => $token, 'credential' => madeUpCredential()])
        ->assertJsonValidationErrors(['credential' => 'could not be verified']);

    // The same options can't be answered again.
    $this->postJson(route('api.v1.auth.twoFactor.passkey.verify'), ['challenge_token' => $token, 'credential' => madeUpCredential()])
        ->assertJsonValidationErrors(['credential' => 'expired or was already used']);

    // Fresh options, answered too late.
    $this->postJson(route('api.v1.auth.twoFactor.passkey.options'), ['challenge_token' => $token])->assertOk();
    $this->travel(61 + PasskeyChallenges::GRACE_SECONDS)->seconds();

    $this->postJson(route('api.v1.auth.twoFactor.passkey.verify'), ['challenge_token' => $token, 'credential' => madeUpCredential()])
        ->assertJsonValidationErrors(['credential' => 'expired or was already used']);
});

test('adding or removing a passkey e-mails the user an alert', function () {
    Mail::fake();
    $passkey = $this->user->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'AQID', 'credential' => []]);

    PasskeyRegistered::dispatch($this->user, $passkey);

    Mail::assertQueued(PasskeyChangedMail::class, fn (PasskeyChangedMail $mail): bool => $mail->hasTo('asha@example.com')
        && $mail->change === 'added'
        && $mail->passkeyName === 'Laptop');

    $this->actingAs($this->user)->withSession($this->confirmed)->delete(route('profile.passkeys.destroy', $passkey))->assertRedirect();

    Mail::assertQueued(PasskeyChangedMail::class, fn (PasskeyChangedMail $mail): bool => $mail->change === 'removed');
    Mail::assertQueuedCount(2);
});
