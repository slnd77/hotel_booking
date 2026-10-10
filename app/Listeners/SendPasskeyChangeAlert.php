<?php

namespace App\Listeners;

use App\Mail\PasskeyChangedMail;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;
use Throwable;

/**
 * A security notice by e-mail whenever a passkey is added or removed; a mail failure is
 * logged, never blocks the change.
 */
class SendPasskeyChangeAlert
{
    public function handlePasskeyRegistered(PasskeyRegistered $event): void
    {
        $this->alert($event->user, $event->passkey, 'added');
    }

    public function handlePasskeyDeleted(PasskeyDeleted $event): void
    {
        $this->alert($event->user, $event->passkey, 'removed');
    }

    private function alert(Authenticatable $user, Passkey $passkey, string $change): void
    {
        Log::info("Passkey {$change} for user {$user->getAuthIdentifier()}: {$passkey->name}");

        $email = $user->getAttribute('email');

        if (! filled($email)) {
            return;
        }

        $request = app()->runningInConsole() ? null : request();

        try {
            Mail::to($email)->queue(new PasskeyChangedMail(
                (string) $user->getAttribute('name'),
                (string) $passkey->name,
                $change,
                now()->utc()->format('d M Y, h:i A').' UTC',
                $request?->ip(),
                filled($request?->userAgent()) ? Str::limit((string) $request->userAgent(), 120) : null,
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
