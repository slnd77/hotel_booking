<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingCheckInStatus;
use App\Enums\BookingStatus;
use App\Enums\SubBookingStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\SubBooking;
use App\Services\Housekeeping;
use App\Services\InvoiceEmails;
use App\Services\PermissionGate;
use App\Services\RoomStays;
use App\Services\StayRequirements;
use App\Services\StayTimeCharges;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Booking page → Folios: check one room in or out (e.g. an early departure) while
 * the rest of the booking stays. The booking status follows from its rooms.
 */
class RoomStayController extends Controller
{
    public function update(Request $request, Property $property, Booking $booking, SubBooking $subBooking, RoomStays $stays, StayRequirements $requirements): RedirectResponse
    {
        abort_unless($booking->property_id === $property->id && $subBooking->booking_id === $booking->id, 404);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:booked,checked_in,checked_out'],
            ...RoomStays::reportedAtRules(),
        ], ['reported_at.before_or_equal' => 'The reported time can\'t be in the future.']);
        $to = SubBookingStatus::from($validated['status']);
        $at = RoomStays::reportedAt($validated['reported_at'] ?? null);
        $waive = $this->waivesStayTimeCharges($request);

        $action = $stays->actionFor($subBooking->status, $to)
            ?? throw ValidationException::withMessages(['status' => "A {$stays->label($subBooking->status)} room can't be moved to {$stays->label($to)}."]);

        app(PermissionGate::class)->require($request->user(), match ($action) {
            'check in' => 'reservation.check_in',
            'check out' => 'reservation.check_out',
            'undo check-in' => 'reservation.undo_check_in',
            default => 'reservation.undo_check_out',
        });

        if (in_array($action, ['check in', 'check out'], true)) {
            $missing = $requirements->missing($booking, $action === 'check in');

            if ($missing !== []) {
                return back()->with('error', "Can't {$action} yet. ".implode(' ', $missing));
            }
        }

        if ($action === 'check out') {
            try {
                $stays->ensureCanCheckOut($booking, $stays->amountDueForCheckOut($booking, new EloquentCollection([$subBooking])) + app(StayTimeCharges::class)->lateCheckOutTotal([$subBooking], $at, $waive), $request->user());
            } catch (ValidationException $exception) {
                return back()->with('error', collect($exception->errors())->flatten()->first());
            }
        }

        // Housekeeping: a room that isn't ready is refused (hard stop) or needs "Check in anyway".
        if ($action === 'check in') {
            try {
                app(Housekeeping::class)->ensureCanCheckIn($property, [$subBooking], $request->boolean('override_housekeeping'));
            } catch (ValidationException $exception) {
                return back()->with('error', collect($exception->errors())->flatten()->first());
            }
        }

        $roomName = $subBooking->inventoryUnit->name ?? 'Unassigned room';

        try {
            $derived = DB::transaction(function () use ($stays, $subBooking, $to, $booking, $action, $at, $waive): ?BookingStatus {
                // One room leaving before the others gets its own dates: the booking is split first.
                if ($action === 'check out' && ! $booking->rooms_split && $booking->subBookings()->where('status', SubBookingStatus::CheckedIn)->whereKeyNot($subBooking->id)->exists()) {
                    $stays->split($booking);
                }

                $stays->changeRoom($subBooking, $to, $at, $waive);

                return $this->syncBookingStatus($booking, $stays);
            });
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->first());
        }

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $property->id,
            'booking_id' => $booking->id,
            'guest_id' => $booking->guests()->first()?->id,
            'action' => 'StayStatus : '.ucfirst($action)." for {$roomName} via Web",
            'date' => now(),
        ]);

        // A room checked out from its folio takes the check-out default housekeeping status (e.g. Dirty),
        // one checked in the check-in default.
        if ($action === 'check out') {
            app(Housekeeping::class)->markCheckedOut($property, [$subBooking->fresh()]);
        } elseif ($action === 'check in') {
            app(Housekeeping::class)->markCheckedIn($property, [$subBooking->fresh()]);
        }

        $message = ucfirst($action)." done for {$roomName}.";

        if ($derived !== null && $booking->wasChanged('status')) {
            $message .= " The booking is now {$derived->label()}.";
        }

        return back()->with('success', $message);
    }

    /**
     * Booking page → CHECK IN on a booking with several rooms: checks in the rooms the
     * front desk picked; the others keep waiting.
     */
    public function checkInRooms(Request $request, Property $property, Booking $booking, RoomStays $stays, StayRequirements $requirements): RedirectResponse
    {
        return $this->moveSelectedRooms($request, $property, $booking, $stays, $requirements, SubBookingStatus::CheckedIn);
    }

    /**
     * Booking page → CHECK OUT on a booking with several rooms in house: checks out the rooms
     * the front desk picked. When others stay on, the booking is split so each room keeps its
     * own dates.
     */
    public function checkOutRooms(Request $request, Property $property, Booking $booking, RoomStays $stays, StayRequirements $requirements): RedirectResponse
    {
        return $this->moveSelectedRooms($request, $property, $booking, $stays, $requirements, SubBookingStatus::CheckedOut);
    }

    private function moveSelectedRooms(Request $request, Property $property, Booking $booking, RoomStays $stays, StayRequirements $requirements, SubBookingStatus $to): RedirectResponse
    {
        abort_unless($booking->property_id === $property->id, 404);

        $action = $to === SubBookingStatus::CheckedIn ? 'check in' : 'check out';

        $validated = $request->validate([
            'sub_booking_ids' => ['required', 'array', 'min:1'],
            'sub_booking_ids.*' => ['integer', Rule::exists('sub_bookings', 'id')->where('booking_id', $booking->id)],
            ...RoomStays::reportedAtRules(),
            'housekeeping_status' => ['nullable', 'string', Rule::in(app(Housekeeping::class)->statuses($property)->pluck('name'))],
        ], [
            'sub_booking_ids.required' => "Select at least one room to {$action}.",
            'reported_at.before_or_equal' => 'The reported time can\'t be in the future.',
        ]);
        $at = RoomStays::reportedAt($validated['reported_at'] ?? null);
        $waive = $this->waivesStayTimeCharges($request);

        $missing = $requirements->missing($booking, $to === SubBookingStatus::CheckedIn);

        if ($missing !== []) {
            return back()->with('error', "Can't {$action} yet. ".implode(' ', $missing));
        }

        $rooms = $booking->subBookings()->whereKey($validated['sub_booking_ids'])->with(['inventoryUnit', 'folio'])->orderBy('id')->get();

        // Housekeeping: a room that isn't ready is refused (hard stop) or needs "Check in anyway".
        if ($to === SubBookingStatus::CheckedIn) {
            try {
                app(Housekeeping::class)->ensureCanCheckIn($property, $rooms, $request->boolean('override_housekeeping'));
            } catch (ValidationException $exception) {
                return back()->with('error', collect($exception->errors())->flatten()->first());
            }
        }

        // A pay-at-hotel booking settles at the desk: what's leaving must be paid first. Others leave a balance only with the permission.
        if ($to === SubBookingStatus::CheckedOut) {
            try {
                $stays->ensureCanCheckOut($booking, $stays->amountDueForCheckOut($booking, $rooms) + app(StayTimeCharges::class)->lateCheckOutTotal($rooms, $at, $waive), $request->user());
            } catch (ValidationException $exception) {
                return back()->with('error', collect($exception->errors())->flatten()->first());
            }
        }

        try {
            $derived = DB::transaction(function () use ($rooms, $stays, $booking, $to, $at, $waive): ?BookingStatus {
                // Rooms leaving before the others get their own dates: the booking is split first.
                if ($to === SubBookingStatus::CheckedOut && ! $booking->rooms_split
                    && $booking->subBookings()->where('status', SubBookingStatus::CheckedIn)->whereKeyNot($rooms->modelKeys())->exists()) {
                    $stays->split($booking);
                }

                $rooms->each(fn (SubBooking $room) => $stays->changeRoom($room, $to, $at, $waive));

                return $this->syncBookingStatus($booking, $stays);
            });
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->first());
        }

        $roomNames = $rooms->map(fn (SubBooking $room): string => $room->inventoryUnit->name ?? 'Unassigned room')->implode(', ');

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $property->id,
            'booking_id' => $booking->id,
            'guest_id' => $booking->guests()->first()?->id,
            'action' => 'StayStatus : '.ucfirst($action)." for {$roomNames} via Web",
            'date' => now(),
        ]);

        // Check-out → "Mark room as": the rooms left take that housekeeping status (default: the check-out default).
        if ($to === SubBookingStatus::CheckedOut) {
            app(Housekeeping::class)->markCheckedOut($property, $rooms, $validated['housekeeping_status'] ?? null);
        } else {
            app(Housekeeping::class)->markCheckedIn($property, $rooms);
        }

        $message = ($to === SubBookingStatus::CheckedIn ? 'Checked in' : 'Checked out')." {$roomNames}.";

        if ($derived !== null && $booking->wasChanged('status')) {
            $message .= " The booking is now {$derived->label()}.";
        }

        // Check-out → "Send invoice to guest": the final invoice once the last room is out, a proforma before that.
        if ($to === SubBookingStatus::CheckedOut && $request->boolean('send_invoice')) {
            $invoiceProblem = app(InvoiceEmails::class)->sendToGuest($booking->fresh(), $request->user());

            if ($invoiceProblem !== null) {
                session()->flash('warning', $invoiceProblem);
            } else {
                $message .= ' The invoice was e-mailed to the guest.';
            }
        }

        return back()->with('success', $message);
    }

    /**
     * "Waive the charge" on an early check-in or late check-out: only for staff allowed to.
     */
    private function waivesStayTimeCharges(Request $request): bool
    {
        if (! $request->boolean('waive_stay_time_charges')) {
            return false;
        }

        app(PermissionGate::class)->require($request->user(), 'reservation.waive_stay_charges');

        return true;
    }

    /**
     * The booking's status follows its rooms; the last room out generates the invoice.
     */
    private function syncBookingStatus(Booking $booking, RoomStays $stays): ?BookingStatus
    {
        $derived = $stays->derivedBookingStatus($booking);

        if ($derived !== null && $derived !== $booking->status) {
            $booking->update([
                'status' => $derived,
                'check_in_status' => match ($derived) {
                    BookingStatus::CheckedIn => BookingCheckInStatus::CheckIn,
                    BookingStatus::CheckedOut => BookingCheckInStatus::CheckOut,
                    default => null,
                },
            ]);

            if ($derived === BookingStatus::CheckedOut) {
                Invoice::generateForBooking($booking, request()->user()->name);
            }
        }

        return $derived;
    }
}
