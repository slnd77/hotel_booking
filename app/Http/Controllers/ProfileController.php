<?php

namespace App\Http\Controllers;

use App\Enums\TwoFactorMethod;
use App\Models\Country;
use App\Services\TwoFactorCodes;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passkeys\Passkey;

/**
 * "Profile settings" from the username menu: the signed-in user's name,
 * password and two-factor authentication. Saving goes through Fortify's routes.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('profile/Edit', [
            'user' => $request->user()->only(['name', 'email', 'mobile_country_code', 'mobile']),
            'countries' => Country::query()->orderBy('name')->get(['id', 'name', 'iso2', 'phonecode']),
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Protected by `password.confirm`, like Fortify's own two-factor routes.
     */
    public function twoFactor(Request $request): Response
    {
        $user = $request->user();
        $codes = app(TwoFactorCodes::class);

        return Inertia::render('profile/TwoFactor', [
            'twoFactorEnabled' => $user->two_factor_secret !== null,
            'twoFactorConfirmed' => $user->two_factor_confirmed_at !== null,
            'channels' => collect(TwoFactorMethod::codeChannels())->map(fn (TwoFactorMethod $method): array => [
                'value' => $method->value,
                'label' => $method->label(),
                'enabled' => $user->hasTwoFactorMethod($method),
                // SMS / WhatsApp need a mobile number first.
                'available' => ! $method->needsMobile() || $user->mobileNumber() !== null,
                'destination' => $codes->destination($user, $method),
            ])->values(),
            'passkeys' => $user->passkeys()->latest()->get()->map(fn (Passkey $passkey): array => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at' => $passkey->created_at?->toIso8601String(),
                'last_used_at' => $passkey->last_used_at?->toIso8601String(),
            ]),
            'hasTwoFactor' => $user->twoFactorMethods() !== [],
            'status' => $request->session()->get('status'),
        ]);
    }
}
