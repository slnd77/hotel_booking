<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\RedirectIfTwoFactorRequired;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Enums\TwoFactorMethod;
use App\Models\User;
use App\Services\GoogleSignIn;
use App\Services\TwoFactorCodes;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Any two-factor method (not only the authenticator app) leads to the challenge.
        $this->app->singleton(RedirectsIfTwoFactorAuthenticatable::class, RedirectIfTwoFactorRequired::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        Fortify::loginView(fn () => Inertia::render('auth/Login', [
            'canResetPassword' => true,
            'canRegister' => Features::enabled(Features::registration()),
            'googleSignIn' => app(GoogleSignIn::class)->enabled(),
            'status' => session('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            'googleSignIn' => app(GoogleSignIn::class)->enabled(),
        ]));

        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('auth/ForgotPassword', [
            'status' => session('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]));

        Fortify::twoFactorChallengeView(function (Request $request) {
            $user = User::query()->whereKey($request->session()->get('login.id'))->first();
            $codes = app(TwoFactorCodes::class);

            return Inertia::render('auth/TwoFactorChallenge', [
                'methods' => $user === null ? [] : collect($user->twoFactorMethods())->map(fn (TwoFactorMethod $method): array => [
                    'value' => $method->value,
                    'label' => $method->label(),
                    'destination' => $method->sendsCode() ? $codes->destination($user, $method) : null,
                ])->values(),
            ]);
        });

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id')));

        // Adding passkeys: a few tries a minute, and a daily cap, per user.
        RateLimiter::for('passkey-registration', fn (Request $request) => [
            Limit::perMinute(6)->by('passkey-registration:'.$request->user()?->getAuthIdentifier()),
            Limit::perDay(30)->by('passkey-registration-day:'.$request->user()?->getAuthIdentifier()),
        ]);

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
