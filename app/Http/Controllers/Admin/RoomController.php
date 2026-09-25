<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SubBookingStatus;
use App\Http\Controllers\Controller;
use App\Models\InventoryUnit;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\SubBooking;
use App\Services\DormBeds;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RoomController extends Controller
{
    public function store(Request $request, Property $property, RoomCategory $roomCategory, DormBeds $dormBeds): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            ...$this->bedCountRules($roomCategory),
        ]);

        $room = $roomCategory->rooms()->create([
            'name' => $data['name'],
            'display_order' => $roomCategory->rooms()->count() + 1,
            'bed_count' => $roomCategory->sell_by_bed ? (int) $data['bed_count'] : null,
        ]);

        $dormBeds->syncRoom($room);

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'rooms-setup'])->with('success', 'Room created.');
    }

    public function update(Request $request, Property $property, RoomCategory $roomCategory, Room $room, DormBeds $dormBeds): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            ...$this->bedCountRules($roomCategory),
        ]);
        $bedCount = $roomCategory->sell_by_bed ? (int) $data['bed_count'] : null;

        if ($bedCount !== null) {
            $this->ensureRemovedBedsAreFree($property, $room, $bedCount, $dormBeds);
        }

        $room->update(['name' => $data['name'], 'bed_count' => $bedCount]);
        $dormBeds->syncRoom($room);

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'rooms-setup'])->with('success', 'Room updated.');
    }

    public function destroy(Property $property, RoomCategory $roomCategory, Room $room): RedirectResponse
    {
        $room->delete();

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'rooms-setup'])->with('success', 'Room deleted.');
    }

    /**
     * A dormitory's rooms need their number of beds.
     *
     * @return array<string, list<string>>
     */
    private function bedCountRules(RoomCategory $roomCategory): array
    {
        return $roomCategory->sell_by_bed ? ['bed_count' => ['required', 'integer', 'min:1', 'max:100']] : [];
    }

    /**
     * Fewer beds can't drop a bed that has a current or upcoming stay.
     *
     * @throws ValidationException
     */
    private function ensureRemovedBedsAreFree(Property $property, Room $room, int $bedCount, DormBeds $dormBeds): void
    {
        $removedBedIds = InventoryUnit::query()
            ->where('room_id', $room->id)
            ->where('unit_type', InventoryUnit::TYPE_BED)
            ->get()
            ->filter(fn (InventoryUnit $bed): bool => $dormBeds->bedNumber($bed) > $bedCount)
            ->pluck('id');

        if ($removedBedIds->isNotEmpty() && SubBooking::query()->whereIn('inventory_unit_id', $removedBedIds)->whereIn('status', SubBookingStatus::occupying())->whereDate('check_out_date', '>=', $property->today())->exists()) {
            throw ValidationException::withMessages(['bed_count' => "Beds above {$bedCount} have current or upcoming stays. Move those guests first."]);
        }
    }

    public function reorder(Request $request, Property $property, RoomCategory $roomCategory): RedirectResponse
    {
        $data = $request->validate([
            'room_ids' => ['required', 'array'],
            'room_ids.*' => ['integer', 'exists:rooms,id'],
        ]);

        foreach ($data['room_ids'] as $index => $roomId) {
            $roomCategory->rooms()->whereKey($roomId)->update(['display_order' => $index + 1]);
        }

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'rooms-setup'])->with('success', 'Room order updated.');
    }
}
