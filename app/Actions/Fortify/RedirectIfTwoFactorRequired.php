<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;

/**
 * After the password, users with any two-factor method on (passkey, authenticator app, or
 * codes by WhatsApp, SMS or e-mail) are sent to the two-factor challenge, not just TOTP users.
 */
class RedirectIfTwoFactorRequired extends RedirectIfTwoFactorAuthenticatable
{
    /**
     * @param  Request  $request
     * @param  callable  $next
     * @return mixed
     */
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);

        if ($user instanceof User && $user->twoFactorMethods() !== []) {
            return $this->twoFactorChallengeResponse($request, $user);
        }

        return $next($request);
    }
}
