<?php

namespace App\Services;

use App\Enums\TwoFactorMethod;
use App\Mail\TwoFactorCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * One-time codes sent by e-mail, SMS or WhatsApp: for signing in ("login") or for proving a
 * channel works before it is turned on ("setup"). Only a hash is kept, for a few minutes and a
 * few tries.
 */
class TwoFactorCodes
{
    public const MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 30;

    public function __construct(private TextMessages $textMessages) {}

    /**
     * @throws ValidationException when a code was sent moments ago
     */
    public function send(User $user, TwoFactorMethod $method, string $purpose): void
    {
        $throttleKey = $this->key($user, $method, $purpose).':resend';

        if (RateLimiter::tooManyAttempts($throttleKey, 1)) {
            throw ValidationException::withMessages([
                'code' => 'Wait '.RateLimiter::availableIn($throttleKey).' seconds before asking for another code.',
            ]);
        }

        RateLimiter::hit($throttleKey, self::RESEND_SECONDS);

        $code = (string) random_int(100000, 999999);
        Cache::put($this->key($user, $method, $purpose), ['hash' => Hash::make($code), 'attempts' => 0], now()->addMinutes(self::MINUTES));

        match ($method) {
            TwoFactorMethod::Email => Mail::to($user->email)->send(new TwoFactorCodeMail($code, self::MINUTES)),
            default => $this->textMessages->send($method, (string) $user->mobileNumber(), "{$code} is your ".config('app.name').' sign-in code. It expires in '.self::MINUTES.' minutes.'),
        };
    }

    /**
     * Whether the code is the one last sent; a right code can be used once.
     */
    public function verify(User $user, TwoFactorMethod $method, string $purpose, string $code): bool
    {
        $key = $this->key($user, $method, $purpose);
        $sent = Cache::get($key);

        if (! is_array($sent) || $sent['attempts'] >= self::MAX_ATTEMPTS) {
            return false;
        }

        if (! Hash::check(preg_replace('/\D+/', '', $code), $sent['hash'])) {
            Cache::put($key, [...$sent, 'attempts' => $sent['attempts'] + 1], now()->addMinutes(self::MINUTES));

            return false;
        }

        Cache::forget($key);

        return true;
    }

    /**
     * Where the code goes, partly hidden: "a****@example.com", "+91 ******5678".
     */
    public function destination(User $user, TwoFactorMethod $method): string
    {
        if ($method === TwoFactorMethod::Email) {
            [$name, $domain] = explode('@', $user->email, 2) + [1 => ''];

            return Str::mask($name, '*', 1).'@'.$domain;
        }

        $digits = preg_replace('/\D+/', '', (string) $user->mobile);

        return trim('+'.ltrim((string) $user->mobile_country_code, '+').' '.Str::mask($digits, '*', 0, max(0, strlen($digits) - 4)));
    }

    private function key(User $user, TwoFactorMethod $method, string $purpose): string
    {
        return "two-factor-code:{$purpose}:{$user->getKey()}:{$method->value}";
    }
}
