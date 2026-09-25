<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\RoomCategoryBedType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RoomCategoryBedTypeController extends Controller
{
    public function updateBeds(Request $request, Property $property, RoomCategory $roomCategory): RedirectResponse
    {
        return $this->sync($request, $roomCategory, 'bed');
    }

    public function updateExtraBeds(Request $request, Property $property, RoomCategory $roomCategory): RedirectResponse
    {
        return $this->sync($request, $roomCategory, 'extra_bed');
    }

    private function sync(Request $request, RoomCategory $roomCategory, string $type): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['array'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.enabled' => ['boolean'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        RoomCategoryBedType::query()
            ->where('room_category_id', $roomCategory->id)
            ->where('type', $type)
            ->delete();

        foreach ($data['items'] ?? [] as $item) {
            if (! ($item['enabled'] ?? false)) {
                continue;
            }

            $roomCategory->bedTypes()->create([
                'name' => $item['name'],
                'type' => $type,
                'quantity' => $item['quantity'] ?? 1,
            ]);
        }

        return redirect()->route('admin.properties.edit', [$roomCategory->property_id, 'tab' => 'rooms-setup'])->with('success', 'Bed types updated.');
    }
}
