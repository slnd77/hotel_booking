<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePropertyAmenitiesRequest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;

class PropertyAmenityController extends Controller
{
    public function update(UpdatePropertyAmenitiesRequest $request, Property $property): RedirectResponse
    {
        $property->amenities()->sync($request->validated()['amenity_ids'] ?? []);

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'property-amenities'])->with('success', 'Property amenities updated.');
    }
}
