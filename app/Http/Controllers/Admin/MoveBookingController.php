<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\SubBooking;
use App\Services\BookingPricing;
use App\Services\PermissionGate;
use App\Services\RoomInventory;
use App\Services\RoomStays;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Drag-and-drop on the smart chart: moves one room of a booking to another
 * room of the same category and/or shifts the whole stay, keeping its length
 * and prices.
 */
class MoveBookingController extends Controller
{
    public function __invoke(Request $request, Property $property, Booking $booking, RoomInventory $roomInventory, RoomStays $stays): JsonResponse
    {
        abort_unless($booking->property_id === $property->id, 404);

        $data = $request->validate([
            'sub_booking_id' => ['required', 'integer'],
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            // "room": only this room's dates move (the booking is split); "booking": every room moves.
            'scope' => ['nullable', 'in:booking,room'],
        ]);

        $movedRoom = $booking->rooms()->findOrFail($request->integer('sub_booking_id'));

        // Roles and Permissions: a stay arriving today can only be dragged with this permission.
        if ($movedRoom->check_in_date->toDateString() === $property->today()) {
            app(PermissionGate::class)->require($request->user(), 'smart_chart.drag_drop_starting_today', "You don't have permission to move a reservation starting today.");
        }
        $targetRoom = Room::query()->findOrFail($request->integer('room_id'));

        if ($targetRoom->room_category_id !== $movedRoom->room_category_id) {
            $categoryName = RoomCategory::query()->whereKey($movedRoom->room_category_id)->value('name');

            throw ValidationException::withMessages(['room_id' => "This booking can only move to another {$categoryName} room, so its rate stays valid."]);
        }

        $roomCheckIn = $movedRoom->check_in_date->toDateString();

        if (($data['scope'] ?? 'booking') === 'room' && $data['check_in'] !== $roomCheckIn) {
            return $this->moveOneRoom($request, $property, $booking, $movedRoom, $targetRoom, $data['check_in'], $stays);
        }

        if ($booking->rooms_split) {
            return $this->moveSplitBooking($request, $property, $booking, $movedRoom, $targetRoom, $data['check_in'], $roomInventory, $stays);
        }

        $currentCheckIn = $booking->check_in->toDateString();
        $datesChange = $data['check_in'] !== $currentCheckIn;

        $this->ensureMovable($booking, $datesChange, $data['check_in']);

        $nights = (int) $booking->check_in->diffInDays($booking->check_out);
        $checkIn = $data['check_in'];
        $checkOut = CarbonImmutable::parse($checkIn)->addDays($nights)->toDateString();

        // The booking's rooms as they would be after the move.
        $rooms = $booking->rooms()->get()->map(fn (SubBooking $room): array => [
            'room_category_id' => $room->room_category_id,
            'room_id' => $room->is($movedRoom) ? $targetRoom->id : $room->room_id,
        ]);

        $this->ensureRoomsAreFree($property, $booking, array_values($rooms->all()), $checkIn, $checkOut, $roomInventory);

        $previousRoomName = $movedRoom->room->name ?? 'unassigned';

        DB::transaction(function () use ($booking, $movedRoom, $targetRoom, $checkIn, $checkOut, $datesChange): void {
            if ($datesChange) {
                $booking->update(['check_in' => $checkIn, 'check_out' => $checkOut]);
            }

            $movedRoom->update(['room_id' => $targetRoom->id]);
        });

        $changes = array_filter([
            $previousRoomName !== $targetRoom->name ? "{$previousRoomName} → {$targetRoom->name}" : null,
            $datesChange ? CarbonImmutable::parse($currentCheckIn)->format('d M').' → '.CarbonImmutable::parse($checkIn)->format('d M Y') : null,
        ]);

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $property->id,
            'booking_id' => $booking->id,
            'guest_id' => $booking->guests()->first()?->id,
            'action' => 'Reservation moved on Smart Chart: '.implode(', ', $changes),
            'date' => now(),
        ]);

        return response()->json(['moved' => true]);
    }

    /**
     * Only the dragged room shifts (same length); the booking becomes split.
     */
    private function moveOneRoom(Request $request, Property $property, Booking $booking, SubBooking $movedRoom, Room $targetRoom, string $checkIn, RoomStays $stays): JsonResponse
    {
        $this->ensureMovable($booking, false, $checkIn);

        $nights = BookingPricing::nights($movedRoom->check_in_date->toDateString(), $movedRoom->check_out_date->toDateString());
        $checkOut = CarbonImmutable::parse($checkIn)->addDays($nights)->toDateString();
        $from = $movedRoom->check_in_date->format('d M');
        $previousRoomName = $movedRoom->room->name ?? 'unassigned';

        $price = $stays->changeRoomDates($movedRoom, $checkIn, $checkOut, $targetRoom->id);

        $this->log($request, $property, $booking, array_values(array_filter([
            "{$previousRoomName} only",
            $previousRoomName !== $targetRoom->name ? "{$previousRoomName} → {$targetRoom->name}" : null,
            $from.' → '.CarbonImmutable::parse($checkIn)->format('d M Y'),
        ])));

        return response()->json(['moved' => true, 'price' => $price]);
    }

    /**
     * A split booking moved as a whole: every room shifts by the same number of days,
     * each keeping its own dates relative to the others.
     */
    private function moveSplitBooking(Request $request, Property $property, Booking $booking, SubBooking $movedRoom, Room $targetRoom, string $checkIn, RoomInventory $roomInventory, RoomStays $stays): JsonResponse
    {
        $shift = (int) CarbonImmutable::parse($movedRoom->check_in_date->toDateString())->diffInDays(CarbonImmutable::parse($checkIn), false);
        $this->ensureMovable($booking, $shift !== 0, $booking->check_in->addDays($shift)->toDateString());

        $rooms = $booking->rooms()->get()->map(fn (SubBooking $room): array => [
            'room' => $room,
            'room_id' => $room->is($movedRoom) ? $targetRoom->id : $room->room_id,
            'check_in' => $room->check_in_date->addDays($shift)->toDateString(),
            'check_out' => $room->check_out_date->addDays($shift)->toDateString(),
        ]);

        $dormRoomIds = $roomInventory->activeBedsByRoom(null, $property->id)->keys();

        // Several beds of one booking can share a dorm room (each stay is put on its own bed).
        if ($rooms->pluck('room_id')->filter()->reject(fn ($roomId): bool => $dormRoomIds->contains((int) $roomId))->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['room_id' => 'Another room of this booking is already in that room.']);
        }

        foreach ($rooms as $room) {
            if ($room['room_id'] && $roomInventory->unavailableRoomIds($property->id, $room['check_in'], $room['check_out'], $booking->id)->contains($room['room_id'])) {
                throw ValidationException::withMessages(['room_id' => 'Already booked or blocked for those dates: '.Room::query()->whereKey($room['room_id'])->value('name').'.']);
            }
        }

        DB::transaction(function () use ($rooms, $booking, $stays): void {
            foreach ($rooms as $room) {
                $room['room']->update(['room_id' => $room['room_id'], 'check_in_date' => $room['check_in'], 'check_out_date' => $room['check_out']]);
            }

            $stays->respanBooking($booking->fresh());
        });

        $this->log($request, $property, $booking, ["all rooms shifted {$shift} day(s)"]);

        return response()->json(['moved' => true]);
    }

    /**
     * @param  list<string>  $changes
     */
    private function log(Request $request, Property $property, Booking $booking, array $changes): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $property->id,
            'booking_id' => $booking->id,
            'guest_id' => $booking->guests()->first()?->id,
            'action' => 'Reservation moved on Smart Chart: '.implode(', ', $changes),
            'date' => now(),
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function ensureMovable(Booking $booking, bool $datesChange, string $checkIn): void
    {
        if (in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::CheckedOut, BookingStatus::NoShow], true)) {
            throw ValidationException::withMessages(['booking' => "A {$booking->status->label()} booking can't be moved."]);
        }

        if ($datesChange && $booking->status === BookingStatus::CheckedIn) {
            throw ValidationException::withMessages(['check_in' => 'The guest is already checked in, so only the room can change, not the dates.']);
        }

        if ($datesChange && $checkIn < $booking->property->today()) {
            throw ValidationException::withMessages(['check_in' => 'A booking cannot be moved into the past.']);
        }
    }

    /**
     * @param  list<array{room_category_id: int, room_id: int|null}>  $rooms
     *
     * @throws ValidationException
     */
    private function ensureRoomsAreFree(Property $property, Booking $booking, array $rooms, string $checkIn, string $checkOut, RoomInventory $roomInventory): void
    {
        $assignedRoomIds = collect($rooms)->pluck('room_id')->filter();
        $dormRoomIds = $roomInventory->activeBedsByRoom(null, $property->id)->keys();

        if ($assignedRoomIds->reject(fn ($roomId): bool => $dormRoomIds->contains((int) $roomId))->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['room_id' => 'Another room of this booking is already in that room.']);
        }

        $takenRoomNames = Room::query()
            ->whereIn('id', $assignedRoomIds->intersect($roomInventory->unavailableRoomIds($property->id, $checkIn, $checkOut, $booking->id)))
            ->orderBy('name')
            ->pluck('name');

        if ($takenRoomNames->isNotEmpty()) {
            throw ValidationException::withMessages(['room_id' => 'Already booked or blocked for those dates: '.$takenRoomNames->implode(', ').'.']);
        }

        $roomsCountByCategory = $roomInventory->capacityByCategory($property->id);
        $availability = $roomInventory->availabilityByCategory($property->id, $roomsCountByCategory, $checkIn, $checkOut, $booking->id);
        $shortCategory = collect($rooms)->countBy('room_category_id')
            ->first(fn (int $needed, int $categoryId): bool => $needed > (int) ($availability[$categoryId] ?? 0));

        if ($shortCategory !== null) {
            throw ValidationException::withMessages(['check_in' => 'Not enough free rooms for those dates.']);
        }
    }
}
