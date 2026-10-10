<?php

namespace App\Http\Controllers;

use App\Enums\TwoFactorMethod;
use App\Models\User;
use App\Services\TwoFactorCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

/**
 * Profile settings → Two-factor authentication: turning code channels (WhatsApp, SMS, e-mail)
 * on after proving a code arrives, turning them off, and the recovery codes every method shares.
 * Passkeys and the authenticator app have their own routes.
 */
class TwoFactorMethodController extends Controller
{
    public function sendCode(Request $request, string $method, TwoFactorCodes $codes): JsonResponse
    {
        $user = $request->user();
        $channel = $this->channel($user, $method);

        $codes->send($user, $channel, 'setup');

        return response()->json(['message' => "We sent a code to {$codes->destination($user, $channel)}."]);
    }

    public function enable(Request $request, string $method, TwoFactorCodes $codes, GenerateNewRecoveryCodes $generateRecoveryCodes): RedirectResponse
    {
        $user = $request->user();
        $channel = $this->channel($user, $method);
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:12']])['code'];

        if (! $codes->verify($user, $channel, 'setup', $code)) {
            throw ValidationException::withMessages(['code' => 'The code is wrong or has expired.']);
        }

        $user->forceFill(['two_factor_channels' => array_values(array_unique([...$user->two_factor_channels ?? [], $channel->value]))])->save();
        $this->ensureRecoveryCodes($user, $generateRecoveryCodes);

        return back()->with('status', "{$channel->label()} turned on.");
    }

    public function disable(Request $request, string $method): RedirectResponse
    {
        $user = $request->user();
        $channel = TwoFactorMethod::tryFrom($method);
        abort_unless($channel?->sendsCode() ?? false, 404);

        $user->forceFill(['two_factor_channels' => array_values(array_diff($user->two_factor_channels ?? [], [$channel->value]))])->save();

        return back()->with('status', "{$channel->label()} turned off.");
    }

    /**
     * @return JsonResponse list of recovery codes
     */
    public function recoveryCodes(Request $request, GenerateNewRecoveryCodes $generateRecoveryCodes): JsonResponse
    {
        $user = $request->user();
        abort_if($user->twoFactorMethods() === [], 404);
        $this->ensureRecoveryCodes($user, $generateRecoveryCodes);

        return response()->json($user->fresh()->recoveryCodes());
    }

    public function regenerateRecoveryCodes(Request $request, GenerateNewRecoveryCodes $generateRecoveryCodes): RedirectResponse
    {
        abort_if($request->user()->twoFactorMethods() === [], 404);
        $generateRecoveryCodes($request->user());

        return back()->with('status', 'New recovery codes created.');
    }

    private function channel(User $user, string $method): TwoFactorMethod
    {
        $channel = TwoFactorMethod::tryFrom($method);
        abort_unless($channel?->sendsCode() ?? false, 404);

        if ($channel->needsMobile() && $user->mobileNumber() === null) {
            throw ValidationException::withMessages(['code' => "Add your mobile number under Profile first; {$channel->label()} codes go to it."]);
        }

        return $channel;
    }

    /**
     * Recovery codes get anyone with a two-factor method in when the method is lost.
     */
    private function ensureRecoveryCodes(User $user, GenerateNewRecoveryCodes $generateRecoveryCodes): void
    {
        if ($user->two_factor_recovery_codes === null) {
            $generateRecoveryCodes($user);
        }
    }
}
