<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ApplicationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Application Settings → Notification Settings: switch channels on or off and
 * configure the e-mail channel's SMTP server. SMS and WhatsApp aren't supported yet.
 */
class NotificationChannelController extends Controller
{
    public function updateStatus(Request $request, string $channel, ApplicationSettings $settings): RedirectResponse
    {
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $channelDetails = ApplicationSettings::NOTIFICATION_CHANNELS[$channel];

        if (! $channelDetails['supported']) {
            throw ValidationException::withMessages(['enabled' => "{$channelDetails['label']} notifications aren't supported yet."]);
        }

        $notification = $settings->get(ApplicationSettings::NOTIFICATION);

        if ($enabled && $channel === 'email' && ! $settings->isEmailConfigured($notification['email']['smtp'])) {
            throw ValidationException::withMessages(['enabled' => 'Configure the SMTP settings before switching e-mail on.']);
        }

        $notification[$channel]['enabled'] = $enabled;
        $settings->save(ApplicationSettings::NOTIFICATION, $notification, $request->user(), ($enabled ? 'Switched on ' : 'Switched off ')."{$channelDetails['label']} notifications.");

        return back()->with('success', "{$channelDetails['label']} notifications ".($enabled ? 'switched on.' : 'switched off.'));
    }

    public function updateEmail(Request $request, ApplicationSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', 'string', 'in:tls,ssl,none'],
            'username' => ['nullable', 'string', 'max:255'],
            // Left blank to keep the saved password.
            'password' => ['nullable', 'string', 'max:255'],
            'clear_password' => ['boolean'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
        ]);

        $notification = $settings->get(ApplicationSettings::NOTIFICATION);
        $savedPassword = $notification['email']['smtp']['password'];

        $password = match (true) {
            filled($data['password'] ?? null) => Crypt::encryptString($data['password']),
            (bool) ($data['clear_password'] ?? false) => null,
            default => $savedPassword,
        };

        $notification['email']['smtp'] = [
            'host' => trim($data['host']),
            'port' => (int) $data['port'],
            'encryption' => $data['encryption'],
            'username' => $data['username'] ?? '',
            'password' => $password,
            'from_address' => $data['from_address'],
            'from_name' => $data['from_name'] ?? '',
        ];

        $passwordNote = $password === $savedPassword ? '' : ($password === null ? ', password removed' : ', password changed');

        $settings->save(
            ApplicationSettings::NOTIFICATION,
            $notification,
            $request->user(),
            "Updated e-mail SMTP settings — {$data['host']}:{$data['port']} ({$data['encryption']}), from {$data['from_address']}{$passwordNote}.",
        );

        return back()->with('success', 'E-mail settings saved.');
    }

    /**
     * Which e-mails go to guests on their own: booking confirmation, cancellation, payment receipt.
     */
    public function updateGuestEmails(Request $request, ApplicationSettings $settings): RedirectResponse
    {
        $data = $request->validate(collect(ApplicationSettings::GUEST_EMAILS)->mapWithKeys(fn (string $label, string $key): array => [$key => ['required', 'boolean']])->all());

        $notification = $settings->get(ApplicationSettings::NOTIFICATION);
        $notification['guest_emails'] = collect(ApplicationSettings::GUEST_EMAILS)->mapWithKeys(fn (string $label, string $key): array => [$key => (bool) $data[$key]])->all();

        $summary = collect(ApplicationSettings::GUEST_EMAILS)->map(fn (string $label, string $key): string => $label.' '.($notification['guest_emails'][$key] ? 'on' : 'off'))->implode(', ');
        $settings->save(ApplicationSettings::NOTIFICATION, $notification, $request->user(), "Updated guest e-mails — {$summary}.");

        return back()->with('success', 'Guest e-mails saved.');
    }

    /**
     * Sends a message through the saved SMTP settings (even while e-mail is switched off) to check them.
     */
    public function sendTestEmail(Request $request, ApplicationSettings $settings): RedirectResponse
    {
        $to = $request->validate(['to' => ['required', 'email', 'max:255']])['to'];
        $smtp = $settings->get(ApplicationSettings::NOTIFICATION)['email']['smtp'];

        if (! $settings->isEmailConfigured($smtp)) {
            throw ValidationException::withMessages(['to' => 'Save the SMTP settings before sending a test e-mail.']);
        }

        // No probing of this server's network (localhost, LAN, cloud metadata) through the test.
        $ip = gethostbyname((string) $smtp['host']);

        if (! app()->environment('local', 'testing') && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw ValidationException::withMessages(['to' => 'The SMTP host must not be a private or internal IP address.']);
        }

        try {
            Mail::build($settings->smtpMailerConfig($smtp))->raw(
                'This is a test e-mail from '.config('app.name').'. Your SMTP settings are working.',
                fn (Message $message) => $message
                    ->from($smtp['from_address'], filled($smtp['from_name']) ? $smtp['from_name'] : null)
                    ->to($to)
                    ->subject('Test e-mail from '.config('app.name')),
            );
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['to' => 'The test e-mail could not be sent. Please verify the host, port, and credentials.']);
        }

        return back()->with('success', "Test e-mail sent to {$to}.");
    }
}
