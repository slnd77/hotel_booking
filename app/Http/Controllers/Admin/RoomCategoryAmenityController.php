<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateRoomCategoryAmenitiesRequest;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\RoomCategoryAmenity;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RoomCategoryAmenityController extends Controller
{
    public function update(UpdateRoomCategoryAmenitiesRequest $request, Property $property, RoomCategory $roomCategory): RedirectResponse
    {
        if ($roomCategory->property_id !== $property->id) {
            throw new NotFoundHttpException;
        }

        $amenityIds = $request->validated()['amenity_ids'] ?? [];

        RoomCategoryAmenity::query()
            ->where('property_id', $property->id)
            ->where('room_category_id', $roomCategory->id)
            ->delete();

        foreach ($amenityIds as $amenityId) {
            RoomCategoryAmenity::query()->create([
                'property_id' => $property->id,
                'room_category_id' => $roomCategory->id,
                'amenity_id' => $amenityId,
            ]);
        }

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'rooms-setup'])->with('success', 'Category amenities updated.');
    }
}
