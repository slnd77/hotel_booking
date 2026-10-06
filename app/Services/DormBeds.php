<?php

namespace App\Services;

use App\Enums\SubBookingStatus;
use App\Models\InventoryUnit;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\SubBooking;
use Illuminate\Validation\ValidationException;

/**
 * Dormitories: a room category sold by the bed. Each of its rooms has `bed_count` bed
 * units ("Bed 1", "Bed 2"…) that stays occupy one at a time; its whole-room unit is set
 * aside. Housekeeping and room blocks stay on the physical room, shared by its beds.
 */
class DormBeds
{
    /**
     * Brings a room's units in line with its category: bed units 1..bed_count active (made
     * when missing) and the whole-room unit inactive for a dormitory — the reverse for a
     * category sold by the room. Beds are deactivated, never deleted, so past stays keep them.
     */
    public function syncRoom(Room $room): void
    {
        $room->loadMissing('roomCategory');
        $byBed = (bool) $room->roomCategory?->sell_by_bed;
        $beds = $byBed ? max(0, (int) $room->bed_count) : 0;

        InventoryUnit::forRoom($room->id)->update(['is_active' => ! $byBed, 'name' => $room->name, 'room_category_id' => $room->room_category_id]);

        for ($number = 1; $number <= $beds; $number++) {
            InventoryUnit::query()->updateOrCreate(
                ['room_id' => $room->id, 'unit_type' => InventoryUnit::TYPE_BED, 'name' => "Bed {$number}"],
                ['property_id' => $room->roomCategory->property_id, 'room_category_id' => $room->room_category_id, 'capacity' => 1, 'is_active' => true],
            );
        }

        InventoryUnit::query()
            ->where('room_id', $room->id)
            ->where('unit_type', InventoryUnit::TYPE_BED)
            ->get()
            ->filter(fn (InventoryUnit $bed): bool => $this->bedNumber($bed) > $beds)
            ->each(fn (InventoryUnit $bed) => $bed->update(['is_active' => false]));
    }

    public function syncCategory(RoomCategory $category): void
    {
        $category->rooms()->with('roomCategory')->get()->each(fn (Room $room) => $this->syncRoom($room));
    }

    /**
     * Before a stay in a dormitory is saved: the bed it occupies. Keeps its bed when that's
     * still in the chosen room and free for its nights; otherwise the room's first free bed.
     *
     * @throws ValidationException when every bed of the room is taken for those nights
     */
    public function assignBed(SubBooking $stay): void
    {
        $unit = $stay->inventory_unit_id ? InventoryUnit::query()->with('roomCategory', 'room')->find($stay->inventory_unit_id) : null;

        if (! $unit || ! $unit->roomCategory?->sell_by_bed || ! $unit->room_id) {
            return;
        }

        $original = $stay->getOriginal('inventory_unit_id');
        $candidates = InventoryUnit::query()
            ->where('room_id', $unit->room_id)
            ->where('unit_type', InventoryUnit::TYPE_BED)
            ->where('is_active', true)
            ->get()
            ->sortBy(fn (InventoryUnit $bed): array => [
                // Its own bed first (unchanged or the one it had), then by bed number.
                $bed->id === ($unit->unit_type === InventoryUnit::TYPE_BED ? $unit->id : $original) ? 0 : 1,
                $this->bedNumber($bed),
            ]);

        $occupying = in_array($stay->status, SubBookingStatus::occupying(), true);
        $free = $candidates->first(fn (InventoryUnit $bed): bool => ! $occupying || $this->bedIsFree($bed->id, $stay));

        if (! $free) {
            throw ValidationException::withMessages(['rooms' => "Every bed in {$unit->room->name} is taken for {$stay->check_in_date->toDateString()} → {$stay->check_out_date->toDateString()}."]);
        }

        $stay->inventoryUnit()->associate($free);
    }

    /**
     * "Bed 4" → 4: the bed's position in its room (and its lane on the Smart Chart).
     */
    public function bedNumber(InventoryUnit $bed): int
    {
        return (int) preg_replace('/\D+/', '', $bed->name);
    }

    private function bedIsFree(int $bedId, SubBooking $stay): bool
    {
        return ! SubBooking::query()
            ->where('inventory_unit_id', $bedId)
            ->when($stay->exists, fn ($query) => $query->whereKeyNot($stay->id))
            ->whereIn('status', SubBookingStatus::occupying())
            ->whereDate('check_in_date', '<', $stay->check_out_date->toDateString())
            ->whereDate('check_out_date', '>', $stay->check_in_date->toDateString())
            ->exists();
    }
}
