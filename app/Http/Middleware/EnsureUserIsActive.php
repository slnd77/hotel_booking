<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out a user made inactive (also one who has just signed in) and sends them to the login
 * page with the reason.
 */
class EnsureUserIsActive
{
    public const MESSAGE = 'Your user account is inactive. Contact your account administrator.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isActive()) {
            return $next($request);
        }

        // API tokens carry no session: the request is refused, the token stays until revoked.
        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson() || ! $request->hasSession()) {
            abort(403, self::MESSAGE);
        }

        return redirect()->route('login')->withErrors(['email' => self::MESSAGE]);
    }
}
