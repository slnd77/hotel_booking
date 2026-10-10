<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The API's second login step: after the password, the app gets a short-lived challenge token
 * (instead of a session) to trade for an access token with one of the user's two-factor methods.
 * Only a hash of it is kept; it dies after a few minutes or a few wrong answers.
 */
class ApiTwoFactorChallenges
{
    public const MINUTES = 5;

    public const MAX_FAILURES = 5;

    public function create(User $user, string $deviceName): string
    {
        $token = Str::random(64);
        Cache::put($this->key($token), ['user_id' => $user->getKey(), 'device_name' => $deviceName, 'failures' => 0, 'passkey_options' => null, 'passkey_expires_at' => null], now()->addMinutes(self::MINUTES));

        return $token;
    }

    public function __construct(private PasskeyChallenges $passkeyChallenges) {}

    /**
     * The challenge; its passkey options are null once the passkey prompt has timed out.
     *
     * @return array{user: User, device_name: string, passkey_options: string|null}|null
     */
    public function find(string $token): ?array
    {
        $challenge = Cache::get($this->key($token));
        $user = is_array($challenge) ? User::query()->find($challenge['user_id']) : null;

        if (! $user instanceof User || ! $user->isActive()) {
            return null;
        }

        $passkeyExpired = $this->passkeyChallenges->isExpired($challenge['passkey_expires_at'] ?? null);

        return ['user' => $user, 'device_name' => $challenge['device_name'], 'passkey_options' => $passkeyExpired ? null : $challenge['passkey_options']];
    }

    public function rememberPasskeyOptions(string $token, string $serializedOptions): void
    {
        $this->update($token, ['passkey_options' => $serializedOptions, 'passkey_expires_at' => $this->passkeyChallenges->expiresAt()]);
    }

    /**
     * Passkey options answer one attempt only.
     */
    public function forgetPasskeyOptions(string $token): void
    {
        $this->update($token, ['passkey_options' => null, 'passkey_expires_at' => null]);
    }

    /**
     * A wrong answer; the challenge ends after too many.
     */
    public function fail(string $token): void
    {
        $challenge = Cache::get($this->key($token));

        if (! is_array($challenge)) {
            return;
        }

        $challenge['failures'] >= self::MAX_FAILURES - 1
            ? Cache::forget($this->key($token))
            : $this->update($token, ['failures' => $challenge['failures'] + 1]);
    }

    public function forget(string $token): void
    {
        Cache::forget($this->key($token));
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function update(string $token, array $changes): void
    {
        $challenge = Cache::get($this->key($token));

        if (is_array($challenge)) {
            Cache::put($this->key($token), [...$challenge, ...$changes], now()->addMinutes(self::MINUTES));
        }
    }

    private function key(string $token): string
    {
        return 'api-two-factor:'.hash('sha256', $token);
    }
}
