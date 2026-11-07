<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\RoomCategoryImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RoomCategoryImageController extends Controller
{
    public function store(Request $request, Property $property, RoomCategory $roomCategory): RedirectResponse
    {
        $data = $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:1000'],
        ]);

        $path = $data['image']->store('room-category-images', 'public');

        $roomCategory->images()->create([
            'path' => $path,
            'selected' => false,
        ]);

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'rooms-setup'])->with('success', 'Image uploaded.');
    }

    public function update(Request $request, Property $property, RoomCategory $roomCategory, RoomCategoryImage $image): RedirectResponse
    {
        $data = $request->validate([
            'selected' => ['required', 'boolean'],
        ]);

        $image->update(['selected' => $data['selected']]);

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'rooms-setup'])->with('success', 'Image updated.');
    }

    public function destroy(Property $property, RoomCategory $roomCategory, RoomCategoryImage $image): RedirectResponse
    {
        Storage::disk('public')->delete($image->path);
        $image->delete();

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'rooms-setup'])->with('success', 'Image deleted.');
    }
}
