<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TwoFactorMethod;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ApiTwoFactorChallenges;
use App\Services\TwoFactorCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * The API's second login step: each method trades the challenge token from login for a device
 * token. Wrong answers count against the challenge.
 */
class TwoFactorController extends Controller
{
    public function __construct(private ApiTwoFactorChallenges $challenges) {}

    public function sendCode(Request $request, TwoFactorCodes $codes): JsonResponse
    {
        [$user] = $this->challenge($request);
        $method = $this->codeMethod($request, $user);

        $codes->send($user, $method, 'login');

        return response()->json(['message' => "We sent a code to {$codes->destination($user, $method)}."]);
    }

    public function verifyCode(Request $request, TwoFactorCodes $codes): JsonResponse
    {
        [$user, $device, $token] = $this->challenge($request);
        $method = $this->codeMethod($request, $user);
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:12']])['code'];

        return $codes->verify($user, $method, 'login', $code)
            ? $this->signIn($user, $device, $token)
            : $this->wrong($token, 'code', 'The code is wrong or has expired.');
    }

    public function totp(Request $request, TwoFactorAuthenticationProvider $provider): JsonResponse
    {
        [$user, $device, $token] = $this->challenge($request);
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:12']])['code'];

        if (! $user->hasTwoFactorMethod(TwoFactorMethod::Totp)) {
            throw ValidationException::withMessages(['code' => 'The authenticator app isn\'t turned on for you.']);
        }

        return $provider->verify(decrypt($user->two_factor_secret), $code)
            ? $this->signIn($user, $device, $token)
            : $this->wrong($token, 'code', 'The code is wrong.');
    }

    public function recovery(Request $request): JsonResponse
    {
        [$user, $device, $token] = $this->challenge($request);
        $code = (string) $request->validate(['recovery_code' => ['required', 'string', 'max:100']])['recovery_code'];
        $valid = $user->two_factor_recovery_codes !== null && collect($user->recoveryCodes())->contains(fn (string $recoveryCode): bool => hash_equals($recoveryCode, $code));

        if (! $valid) {
            return $this->wrong($token, 'recovery_code', 'The recovery code is wrong.');
        }

        $user->replaceRecoveryCode($code);

        return $this->signIn($user, $device, $token);
    }

    public function passkeyOptions(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        [$user, , $token] = $this->challenge($request);
        abort_unless($user->hasTwoFactorMethod(TwoFactorMethod::Passkey), 422, 'You have no passkey.');

        $options = $generate($user);
        $this->challenges->rememberPasskeyOptions($token, WebAuthn::toJson($options));

        return response()->json(['options' => WebAuthn::toBrowserArray($options)]);
    }

    public function verifyPasskey(Request $request, VerifyPasskey $verify): JsonResponse
    {
        [$user, $device, $token, $serializedOptions] = $this->challenge($request);
        $request->validate([
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.rawId' => ['required', 'string'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.response' => ['required', 'array'],
        ]);

        if ($serializedOptions === null) {
            throw ValidationException::withMessages(['credential' => 'The passkey request expired or was already used. Ask for passkey options again.']);
        }

        $this->challenges->forgetPasskeyOptions($token);

        try {
            $credential = WebAuthn::fromJson(json_encode($request->input('credential')) ?: '{}', PublicKeyCredential::class);
            $verify($credential, WebAuthn::fromJson($serializedOptions, PublicKeyCredentialRequestOptions::class), $user);
        } catch (InvalidPasskeyException $exception) {
            return $this->wrong($token, 'credential', $exception->getMessage());
        } catch (Throwable) {
            return $this->wrong($token, 'credential', 'The passkey could not be verified.');
        }

        return $this->signIn($user, $device, $token);
    }

    /**
     * @return array{0: User, 1: string, 2: string, 3: string|null}
     */
    private function challenge(Request $request): array
    {
        $token = (string) $request->validate(['challenge_token' => ['required', 'string', 'max:100']])['challenge_token'];
        $challenge = $this->challenges->find($token);

        abort_if($challenge === null, 401, 'Your sign-in expired. Sign in again.');

        return [$challenge['user'], $challenge['device_name'], $token, $challenge['passkey_options']];
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

    private function signIn(User $user, string $device, string $token): JsonResponse
    {
        $this->challenges->forget($token);

        return AuthController::tokenResponse($user, $device);
    }

    private function wrong(string $token, string $field, string $message): never
    {
        $this->challenges->fail($token);

        throw ValidationException::withMessages([$field => $message]);
    }
}
