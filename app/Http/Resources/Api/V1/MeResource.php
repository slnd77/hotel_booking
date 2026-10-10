<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Services\ApiScopes;
use App\Services\PermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Inertia\Inertia;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The signed-in user as the app needs it: who they are, what their role lets them do, and what
 * the current token may do.
 *
 * @mixin User
 */
class MeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $token = $this->currentAccessToken();
        $packageMenus = Inertia::getShared('packageMenus', []);
        $fullAccess = $this->hasAnyRole(PermissionCatalog::fullAccessRoles());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'mobile_country_code' => $this->mobile_country_code,
            'mobile' => $this->mobile,
            'roles' => $this->getRoleNames()->values(),
            // Super admin and Admin pass every check: "*".
            'permissions' => $fullAccess ? ['*'] : $this->getAllPermissions()->pluck('name')->values(),
            'scopes' => $token instanceof PersonalAccessToken
                ? collect(ApiScopes::names())->filter(fn (string $scope): bool => $token->can($scope) && ApiScopes::userMay($this->resource, $scope))->values()
                : [],
            'two_factor_methods' => collect($this->twoFactorMethods())->map->value->values(),
            // Features installed packages turn off (null: everything on) and the pages they add
            // to the menu, as the web layout gets them (shared by those packages).
            'plan_features' => value(Inertia::getShared('features')),
            'package_menus' => collect(is_array($packageMenus) ? $packageMenus : [])
                ->map(fn (mixed $menu): mixed => value($menu))
                ->filter()
                ->all(),
        ];
    }
}
