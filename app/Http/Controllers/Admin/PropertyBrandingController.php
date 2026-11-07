<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\PropertyBranding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PropertyBrandingController extends Controller
{
    private const HEX_COLOR = 'regex:/^#[0-9a-fA-F]{6}$/';

    public function update(Request $request, Property $property, PropertyBranding $branding): RedirectResponse
    {
        $image = ['image', 'mimes:jpeg,png,webp', 'max:2048'];

        $data = $request->validate([
            'website_url' => ['nullable', 'url', 'max:255'],
            'accommodation_nomenclature' => ['required', 'string', 'max:50'],
            'phone_numbers' => ['array', 'max:5'],
            'phone_numbers.*' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
            'support_emails' => ['array', 'max:5'],
            'support_emails.*' => ['nullable', 'email', 'max:255'],
            'show_property_name' => ['boolean'],
            'theme_color' => ['required', self::HEX_COLOR],
            'header_color' => ['required', self::HEX_COLOR],
            'text_color' => ['required', self::HEX_COLOR],
            'logo' => ['nullable', ...$image],
            'remove_logo' => ['boolean'],
            'banner_image' => ['nullable', ...$image],
            'remove_banner_image' => ['boolean'],
            'background_images' => ['array', 'max:10'],
            'background_images.*.path' => ['required', 'string'],
            'background_images.*.selected' => ['boolean'],
            'new_background_images' => ['array', 'max:10'],
            'new_background_images.*' => $image,
        ], [
            'phone_numbers.*.regex' => 'A phone number may only contain digits, spaces, +, - and brackets.',
            '*.regex' => 'Pick a colour as #RRGGBB.',
        ]);

        $branding->save($property, $data, $request->user());

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'branding'])->with('success', 'Branding saved.');
    }
}
