<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\WebCheckins;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Property → Web Check-in: whether guests can check in online, when the link opens and what
 * the form asks for.
 */
class PropertyWebCheckinController extends Controller
{
    public function update(Request $request, Property $property, WebCheckins $checkins): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['boolean'],
            'opens_days_before' => ['required', 'integer', 'min:0', 'max:60'],
            'require_id_document' => ['boolean'],
            'require_signature' => ['boolean'],
            'require_address' => ['boolean'],
            'ask_arrival_time' => ['boolean'],
            'include_in_confirmation_email' => ['boolean'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:5000'],
        ]);

        $checkins->saveSettings($property, [...$data, 'instructions' => $data['instructions'] ?? '', 'terms' => $data['terms'] ?? ''], $request->user());

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'web-check-in'])->with('success', 'Web check-in settings saved.');
    }
}
