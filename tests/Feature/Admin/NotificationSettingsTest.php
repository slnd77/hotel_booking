<?php

use App\Models\ApplicationSetting;
use App\Models\Currency;
use App\Models\Language;
use App\Models\User;
use App\Services\ApplicationSettings;
use Illuminate\Support\Facades\Crypt;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function smtpPayload(array $overrides = []): array
{
    return [
        'host' => 'smtp.example.com',
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'mailer',
        'password' => 'smtp-secret',
        'from_address' => 'reservations@example.com',
        'from_name' => 'Front Desk',
        ...$overrides,
    ];
}

test('the notification settings tab lists e-mail, sms and whatsapp, off by default', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.applicationSettings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('notificationChannels.0.key', 'email')
            ->where('notificationChannels.0.supported', true)
            ->where('notificationChannels.0.enabled', false)
            ->where('notificationChannels.0.configured', false)
            ->where('notificationChannels.1.key', 'sms')
            ->where('notificationChannels.1.supported', false)
            ->where('notificationChannels.2.key', 'whatsapp')
            ->where('notificationChannels.2.supported', false));
});

test('smtp settings are saved with an encrypted password that is never sent to the page', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.notificationChannels.email.update'), smtpPayload())
        ->assertSessionHasNoErrors();

    $stored = ApplicationSetting::query()->where('key', ApplicationSettings::NOTIFICATION)->value('value');

    expect($stored['email']['smtp']['password'])->not->toBe('smtp-secret')
        ->and(Crypt::decryptString($stored['email']['smtp']['password']))->toBe('smtp-secret');

    $this->actingAs($this->admin)
        ->get(route('admin.applicationSettings.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('notificationChannels.0.configured', true)
            ->where('notificationChannels.0.smtp.host', 'smtp.example.com')
            ->where('notificationChannels.0.smtp.has_password', true)
            ->missing('notificationChannels.0.smtp.password'));

    // A blank password keeps the saved one.
    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.notificationChannels.email.update'), smtpPayload(['password' => '', 'port' => 2525]))
        ->assertSessionHasNoErrors();

    $stored = ApplicationSetting::query()->where('key', ApplicationSettings::NOTIFICATION)->value('value');
    expect(Crypt::decryptString($stored['email']['smtp']['password']))->toBe('smtp-secret')
        ->and($stored['email']['smtp']['port'])->toBe(2525);
});

test('smtp settings need a host, a valid port and a from address', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.notificationChannels.email.update'), smtpPayload(['host' => '', 'port' => 70000, 'from_address' => 'not-an-email']))
        ->assertSessionHasErrors(['host', 'port', 'from_address']);
});

test('e-mail can only be switched on once smtp is configured, and unsupported channels cannot be switched on', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.notificationChannels.status', 'email'), ['enabled' => true])
        ->assertSessionHasErrors('enabled');

    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.notificationChannels.status', 'sms'), ['enabled' => true])
        ->assertSessionHasErrors('enabled');

    $this->actingAs($this->admin)->put(route('admin.applicationSettings.notificationChannels.email.update'), smtpPayload());

    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.notificationChannels.status', 'email'), ['enabled' => true])
        ->assertSessionHasNoErrors();

    expect(app(ApplicationSettings::class)->notificationChannels()[0]['enabled'])->toBeTrue();
});

test('application mail goes through the saved smtp server while e-mail is switched on', function () {
    $this->actingAs($this->admin)->put(route('admin.applicationSettings.notificationChannels.email.update'), smtpPayload());

    app()->forgetInstance('mail.manager');
    app('mail.manager');
    expect(config('mail.default'))->not->toBe(ApplicationSettings::NOTIFICATION_MAILER);

    $this->actingAs($this->admin)->put(route('admin.applicationSettings.notificationChannels.status', 'email'), ['enabled' => true]);

    app()->forgetInstance('mail.manager');
    app('mail.manager');

    expect(config('mail.default'))->toBe(ApplicationSettings::NOTIFICATION_MAILER)
        ->and(config('mail.mailers.'.ApplicationSettings::NOTIFICATION_MAILER.'.host'))->toBe('smtp.example.com')
        ->and(config('mail.mailers.'.ApplicationSettings::NOTIFICATION_MAILER.'.password'))->toBe('smtp-secret')
        ->and(config('mail.from.address'))->toBe('reservations@example.com');
});

test('a queued job reads the e-mail settings afresh, back to the .env mailer once e-mail is switched off', function () {
    $this->actingAs($this->admin)->put(route('admin.applicationSettings.notificationChannels.email.update'), smtpPayload());
    $this->actingAs($this->admin)->put(route('admin.applicationSettings.notificationChannels.status', 'email'), ['enabled' => true]);
    app()->forgetInstance('mail.manager');
    app('mail.manager');
    expect(config('mail.default'))->toBe(ApplicationSettings::NOTIFICATION_MAILER);

    $this->actingAs($this->admin)->put(route('admin.applicationSettings.notificationChannels.status', 'email'), ['enabled' => false]);

    // The worker starts its next job: the mailer it built for the previous one is dropped.
    dispatch(fn () => app('mail.manager'));

    expect(config('mail.default'))->toBe('array')
        ->and(config('mail.from.address'))->not->toBe('reservations@example.com');
});

test('guest e-mails are switched on by default and saved from the notification settings', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.applicationSettings.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('guestEmails.0.key', 'booking_confirmation')
            ->where('guestEmails.0.enabled', true)
            ->where('guestEmails.2.key', 'payment_receipt'));

    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.guestEmails.update'), ['booking_confirmation' => true, 'booking_cancellation' => false, 'payment_receipt' => true])
        ->assertSessionHas('success');

    expect(app(ApplicationSettings::class)->sendsGuestEmail('booking_cancellation'))->toBeFalse()
        ->and(app(ApplicationSettings::class)->logs(ApplicationSettings::NOTIFICATION)->first()['description'] ?? null)->toContain('Booking cancellation off');
});
