<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Addon;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Property → Add-ons: the add-on services sold with bookings (booking form, booking detail
 * page and, when shown there, the booking engine).
 */
class PropertyAddonController extends Controller
{
    public function store(Request $request, Property $property): RedirectResponse
    {
        $addon = $property->addons()->create([
            ...$this->validated($request),
            'display_order' => (int) $property->addons()->max('display_order') + 1,
        ]);
        $this->log($request, $property, "Add-on {$addon->name} added");

        return $this->back($property, "Add-on {$addon->name} added.");
    }

    public function update(Request $request, Property $property, Addon $addon): RedirectResponse
    {
        $addon->update($this->validated($request));
        $this->log($request, $property, "Add-on {$addon->name} updated");

        return $this->back($property, "Add-on {$addon->name} updated.");
    }

    /**
     * Bookings keep the add-ons they were sold (their lines copy the name and price).
     */
    public function destroy(Request $request, Property $property, Addon $addon): RedirectResponse
    {
        $addon->delete();
        $this->log($request, $property, "Add-on {$addon->name} deleted");

        return $this->back($property, "Add-on {$addon->name} deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'pricing' => ['required', Rule::in(array_keys(Addon::PRICING))],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'sac_hsn_code' => ['nullable', 'regex:/^\d{4,8}$/'],
            'show_on_booking_engine' => ['boolean'],
            'status' => ['boolean'],
        ], ['sac_hsn_code.regex' => 'The SAC/HSN code is 4 to 8 digits.']);
    }

    private function log(Request $request, Property $property, string $action): void
    {
        ActivityLog::create(['user_id' => $request->user()->id, 'property_id' => $property->id, 'action' => $action, 'date' => now()]);
    }

    private function back(Property $property, string $message): RedirectResponse
    {
        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'add-ons'])->with('success', $message);
    }
}
