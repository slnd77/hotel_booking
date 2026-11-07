<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleSignIn;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Features;
use RuntimeException;

/**
 * "Continue with Google" on the log-in and create-account pages. A Google account with a
 * verified e-mail signs in the user of that e-mail (linking the Google account on first use);
 * from the create-account page it opens a new account when none has that e-mail. Users with a
 * second factor still go through the two-factor challenge.
 */
class GoogleSignInController extends Controller
{
    private const SESSION_KEY = 'google_sign_in';

    public function __construct(private GoogleSignIn $google) {}

    public function redirect(Request $request, string $intent = 'login'): RedirectResponse
    {
        abort_unless($this->google->enabled(), 404);
        abort_unless(in_array($intent, ['login', 'register'], true), 404);

        $state = Str::random(40);
        $request->session()->put(self::SESSION_KEY, ['state' => $state, 'intent' => $intent]);

        return redirect()->away($this->google->authorizeUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless($this->google->enabled(), 404);

        $pending = $request->session()->pull(self::SESSION_KEY);
        $intent = $pending['intent'] ?? 'login';
        $back = $intent === 'register' ? 'register' : 'login';

        if (! is_array($pending) || ! is_string($request->query('state')) || ! hash_equals((string) $pending['state'], $request->query('state'))) {
            return $this->fail($back, 'The Google sign-in expired. Please try again.');
        }

        if ($request->filled('error') || ! is_string($request->query('code'))) {
            return $this->fail($back, 'Google sign-in was cancelled.');
        }

        try {
            $account = $this->google->account($request->query('code'));
        } catch (RuntimeException $exception) {
            report($exception);

            return $this->fail($back, 'Google sign-in failed. Please try again or use your password.');
        }

        if (! $account['email_verified']) {
            return $this->fail($back, 'Your Google e-mail address is not verified.');
        }

        $matches = User::query()
            ->where(fn ($query) => $query->where('google_id', $account['id'])->orWhereRaw('LOWER(email) = ?', [$account['email']]))
            ->get();

        if ($matches->count() > 1) {
            return $this->fail('login', 'Several accounts use this e-mail. Log in with your password to choose one.');
        }

        $user = $matches->first();

        if ($user === null) {
            if ($intent !== 'register' || ! Features::enabled(Features::registration())) {
                return $this->fail('login', 'No account uses this Google e-mail. Create an account first.');
            }

            $user = $this->createUser($account);
        }

        if ($user->status === UserStatus::Inactive) {
            return $this->fail('login', 'This account is inactive. Ask your administrator.');
        }

        if ($user->google_id === null) {
            $user->forceFill(['google_id' => $account['id'], 'email_verified_at' => $user->email_verified_at ?? now()])->save();
        }

        if ($user->twoFactorMethods() !== []) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);
            TwoFactorAuthenticationChallenged::dispatch($user);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(config('fortify.home'));
    }

    /**
     * @param  array{id: string, email: string, email_verified: bool, name: string|null}  $account
     */
    private function createUser(array $account): User
    {
        $user = User::query()->create([
            'name' => $account['name'] ?: Str::before($account['email'], '@'),
            'email' => $account['email'],
            // Signs in with Google; a password can be set later with "Forgot your password?".
            'password' => Hash::make(Str::random(64)),
        ]);
        $user->forceFill(['google_id' => $account['id'], 'email_verified_at' => now()])->save();
        $user->assignRole('user');

        event(new Registered($user));

        return $user;
    }

    private function fail(string $route, string $message): RedirectResponse
    {
        return redirect()->route($route)->withErrors(['google' => $message]);
    }
}
