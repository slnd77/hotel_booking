<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TwoFactorMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MeResource;
use App\Models\User;
use App\Services\ApiScopes;
use App\Services\ApiTwoFactorChallenges;
use App\Services\PermissionCatalog;
use App\Services\TwoFactorCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Mobile app sign-in: e-mail and password, then (when the user has any) a two-factor method,
 * for a device token that may do everything the user's role allows.
 */
class AuthController extends Controller
{
    public function login(Request $request, ApiTwoFactorChallenges $challenges, TwoFactorCodes $codes): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $provider = Auth::guard('web')->getProvider();
        $user = $provider->retrieveByCredentials(['email' => $data['email']]);

        if (! $user instanceof User || ! $provider->validateCredentials($user, ['password' => $data['password']])) {
            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        abort_unless($user->isActive(), 403, 'Your user account is inactive. Contact your account administrator.');
        abort_unless(PermissionCatalog::canEnterPanel($user), 403, 'This user has no access to the hotel admin.');

        if ($user->twoFactorMethods() === []) {
            return $this->tokenResponse($user, $data['device_name']);
        }

        return response()->json([
            'two_factor' => true,
            'challenge_token' => $challenges->create($user, $data['device_name']),
            'expires_in' => ApiTwoFactorChallenges::MINUTES * 60,
            'methods' => collect($user->twoFactorMethods())->map(fn (TwoFactorMethod $method): array => [
                'value' => $method->value,
                'label' => $method->label(),
                'destination' => $method->sendsCode() ? $codes->destination($user, $method) : null,
            ])->values(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // A token sign-in revokes its token; a browser session signed in through Sanctum's
        // stateful mode has no token to revoke.
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): MeResource
    {
        return new MeResource($request->user());
    }

    /**
     * A device token: every scope, limited by the user's role on each request.
     */
    public static function tokenResponse(User $user, string $deviceName): JsonResponse
    {
        $token = $user->createToken(ApiScopes::DEVICE_PREFIX.$deviceName, ['*']);
        $user->withAccessToken($token->accessToken);

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => new MeResource($user),
        ]);
    }
}
