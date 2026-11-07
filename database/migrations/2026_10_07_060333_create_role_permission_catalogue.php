<?php

use App\Services\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * The Roles and Permissions catalogue, the full-access roles (Super admin, Admin) and the
     * predefined roles with their starting permission matrices.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionCatalog::names() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        // Super admin and Admin hold no permissions: they bypass every check.
        foreach (PermissionCatalog::fullAccessRoles() as $role) {
            Role::findOrCreate($role, 'web');
        }

        foreach (PermissionCatalog::PREDEFINED_ROLES as $role) {
            Role::findOrCreate($role, 'web')->syncPermissions(PermissionCatalog::defaultsFor($role));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->whereIn('name', [PermissionCatalog::SUPER_ADMIN, ...PermissionCatalog::PREDEFINED_ROLES])->delete();
        Permission::query()->whereIn('name', PermissionCatalog::names())->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
