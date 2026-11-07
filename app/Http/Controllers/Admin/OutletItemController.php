<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\OutletItem;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * An outlet's Catalogue Items tab. The tick against an item is whether the outlet sells it.
 */
class OutletItemController extends Controller
{
    public function store(Request $request, Property $property, Outlet $outlet): RedirectResponse
    {
        $data = $this->validated($request, $outlet);
        $item = $outlet->items()->create([
            ...$data,
            'display_order' => $data['display_order'] ?? ((int) $outlet->items()->max('display_order') + 1),
        ]);

        OutletController::log($request, $property, "{$outlet->name}: item {$item->name} added");

        return back()->with('success', "Item {$item->name} added.");
    }

    public function update(Request $request, Property $property, Outlet $outlet, OutletItem $item): RedirectResponse
    {
        $item->update($this->validated($request, $outlet));
        OutletController::log($request, $property, "{$outlet->name}: item {$item->name} updated");

        return back()->with('success', "Item {$item->name} updated.");
    }

    /**
     * Catalogue Items → Save: the ticked items are sold, the others aren't.
     */
    public function updateStatuses(Request $request, Property $property, Outlet $outlet): RedirectResponse
    {
        $data = $request->validate([
            'active_item_ids' => ['present', 'array'],
            'active_item_ids.*' => ['integer', Rule::exists('outlet_items', 'id')->where('outlet_id', $outlet->id)],
        ]);

        $outlet->items()->whereIn('id', $data['active_item_ids'])->update(['status' => true]);
        $outlet->items()->whereNotIn('id', $data['active_item_ids'])->update(['status' => false]);
        OutletController::log($request, $property, "{$outlet->name}: catalogue items saved (".count($data['active_item_ids']).' active)');

        return back()->with('success', 'Catalogue items saved.');
    }

    public function destroy(Request $request, Property $property, Outlet $outlet, OutletItem $item): RedirectResponse
    {
        $item->delete();
        OutletController::log($request, $property, "{$outlet->name}: item {$item->name} deleted");

        return back()->with('success', "Item {$item->name} deleted.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Outlet $outlet): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'outlet_display_category_id' => ['nullable', 'integer', Rule::exists('outlet_display_categories', 'id')->where('outlet_id', $outlet->id)],
            'tax_category_id' => ['nullable', 'integer', 'min:1'],
            'outlet_tax_rate_id' => ['nullable', 'integer', Rule::exists('outlet_tax_rates', 'id')->where('outlet_id', $outlet->id)],
            'sac_hsn_code' => ['nullable', 'string', 'regex:/^[0-9]{4,8}$/'],
            'item_type' => ['required', Rule::in(array_keys(OutletItem::ITEM_TYPES))],
            'status' => ['boolean'],
            'display_order' => ['nullable', 'integer', 'min:0'],
        ], [
            'sac_hsn_code.regex' => 'The SAC/HSN code must be 4 to 8 digits.',
        ]);
    }
}
