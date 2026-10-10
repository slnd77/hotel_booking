<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Manage Account → Roles and Permissions: the system defined roles (read only) and the
 * account's user defined roles, which are created, edited and deleted here.
 */
class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        PermissionCatalog::ensurePermissions();

        $systemRoles = Role::query()
            ->whereIn('name', PermissionCatalog::PREDEFINED_ROLES)
            ->with('permissions:id,name')
            ->withCount('users')
            ->get()
            ->sortBy(fn (Role $role): int => (int) array_search($role->name, PermissionCatalog::PREDEFINED_ROLES, true))
            ->values();

        $customRoles = Role::query()
            ->whereNotIn('name', PermissionCatalog::systemRoles())
            ->with('permissions:id,name')
            ->withCount('users')
            ->orderBy('name')
            ->get();

        $all = $systemRoles->concat($customRoles);
        $selected = $all->firstWhere('id', $request->integer('role')) ?? $all->first();

        return Inertia::render('admin/roles/Index', [
            'systemRoles' => $this->rolesPayload($systemRoles),
            'customRoles' => $this->rolesPayload($customRoles),
            'selectedRoleId' => $selected?->id,
            'catalogue' => collect(PermissionCatalog::catalogue())->map(fn (array $groups, string $section): array => [
                'name' => $section,
                'groups' => collect($groups)->map(fn (array $permissions, string $group): array => [
                    'name' => $group,
                    'permissions' => collect($permissions)->map(fn (string $label, string $name): array => ['name' => $name, 'label' => $label])->values(),
                ])->values(),
            ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        PermissionCatalog::ensurePermissions();

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        $role->syncPermissions($data['permissions']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('admin.roles.index', ['role' => $role->id])->with('success', "Role {$role->name} created.");
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_unless(PermissionCatalog::isCustomRole($role->name), 403, 'System defined roles can\'t be edited.');

        $data = $this->validated($request, $role);
        PermissionCatalog::ensurePermissions();

        $role->update(['name' => $data['name']]);
        $role->syncPermissions($data['permissions']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('admin.roles.index', ['role' => $role->id])->with('success', "Role {$role->name} saved.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        abort_unless(PermissionCatalog::isCustomRole($role->name), 403, 'System defined roles can\'t be deleted.');

        if ($role->users()->exists()) {
            return back()->with('error', "Role {$role->name} is given to users. Give them another role first.");
        }

        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('admin.roles.index')->with('success', "Role {$role->name} deleted.");
    }

    /**
     * @return array{name: string, permissions: list<string>}
     */
    private function validated(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::notIn(PermissionCatalog::systemRoles()),
                Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($role?->id),
            ],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(PermissionCatalog::names())],
        ], [
            'name.not_in' => 'This name belongs to a system defined role.',
        ]);
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @return Collection<int, array{id: int|string, name: string, users_count: int, permissions: list<string>}>
     */
    private function rolesPayload(Collection $roles): Collection
    {
        return $roles->map(fn (Role $role): array => [
            'id' => $role->id,
            'name' => $role->name,
            'users_count' => $role->users_count,
            'permissions' => array_values($role->permissions->map(fn (Permission $permission): string => $permission->name)->all()),
        ])->values();
    }
}
