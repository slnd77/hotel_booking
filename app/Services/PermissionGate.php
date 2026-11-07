<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Property;
use App\Models\User;

/**
 * Permission checks that depend on what is being done rather than the page: reservations by
 * kind (confirm, hold, enquiry) and by date (today, past, future in the property's calendar),
 * room blocks by date, and room status changes.
 */
class PermissionGate
{
    /**
     * Abort unless the user may create, edit or cancel this kind of reservation for this date.
     */
    public function reservation(?User $user, Property $property, string $action, BookingStatus|string $status, string $checkIn): void
    {
        $kind = $this->reservationKind($status);
        $when = $this->when($property, $checkIn);
        $label = PermissionCatalog::RESERVATION_KINDS[$kind] === 'Enquiry' ? 'enquiry' : 'reservation';

        $this->require($user, "reservations.{$kind}.{$action}_{$when}", "You don't have permission to {$action} a {$label} for ".match ($when) {
            'today' => 'today',
            'past' => 'a past date',
            default => 'a future date',
        }.'.');
    }

    /**
     * Abort unless the user may block (or unblock) rooms from this date.
     */
    public function roomBlock(?User $user, Property $property, string $action, string $startDate): void
    {
        $when = $this->when($property, $startDate);

        $this->require($user, "smart_chart.{$action}_{$when}", "You don't have permission to {$action} rooms for ".($when === 'today' ? 'today' : ($when === 'past' ? 'a past date' : 'a future date')).'.');
    }

    /**
     * Abort unless the user may set a room's housekeeping status to this one.
     */
    public function housekeeping(?User $user, string $status): void
    {
        $this->require($user, 'housekeeping.change_status', "You don't have permission to change a room's status.");

        $this->require($user, PermissionCatalog::housekeepingMark($status), "You don't have permission to mark a room as {$status}.");
    }

    /**
     * Abort unless the user has the permission (the super admin always has).
     */
    public function require(?User $user, string $permission, ?string $message = null): void
    {
        abort_unless($this->allows($user, $permission), 403, $message ?? "You don't have permission to do this.");
    }

    public function allows(?User $user, string $permission): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasAnyRole(PermissionCatalog::fullAccessRoles()) || $user->checkPermissionTo($permission);
    }

    /**
     * confirm, hold or enquiry.
     */
    public function reservationKind(BookingStatus|string $status): string
    {
        $status = $status instanceof BookingStatus ? $status : BookingStatus::tryFrom($status);

        return match ($status) {
            BookingStatus::OnHold => 'hold',
            BookingStatus::Enquiry, BookingStatus::ExpiredQuote => 'enquiry',
            default => 'confirm',
        };
    }

    /**
     * today, past or future, in the property's own calendar.
     */
    public function when(Property $property, string $date): string
    {
        $today = $property->today();
        $date = substr($date, 0, 10);

        return $date === $today ? 'today' : ($date < $today ? 'past' : 'future');
    }
}
