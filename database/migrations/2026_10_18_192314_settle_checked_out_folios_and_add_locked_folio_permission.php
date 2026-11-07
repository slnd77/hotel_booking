<?php

use App\Enums\BookingStatus;
use App\Enums\FolioStatus;
use App\Enums\SubBookingStatus;
use App\Models\Booking;
use App\Models\Folio;
use App\Models\SubBooking;
use App\Services\FolioSettlements;
use App\Services\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Folios never left "open" before: the ones already checked out with nothing due settle
     * now. "Change Folios Locked by the Night Audit" goes to Account Admin and Property Admin.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(FolioSettlements::UNLOCK_PERMISSION, 'web');

        Role::query()
            ->whereIn('name', [PermissionCatalog::ACCOUNT_ADMIN, 'Property Admin'])
            ->where('guard_name', 'web')
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo(FolioSettlements::UNLOCK_PERMISSION));

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Booking::query()
            ->where(fn ($query) => $query
                ->where('status', BookingStatus::CheckedOut)
                ->orWhereHas('subBookings', fn ($room) => $room->where('status', SubBookingStatus::CheckedOut)))
            ->chunkById(200, fn ($bookings) => $bookings->each(fn (Booking $booking) => app(FolioSettlements::class)->refresh($booking)));
    }

    public function down(): void
    {
        Folio::query()->where('status', '!=', FolioStatus::Open)
            ->whereIn('owner_type', [(new Booking)->getMorphClass(), (new SubBooking)->getMorphClass()])
            ->update(['status' => FolioStatus::Open]);

        Permission::query()->where('name', FolioSettlements::UNLOCK_PERMISSION)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
