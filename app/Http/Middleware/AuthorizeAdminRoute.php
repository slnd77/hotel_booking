<?php

namespace App\Http\Middleware;

use App\Services\PermissionCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Each admin route needs the permission PermissionCatalog::ROUTES gives it (any one of a
 * list); routes not listed are for the super admin only. The super admin passes everything.
 * The mobile app's copy of the panel (`api.v1.panel.*`) is checked by the same names.
 */
class AuthorizeAdminRoute
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $routeName = (string) ServePanelOverApi::adminRouteName($request->route()?->getName());

        if ($user === null || $user->hasAnyRole(PermissionCatalog::fullAccessRoles())) {
            return $next($request);
        }

        abort_unless(PermissionCatalog::canEnterPanel($user), 403, 'User does not have the right roles.');

        $permission = PermissionCatalog::forRoute($routeName);

        // Pages no permission covers belong to the account owner (Account Admin).
        abort_if($permission === false && ! $user->hasAnyRole(PermissionCatalog::ownerRoles()), 403, 'This page is for the account owner only.');
        abort_if($permission !== null && $permission !== false && ! collect((array) $permission)->contains(fn (string $name): bool => $user->checkPermissionTo($name)), 403, 'You don\'t have permission to do this.');

        return $next($request);
    }
}
