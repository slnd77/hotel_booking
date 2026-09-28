<?php

namespace App\Services;

use App\Enums\SubBookingStatus;
use App\Models\InventoryUnit;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomCategory;
use App\Models\SubBooking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Single source of truth for which rooms are taken for a stay, read from the
 * inventory layer (sub_bookings → inventory_units).
 *
 * A room is unavailable for [checkIn, checkOut) when an occupying stay (see
 * SubBookingStatus::occupying) is on it or a room block covers any of those nights.
 * Stays without an assigned unit still consume one room of their category.
 * A guest still in house past their check-out date (an overstay) keeps the room
 * every night up to and including today, until they actually check out.
 *
 * Dormitory categories (sold by the bed) count beds instead: a dorm room stays available
 * while one of its beds is free for every night, and a block takes all of its free beds.
 */
class RoomInventory
{
    /**
     * IDs of rooms that are booked or blocked for at least one night of the stay.
     * Pass $excludeBookingId when editing a booking so its own rooms stay selectable,
     * or $excludeSubBookingId when moving just one of its rooms.
     *
     * @return Collection<int, int>
     */
    public function unavailableRoomIds(int $propertyId, string $checkIn, string $checkOut, ?int $excludeBookingId = null, ?int $excludeSubBookingId = null): Collection
    {
        $stays = $this->occupiedStays($propertyId, $checkIn, $checkOut, $excludeBookingId, $excludeSubBookingId)
            ->whereNotNull('inventory_units.room_id')
            ->get(['sub_bookings.inventory_unit_id', 'inventory_units.room_id']);
        $bedsByRoom = $this->activeBedsByRoom(array_values($stays->pluck('room_id')->map(fn ($id): int => (int) $id)->unique()->all()));

        // A dorm room is taken only when none of its beds is free for the whole stay.
        $bookedRoomIds = $stays->groupBy('room_id')
            ->filter(fn (Collection $roomStays, int|string $roomId): bool => ! isset($bedsByRoom[$roomId])
                || $roomStays->pluck('inventory_unit_id')->unique()->count() >= $bedsByRoom[$roomId])
            ->keys();

        $blockedRoomIds = RoomBlock::query()
            ->where('property_id', $propertyId)
            ->overlapping($checkIn, $checkOut)
            ->pluck('room_id');

        return $bookedRoomIds->merge($blockedRoomIds)->map(fn ($id): int => (int) $id)->unique()->values();
    }

    /**
     * Beds still free for the whole stay in each dorm room of the property (room id => beds).
     *
     * @return Collection<int, int>
     */
    public function freeBedsByRoom(int $propertyId, string $checkIn, string $checkOut, ?int $excludeBookingId = null, ?int $excludeSubBookingId = null): Collection
    {
        $takenBeds = $this->occupiedStays($propertyId, $checkIn, $checkOut, $excludeBookingId, $excludeSubBookingId)
            ->whereNotNull('inventory_units.room_id')
            ->get(['sub_bookings.inventory_unit_id', 'inventory_units.room_id'])
            ->groupBy('room_id')
            ->map(fn (Collection $roomStays): int => $roomStays->pluck('inventory_unit_id')->unique()->count());
        $blockedRoomIds = RoomBlock::query()->where('property_id', $propertyId)->overlapping($checkIn, $checkOut)->pluck('room_id')->flip();

        return $this->activeBedsByRoom(null, $propertyId)
            ->map(fn (int $beds, int $roomId): int => $blockedRoomIds->has($roomId) ? 0 : $this->atLeastZero($beds - (int) ($takenBeds[$roomId] ?? 0)));
    }

    /**
     * What each category has to sell: its rooms, or for a dormitory its active beds.
     *
     * @return Collection<int, int> keyed by room category id
     */
    public function capacityByCategory(int $propertyId): Collection
    {
        $beds = InventoryUnit::query()
            ->where('property_id', $propertyId)
            ->where('unit_type', InventoryUnit::TYPE_BED)
            ->where('is_active', true)
            ->selectRaw('room_category_id, count(*) as beds')
            ->groupBy('room_category_id')
            ->pluck('beds', 'room_category_id');

        return RoomCategory::query()
            ->where('property_id', $propertyId)
            ->withCount('rooms')
            ->get(['id', 'sell_by_bed'])
            ->mapWithKeys(fn (RoomCategory $category): array => [
                $category->id => $category->sell_by_bed ? (int) ($beds[$category->id] ?? 0) : (int) $category->rooms_count,
            ]);
    }

    /**
     * Rooms (or dorm beds) left per category for the whole stay: its capacity minus stays
     * (assigned or not) minus what blocks take — a blocked room, or a blocked dorm's free beds.
     *
     * @param  Collection<int, int>  $roomsCountByCategory  keyed by room category id (see capacityByCategory)
     * @return Collection<int, int>
     */
    public function availabilityByCategory(int $propertyId, Collection $roomsCountByCategory, string $checkIn, string $checkOut, ?int $excludeBookingId = null, ?int $excludeSubBookingId = null): Collection
    {
        $stays = $this->occupiedStays($propertyId, $checkIn, $checkOut, $excludeBookingId, $excludeSubBookingId)
            ->get(['sub_bookings.room_category_id', 'sub_bookings.inventory_unit_id', 'inventory_units.room_id']);

        $bookedCounts = $stays->countBy('room_category_id');
        $takenUnitsByRoom = $stays->whereNotNull('room_id')->groupBy('room_id')
            ->map(fn (Collection $roomStays): int => $roomStays->pluck('inventory_unit_id')->unique()->count());

        $blockedRooms = RoomBlock::query()
            ->where('room_blocks.property_id', $propertyId)
            ->overlapping($checkIn, $checkOut)
            ->join('rooms', 'rooms.id', '=', 'room_blocks.room_id')
            ->distinct()
            ->get(['room_blocks.room_id', 'rooms.room_category_id']);
        $bedsByRoom = $this->activeBedsByRoom(array_values($blockedRooms->pluck('room_id')->map(fn ($id): int => (int) $id)->all()));

        $blockedCounts = $blockedRooms->groupBy('room_category_id')->map(fn (Collection $rooms): int => (int) $rooms->sum(
            fn (RoomBlock $block): int => isset($bedsByRoom[$block->room_id])
                ? max(0, $bedsByRoom[$block->room_id] - (int) ($takenUnitsByRoom[$block->room_id] ?? 0))
                : (isset($takenUnitsByRoom[$block->room_id]) ? 0 : 1),
        ));

        return $roomsCountByCategory->map(fn (int $total, int $categoryId): int => $this->atLeastZero(
            $total - (int) ($bookedCounts[$categoryId] ?? 0) - (int) ($blockedCounts[$categoryId] ?? 0),
        ));
    }

    /**
     * A count of what is left, which never goes below zero.
     */
    private function atLeastZero(int $count): int
    {
        return max(0, $count);
    }

    /**
     * Active bed count of dorm rooms (rooms of a category sold by the bed), by room id.
     *
     * @param  list<int|string>|null  $roomIds  only these rooms
     * @return Collection<int, int>
     */
    public function activeBedsByRoom(?array $roomIds = null, ?int $propertyId = null): Collection
    {
        return InventoryUnit::query()
            ->when($propertyId !== null, fn (Builder $query) => $query->where('property_id', $propertyId))
            ->where('unit_type', InventoryUnit::TYPE_BED)
            ->where('is_active', true)
            ->whereHas('roomCategory', fn (Builder $query) => $query->where('sell_by_bed', true))
            ->when($roomIds !== null, fn (Builder $query) => $query->whereIn('room_id', $roomIds))
            ->selectRaw('room_id, count(*) as beds')
            ->groupBy('room_id')
            ->pluck('beds', 'room_id')
            ->map(fn ($beds): int => (int) $beds);
    }

    /**
     * Which of the given rooms have an occupying stay overlapping [startDate, endDate).
     *
     * @param  list<int>  $roomIds
     * @return Collection<int, int>
     */
    public function bookedRoomIds(array $roomIds, string $startDate, string $endDate): Collection
    {
        return $this->occupyingOverlap(SubBooking::query(), $startDate, $endDate, now()->toDateString())
            ->join('inventory_units', 'inventory_units.id', '=', 'sub_bookings.inventory_unit_id')
            ->whereIn('inventory_units.room_id', $roomIds)
            ->pluck('inventory_units.room_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * First free room of a category, skipping rooms already picked for the same booking.
     * For a dormitory, pass the free beds per room ($freeBeds, see freeBedsByRoom): the first
     * room with a bed left is used, and its count goes down — so a group fills a dorm first.
     *
     * @param  Collection<int, int>  $unavailableRoomIds
     * @param  Collection<int, int>|null  $freeBeds
     */
    public function firstFreeRoomId(int $roomCategoryId, Collection $unavailableRoomIds, ?Collection $freeBeds = null): ?int
    {
        $rooms = Room::query()->where('room_category_id', $roomCategoryId)->orderBy('display_order')->orderBy('name')->pluck('id');

        if ($freeBeds !== null && RoomCategory::query()->whereKey($roomCategoryId)->value('sell_by_bed')) {
            $roomId = $rooms->first(fn (int $id): bool => ($freeBeds[$id] ?? 0) > 0);

            if ($roomId !== null) {
                $freeBeds->put($roomId, $freeBeds[$roomId] - 1);
            }

            return $roomId;
        }

        return $rooms->first(fn (int $id): bool => ! $unavailableRoomIds->contains($id));
    }

    /**
     * Occupying stays of a property overlapping [checkIn, checkOut), with their unit's room.
     *
     * @return Builder<SubBooking>
     */
    public function occupiedStays(int $propertyId, string $checkIn, string $checkOut, ?int $excludeBookingId = null, ?int $excludeSubBookingId = null): Builder
    {
        return $this->occupyingOverlap(SubBooking::query(), $checkIn, $checkOut, $this->today($propertyId))
            ->join('bookings', 'bookings.id', '=', 'sub_bookings.booking_id')
            ->leftJoin('inventory_units', 'inventory_units.id', '=', 'sub_bookings.inventory_unit_id')
            ->where('bookings.property_id', $propertyId)
            ->when($excludeBookingId !== null, fn (Builder $query) => $query->where('sub_bookings.booking_id', '!=', $excludeBookingId))
            // Moving one room of a booking: only that room's own stay is set aside.
            ->when($excludeSubBookingId !== null, fn (Builder $query) => $query->where('sub_bookings.id', '!=', $excludeSubBookingId));
    }

    /**
     * Inventory units (rooms, or dorm beds) with a guest in house right now: a checked-in stay
     * that hasn't checked out, whatever its dates. Pass $excludeBookingId to leave a booking's
     * own rooms out (its guests may be in house in them already).
     *
     * @return Collection<int, int> unit id => booking id
     */
    public function inHouseUnitIds(int $propertyId, ?int $excludeBookingId = null): Collection
    {
        return SubBooking::query()
            ->join('bookings', 'bookings.id', '=', 'sub_bookings.booking_id')
            ->where('bookings.property_id', $propertyId)
            ->where('sub_bookings.status', SubBookingStatus::CheckedIn)
            ->whereNotNull('sub_bookings.inventory_unit_id')
            ->when($excludeBookingId !== null, fn (Builder $query) => $query->where('sub_bookings.booking_id', '!=', $excludeBookingId))
            ->pluck('sub_bookings.booking_id', 'sub_bookings.inventory_unit_id')
            ->map(fn ($id): int => (int) $id);
    }

    /**
     * The property's business date (see Property::today()).
     */
    private function today(int $propertyId): string
    {
        return Property::query()->find($propertyId, ['id', 'timezone'])?->today() ?? now()->toDateString();
    }

    /**
     * Stays overlapping [checkIn, checkOut): on their own dates, or — for a guest still in
     * house past the check-out date — on every night up to and including today.
     *
     * @param  Builder<SubBooking>  $query
     * @return Builder<SubBooking>
     */
    private function occupyingOverlap(Builder $query, string $checkIn, string $checkOut, string $today): Builder
    {
        // An overstay's effective check-out is tomorrow: it overlaps the stay when today >= checkIn.
        $overstayReachesStay = $today >= $checkIn;

        return $query
            ->whereIn('sub_bookings.status', SubBookingStatus::occupying())
            ->whereDate('sub_bookings.check_in_date', '<', $checkOut)
            ->where(fn (Builder $overlap) => $overlap
                ->whereDate('sub_bookings.check_out_date', '>', $checkIn)
                ->when($overstayReachesStay, fn (Builder $query) => $query->orWhere(fn (Builder $overstay) => $overstay
                    ->where('sub_bookings.status', SubBookingStatus::CheckedIn)
                    ->whereDate('sub_bookings.check_out_date', '<=', $today))));
    }
}
