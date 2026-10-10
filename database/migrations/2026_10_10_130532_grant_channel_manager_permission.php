<?php

use App\Services\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * "Access Channel Manager": Account Admin and Property Admin have it from the start,
     * like their other Manage Property defaults.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('manage_property.channel_manager', 'web');

        Role::query()
            ->whereIn('name', [PermissionCatalog::ACCOUNT_ADMIN, 'Property Admin'])
            ->where('guard_name', 'web')
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo('manage_property.channel_manager'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'manage_property.channel_manager')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
