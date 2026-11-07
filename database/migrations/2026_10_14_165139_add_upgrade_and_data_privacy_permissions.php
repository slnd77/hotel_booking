<?php

use App\Services\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Permissions added to the catalogue since it was first installed (room upgrades, Data &
     * privacy), given to the predefined roles that have them by default.
     */
    private const NEW_PERMISSIONS = ['reservation.upgrade_room', 'manage_account.data_privacy'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::NEW_PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (PermissionCatalog::PREDEFINED_ROLES as $roleName) {
            $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
            $granted = array_intersect(self::NEW_PERMISSIONS, PermissionCatalog::defaultsFor($roleName));

            if ($role !== null && $granted !== []) {
                $role->givePermissionTo($granted);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', self::NEW_PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
