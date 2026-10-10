<?php

use App\Enums\TwoFactorMethod;
use App\Mail\TwoFactorCodeMail;
use App\Models\User;
use App\Services\TextMessages;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Password confirmation reads the real clock (time()), not the pinned test clock.
    $this->travelBack();

    $this->user = User::factory()->create([
        'email' => 'asha@example.com',
        'password' => Hash::make('secret-password-1'),
        'mobile_country_code' => '+91',
        'mobile' => '9812345678',
    ]);
    $this->confirmed = ['auth.password_confirmed_at' => time()];
});

/**
 * Captures SMS and WhatsApp messages instead of sending them.
 */
function fakeTextMessages(): TextMessages
{
    $fake = new class extends TextMessages
    {
        /** @var list<array{channel: TwoFactorMethod, to: string, message: string}> */
        public array $sent = [];

        public function __construct() {}

        public function send(TwoFactorMethod $channel, string $to, string $message): void
        {
            $this->sent[] = ['channel' => $channel, 'to' => $to, 'message' => $message];
        }
    };
    app()->instance(TextMessages::class, $fake);

    return $fake;
}

function sentCode(TextMessages $messages): string
{
    preg_match('/\d{6}/', end($messages->sent)['message'], $match);

    return $match[0];
}

function lastMailedCode(): string
{
    $code = null;
    Mail::assertSent(TwoFactorCodeMail::class, function (TwoFactorCodeMail $mail) use (&$code): bool {
        $code = $mail->code;

        return true;
    });

    return (string) $code;
}

test('admins give users a mobile number, shown in the users list', function () {
    Role::firstOrCreate(['name' => 'admin']);
    Role::firstOrCreate(['name' => 'user']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->post(route('admin.users.store'), ['name' => 'Desk', 'email' => 'desk@example.com', 'mobile_country_code' => '+44', 'mobile' => '7700 900123', 'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'role' => 'user', 'status' => 'active'])
        ->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'desk@example.com')->sole();
    expect($user->mobile_country_code)->toBe('+44')
        ->and($user->mobile)->toBe('7700900123')
        ->and($user->mobileNumber())->toBe('+447700900123');

    $this->actingAs($admin)->get(route('admin.users.index'))
        ->assertInertia(fn ($page) => $page->where('users.data', fn ($users): bool => collect($users)->firstWhere('email', 'desk@example.com')['mobile'] === '7700900123'));

    $this->actingAs($admin)
        ->post(route('admin.users.store'), ['name' => 'Bad', 'email' => 'bad@example.com', 'mobile_country_code' => '+44', 'mobile' => 'call me', 'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'role' => 'user', 'status' => 'active'])
        ->assertSessionHasErrors('mobile');
});

test('a user turns e-mail codes on by entering the code sent to them', function () {
    Mail::fake();

    $this->actingAs($this->user)->withSession($this->confirmed)
        ->postJson(route('profile.twoFactor.channels.code', 'email'))
        ->assertOk()
        ->assertJsonPath('message', 'We sent a code to a***@example.com.');

    $this->actingAs($this->user)->withSession($this->confirmed)
        ->post(route('profile.twoFactor.channels.enable', 'email'), ['code' => '000000'])
        ->assertSessionHasErrors('code');

    $this->actingAs($this->user)->withSession($this->confirmed)
        ->post(route('profile.twoFactor.channels.enable', 'email'), ['code' => lastMailedCode()])
        ->assertSessionHasNoErrors();

    $user = $this->user->fresh();
    expect($user->twoFactorMethods())->toBe([TwoFactorMethod::Email])
        ->and($user->recoveryCodes())->toHaveCount(8);

    $this->actingAs($this->user)->withSession($this->confirmed)
        ->delete(route('profile.twoFactor.channels.disable', 'email'));
    expect($this->user->fresh()->twoFactorMethods())->toBe([]);
});

test('SMS and WhatsApp codes need a mobile number and stop when it changes', function () {
    $texts = fakeTextMessages();

    $this->actingAs($this->user)->withSession($this->confirmed)->postJson(route('profile.twoFactor.channels.code', 'whatsapp'))->assertOk();
    expect($texts->sent[0]['to'])->toBe('+919812345678')
        ->and($texts->sent[0]['channel'])->toBe(TwoFactorMethod::WhatsApp);

    $this->actingAs($this->user)->withSession($this->confirmed)
        ->post(route('profile.twoFactor.channels.enable', 'whatsapp'), ['code' => sentCode($texts)])
        ->assertSessionHasNoErrors();
    expect($this->user->fresh()->hasTwoFactorMethod(TwoFactorMethod::WhatsApp))->toBeTrue();

    $this->actingAs($this->user)->put('/user/profile-information', ['name' => 'Asha', 'mobile_country_code' => '+91', 'mobile' => '9800000000'])->assertSessionHasNoErrors();
    expect($this->user->fresh()->hasTwoFactorMethod(TwoFactorMethod::WhatsApp))->toBeFalse()
        ->and($this->user->fresh()->mobile)->toBe('9800000000');

    $this->user->update(['mobile' => null, 'mobile_country_code' => null]);
    $this->actingAs($this->user->fresh())->withSession($this->confirmed)
        ->postJson(route('profile.twoFactor.channels.code', 'sms'))
        ->assertJsonValidationErrors('code');
});

test('the profile page lists every method and its state', function () {
    $this->user->forceFill(['two_factor_channels' => ['sms']])->save();

    $this->actingAs($this->user)->withSession($this->confirmed)
        ->get(route('profile.twoFactor'))
        ->assertInertia(fn ($page) => $page->component('profile/TwoFactor')
            ->where('hasTwoFactor', true)
            ->where('channels.1', ['value' => 'sms', 'label' => 'SMS OTP', 'enabled' => true, 'available' => true, 'destination' => '+91 ******5678'])
            ->where('channels.2.enabled', false)
            ->where('passkeys', []));
});

test('signing in with codes on asks for one, offering each method the user has', function () {
    Mail::fake();
    $this->user->forceFill(['two_factor_channels' => ['email', 'sms']])->save();

    $this->post('/login', ['email' => 'asha@example.com', 'password' => 'secret-password-1'])->assertRedirect(route('two-factor.login'));
    $this->assertGuest();

    $this->get(route('two-factor.login'))->assertInertia(fn ($page) => $page->component('auth/TwoFactorChallenge')
        ->where('methods.0.value', 'sms')
        ->where('methods.1', ['value' => 'email', 'label' => 'E-mail OTP', 'destination' => 'a***@example.com']));

    // WhatsApp isn't turned on for this user.
    $this->postJson(route('two-factor.code.send'), ['method' => 'whatsapp'])->assertJsonValidationErrors('method');

    $this->postJson(route('two-factor.code.send'), ['method' => 'email'])->assertOk();
    $this->post(route('two-factor.code.verify'), ['method' => 'email', 'code' => '000000'])->assertSessionHasErrors('code');
    $this->assertGuest();

    $this->post(route('two-factor.code.verify'), ['method' => 'email', 'code' => lastMailedCode()])->assertRedirect();
    $this->assertAuthenticatedAs($this->user);
});

test('a sent code stops working after too many wrong tries', function () {
    $texts = fakeTextMessages();
    $this->user->forceFill(['two_factor_channels' => ['sms']])->save();
    $this->post('/login', ['email' => 'asha@example.com', 'password' => 'secret-password-1']);
    $this->postJson(route('two-factor.code.send'), ['method' => 'sms'])->assertOk();
    $code = sentCode($texts);

    foreach (range(1, 5) as $attempt) {
        $this->withoutMiddleware(ThrottleRequests::class)
            ->post(route('two-factor.code.verify'), ['method' => 'sms', 'code' => '000000']);
    }

    $this->withoutMiddleware(ThrottleRequests::class)
        ->post(route('two-factor.code.verify'), ['method' => 'sms', 'code' => $code])
        ->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('a user with only a passkey is challenged and offered passkey options for their own keys', function () {
    $this->user->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'AQIDBA', 'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000']]);

    $this->post('/login', ['email' => 'asha@example.com', 'password' => 'secret-password-1'])->assertRedirect(route('two-factor.login'));

    $this->getJson(route('two-factor.passkey.options'))
        ->assertOk()
        ->assertJsonPath('options.allowCredentials.0.id', 'AQIDBA');

    // A made-up credential is refused.
    $this->postJson(route('two-factor.passkey.verify'), ['credential' => ['id' => 'x', 'rawId' => 'x', 'type' => 'public-key', 'response' => ['clientDataJSON' => 'x']]])
        ->assertJsonValidationErrors('credential');
    $this->assertGuest();
});

test('recovery codes sign in a user whose only method is a code channel', function () {
    $this->user->forceFill(['two_factor_channels' => ['email']])->save();
    app(GenerateNewRecoveryCodes::class)($this->user);
    $recoveryCode = $this->user->fresh()->recoveryCodes()[0];

    $this->post('/login', ['email' => 'asha@example.com', 'password' => 'secret-password-1']);
    $this->post('/two-factor-challenge', ['recovery_code' => $recoveryCode])->assertRedirect();

    $this->assertAuthenticatedAs($this->user);
});

test('the Twilio driver sends SMS and WhatsApp messages', function () {
    config(['services.text_messages.driver' => 'twilio', 'services.twilio' => ['sid' => 'AC123', 'token' => 'tok', 'sms_from' => '+15550001', 'whatsapp_from' => '+15550002']]);
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

    app(TextMessages::class)->send(TwoFactorMethod::WhatsApp, '+919812345678', 'Hello');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json'
        && $request['To'] === 'whatsapp:+919812345678'
        && $request['From'] === 'whatsapp:+15550002'
        && $request['Body'] === 'Hello');
});

test('users remove only their own passkeys', function () {
    $own = $this->user->passkeys()->create(['name' => 'Laptop', 'credential_id' => 'AQID', 'credential' => []]);
    $other = User::factory()->create()->passkeys()->create(['name' => 'Phone', 'credential_id' => 'BAUG', 'credential' => []]);

    $this->actingAs($this->user)->withSession($this->confirmed)
        ->delete(route('profile.passkeys.destroy', $other))
        ->assertForbidden();
    $this->actingAs($this->user)->withSession($this->confirmed)
        ->delete(route('profile.passkeys.destroy', $own))
        ->assertRedirect();

    expect($own->fresh())->toBeNull()
        ->and($other->fresh())->not->toBeNull();
});
