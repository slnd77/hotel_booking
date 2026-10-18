<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\FolioStatus;
use App\Enums\SubBookingStatus;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Folio;
use App\Models\Property;
use App\Models\SubBooking;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * A booking's folio statuses:
 * - Open while the guest is in house or the bill still owes (or holds credit).
 * - Settled once the booking has checked out with its bill at 0 (payments on the master folio
 *   pay the rooms' charges, so the bill is judged as a whole), or once a room checks out on
 *   its own with its folio at 0.
 * - Locked when the night audit closes the departure day.
 *
 * Posting to a settled folio reopens it, and the next ledger sync settles it again when it
 * balances. A locked folio is reopened only for staff allowed to change locked folios.
 */
class FolioSettlements
{
    public const UNLOCK_PERMISSION = 'reservation.edit_locked_folio';

    /**
     * Settles the booking's folios when they qualify and reopens settled ones that no longer
     * do. Locked folios stay locked.
     */
    public function refresh(Booking $booking): void
    {
        $folios = $booking->allFoliosQuery()->where('status', '!=', FolioStatus::Locked)->with('owner')->get();
        $billSettled = $booking->status === BookingStatus::CheckedOut && abs($booking->outstandingBalance()) < 0.005;

        foreach ($folios as $folio) {
            $settled = $billSettled || ($this->roomCheckedOut($folio) && abs($folio->balance()) < 0.005);
            $status = $settled ? FolioStatus::Settled : FolioStatus::Open;

            if ($folio->status !== $status) {
                $folio->update(['status' => $status]);
            }
        }
    }

    /**
     * Locks the property's settled folios whose stay ended on or before the business date just closed.
     *
     * @return int how many folios were locked
     */
    public function lockForAudit(Property $property, CarbonInterface $businessDate): int
    {
        $date = $businessDate->toDateString();

        return Folio::query()
            ->where('status', FolioStatus::Settled)
            ->where(fn (Builder $query) => $query
                ->whereHasMorph('owner', [Booking::class], fn (Builder $booking) => $booking
                    ->where('property_id', $property->id)
                    ->where('status', BookingStatus::CheckedOut)
                    ->whereDate('check_out', '<=', $date))
                ->orWhereHasMorph('owner', [SubBooking::class], fn (Builder $room) => $room
                    ->whereHas('booking', fn (Builder $booking) => $booking->where('property_id', $property->id))
                    ->where(fn (Builder $ended) => $ended
                        ->where(fn (Builder $own) => $own->where('status', SubBookingStatus::CheckedOut)->whereDate('check_out_date', '<=', $date))
                        ->orWhereHas('booking', fn (Builder $booking) => $booking->where('status', BookingStatus::CheckedOut)->whereDate('check_out', '<=', $date)))))
            ->update(['status' => FolioStatus::Locked, 'updated_at' => now()]);
    }

    /**
     * Opens a settled or locked folio for a new entry.
     *
     * @throws ValidationException when the folio is locked and the signed-in user may not change it
     */
    public function reopen(Folio $folio): void
    {
        $wasLocked = $folio->status === FolioStatus::Locked;
        $user = auth()->user();

        if ($wasLocked && $user !== null && ! app(PermissionGate::class)->allows($user, self::UNLOCK_PERMISSION)) {
            throw ValidationException::withMessages([
                'folio' => "Folio #{$folio->id} was locked by the night audit. Ask a user allowed to change locked folios to make this change.",
            ]);
        }

        $folio->update(['status' => FolioStatus::Open]);

        $booking = $this->bookingOf($folio);

        if ($user !== null && $booking !== null) {
            ActivityLog::create([
                'user_id' => $user->id,
                'property_id' => $booking->property_id,
                'booking_id' => $booking->id,
                'guest_id' => $booking->guest_id,
                'action' => "Booking {$booking->booking_ref_id}: folio #{$folio->id} reopened from ".($wasLocked ? 'locked (night audit)' : 'settled').' for a new entry',
                'date' => now(),
            ]);
        }
    }

    private function roomCheckedOut(Folio $folio): bool
    {
        return $folio->owner instanceof SubBooking && $folio->owner->status === SubBookingStatus::CheckedOut;
    }

    private function bookingOf(Folio $folio): ?Booking
    {
        return match (true) {
            $folio->owner instanceof Booking => $folio->owner,
            $folio->owner instanceof SubBooking => $folio->owner->booking,
            default => null,
        };
    }
}
