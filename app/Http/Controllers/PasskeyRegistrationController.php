<?php

namespace App\Http\Controllers;

use App\Services\PasskeyChallenges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Contracts\PasskeyRegistrationResponse;
use Laravel\Passkeys\Http\Controllers\PasskeyRegistrationController as BaseController;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;

/**
 * Profile → Two-factor → passkeys: the package's registration, with a cap on passkeys per user
 * and a challenge that expires on the server with the browser's WebAuthn timeout.
 * (Requests are also rate limited: the `passkey-registration` limiter on the routes.)
 */
class PasskeyRegistrationController extends BaseController
{
    public const MAX_PASSKEYS = 10;

    private const EXPIRES_AT = 'passkey.registration_expires_at';

    public function __construct(private PasskeyChallenges $challenges) {}

    public function index(Request $request, GenerateRegistrationOptions $generate): JsonResponse
    {
        if ($request->user()->passkeys()->count() >= self::MAX_PASSKEYS) {
            throw ValidationException::withMessages(['name' => 'You already have '.self::MAX_PASSKEYS.' passkeys. Remove one before adding another.']);
        }

        $response = parent::index($request, $generate);
        $request->session()->put(self::EXPIRES_AT, $this->challenges->expiresAt());

        return $response;
    }

    public function store(PasskeyRegistrationRequest $request, StorePasskey $storePasskey): PasskeyRegistrationResponse
    {
        if ($this->challenges->isExpired($request->session()->pull(self::EXPIRES_AT))) {
            $request->session()->forget('passkey.registration_options');

            throw ValidationException::withMessages(['credential' => 'Passkey registration session expired. Please try again.']);
        }

        if ($request->user()->passkeys()->count() >= self::MAX_PASSKEYS) {
            $request->session()->forget('passkey.registration_options');

            throw ValidationException::withMessages(['name' => 'You already have '.self::MAX_PASSKEYS.' passkeys. Remove one before adding another.']);
        }

        return parent::store($request, $storePasskey);
    }
}
