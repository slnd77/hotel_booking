<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ApiScopes;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * `api.scope:{scope}` on an API route: the token must hold the scope (device logins hold
 * every scope) and its user's role must allow it, checked on each request.
 */
class AuthorizeApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        abort_unless($user instanceof User && $token instanceof PersonalAccessToken, 401, 'Unauthenticated.');
        abort_unless($token->can($scope), 403, "This API key is missing the scope: {$scope}.");
        abort_unless(ApiScopes::userMay($user, $scope), 403, "Your role doesn't allow {$scope}.");

        return $next($request);
    }
}
