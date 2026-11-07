<?php

use App\Services\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * "Generate API keys": Account Admin has it from the start, like its other defaults.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('manage_account.api_keys', 'web');
        Role::query()->where('name', PermissionCatalog::ACCOUNT_ADMIN)->where('guard_name', 'web')->first()?->givePermissionTo('manage_account.api_keys');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', 'manage_account.api_keys')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
