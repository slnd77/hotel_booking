<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * "Continue with Google" (OAuth 2.0 / OpenID Connect authorization code flow), without an SDK:
 * the consent URL, then the code exchanged server-side for the signed-in Google account.
 * Configured under services.google; off while the client id or secret is missing.
 */
class GoogleSignIn
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    public function enabled(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    /**
     * Google's consent page; it comes back to the callback with the code and this state.
     */
    public function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]);
    }

    /**
     * The Google account behind an authorization code.
     *
     * @return array{id: string, email: string, email_verified: bool, name: string|null}
     *
     * @throws RuntimeException when Google refuses the code or sends no account
     */
    public function account(string $code): array
    {
        $token = Http::asForm()->timeout(10)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        if (! $token->successful() || blank($token->json('access_token'))) {
            throw new RuntimeException('Google did not accept the sign-in.');
        }

        $profile = Http::withToken((string) $token->json('access_token'))->timeout(10)->get(self::USERINFO_URL);

        if (! $profile->successful() || blank($profile->json('sub')) || blank($profile->json('email'))) {
            throw new RuntimeException('Google did not send the account.');
        }

        return [
            'id' => (string) $profile->json('sub'),
            'email' => mb_strtolower(trim((string) $profile->json('email'))),
            'email_verified' => filter_var($profile->json('email_verified'), FILTER_VALIDATE_BOOLEAN),
            'name' => $profile->json('name') ?: null,
        ];
    }

    private function redirectUri(): string
    {
        return config('services.google.redirect') ?: route('auth.google.callback');
    }
}
