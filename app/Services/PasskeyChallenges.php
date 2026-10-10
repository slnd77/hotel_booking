<?php

namespace App\Services;

use Illuminate\Contracts\Session\Session;
use Laravel\Passkeys\Passkeys;

/**
 * Passkey challenges kept on the server only as long as the browser waits for the passkey
 * (the WebAuthn timeout) plus a short grace, and each used once: an old or replayed answer
 * is refused even when the session lives on.
 */
class PasskeyChallenges
{
    public const GRACE_SECONDS = 30;

    /**
     * When a challenge issued now stops being accepted (UTC timestamp).
     */
    public function expiresAt(): int
    {
        return now()->addMilliseconds(Passkeys::timeout())->addSeconds(self::GRACE_SECONDS)->getTimestamp();
    }

    public function isExpired(mixed $expiresAt): bool
    {
        return ! is_int($expiresAt) || now()->getTimestamp() > $expiresAt;
    }

    /**
     * Keeps the serialized options with their expiry under $key.
     */
    public function remember(Session $session, string $key, string $serializedOptions): void
    {
        $session->put($key, ['options' => $serializedOptions, 'expires_at' => $this->expiresAt()]);
    }

    /**
     * The options under $key, removed so they can't be answered twice; null when missing or expired.
     */
    public function pull(Session $session, string $key): ?string
    {
        $challenge = $session->pull($key);

        if (! is_array($challenge) || ! is_string($challenge['options'] ?? null) || $this->isExpired($challenge['expires_at'] ?? null)) {
            return null;
        }

        return $challenge['options'];
    }
}
