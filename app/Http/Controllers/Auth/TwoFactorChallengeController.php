<?php

namespace App\Http\Controllers\Auth;

use App\Enums\TwoFactorMethod;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PasskeyChallenges;
use App\Services\TwoFactorCodes;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Laravel\Passkeys\Support\WebAuthn;
use Symfony\Component\HttpFoundation\Response;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * The second login step for the methods Fortify doesn't cover: one-time codes by WhatsApp,
 * SMS or e-mail, and passkeys. (Authenticator app and recovery codes go through Fortify's own
 * `/two-factor-challenge`.)
 */
class TwoFactorChallengeController extends Controller
{
    private const PASSKEY_OPTIONS = 'two-factor.passkey_options';

    public function sendCode(Request $request, TwoFactorCodes $codes): JsonResponse
    {
        $user = $this->challengedUser($request);
        $method = $this->codeMethod($request, $user);

        $codes->send($user, $method, 'login');

        return response()->json(['message' => "We sent a code to {$codes->destination($user, $method)}."]);
    }

    public function verifyCode(Request $request, TwoFactorCodes $codes): Response
    {
        $user = $this->challengedUser($request);
        $method = $this->codeMethod($request, $user);
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:12']])['code'];

        if (! $codes->verify($user, $method, 'login', $code)) {
            throw ValidationException::withMessages(['code' => 'The code is wrong or has expired.']);
        }

        return $this->logIn($request, $user);
    }

    public function passkeyOptions(Request $request, GenerateVerificationOptions $generate, PasskeyChallenges $challenges): JsonResponse
    {
        $user = $this->challengedUser($request);
        abort_unless($user->hasTwoFactorMethod(TwoFactorMethod::Passkey), 422, 'You have no passkey.');

        $options = $generate($user);
        $challenges->remember($request->session(), self::PASSKEY_OPTIONS, WebAuthn::toJson($options));

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function verifyPasskey(PasskeyVerificationRequest $request, VerifyPasskey $verify, PasskeyChallenges $challenges): Response
    {
        $user = $this->challengedUser($request);
        $serialized = $challenges->pull($request->session(), self::PASSKEY_OPTIONS);

        if ($serialized === null) {
            throw ValidationException::withMessages(['credential' => 'The passkey request expired. Try again.']);
        }

        try {
            $verify($request->credential(), WebAuthn::fromJson($serialized, PublicKeyCredentialRequestOptions::class), $user);
        } catch (InvalidPasskeyException $exception) {
            throw ValidationException::withMessages(['credential' => $exception->getMessage()]);
        }

        return $this->logIn($request, $user);
    }

    /**
     * The user who passed the password step (Fortify keeps them in the session).
     */
    private function challengedUser(Request $request): User
    {
        $id = $request->session()->get('login.id');
        $user = $id ? User::query()->find($id) : null;

        if (! $user instanceof User) {
            throw new HttpResponseException($request->expectsJson()
                ? response()->json(['message' => 'Your sign-in expired. Sign in again.'], 419)
                : redirect()->route('login'));
        }

        return $user;
    }

    private function codeMethod(Request $request, User $user): TwoFactorMethod
    {
        $value = $request->validate(['method' => ['required', Rule::in(array_map(fn (TwoFactorMethod $method): string => $method->value, TwoFactorMethod::codeChannels()))]])['method'];
        $method = TwoFactorMethod::from($value);

        if (! $user->hasTwoFactorMethod($method)) {
            throw ValidationException::withMessages(['method' => "{$method->label()} isn't turned on for you."]);
        }

        return $method;
    }

    private function logIn(Request $request, User $user): Response
    {
        Auth::guard(config('fortify.guard'))->login($user, (bool) $request->session()->pull('login.remember', false));
        $request->session()->forget('login.id');
        $request->session()->regenerate();

        return app(TwoFactorLoginResponse::class)->toResponse($request);
    }
}
