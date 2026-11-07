<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\OutletDisplayCategory;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * An outlet's Display Categories tab.
 */
class OutletDisplayCategoryController extends Controller
{
    public function store(Request $request, Property $property, Outlet $outlet): RedirectResponse
    {
        $data = $this->validated($request);
        $category = $outlet->displayCategories()->create([
            ...$data,
            'display_order' => $data['display_order'] ?? ((int) $outlet->displayCategories()->max('display_order') + 1),
        ]);

        OutletController::log($request, $property, "{$outlet->name}: display category {$category->name} added");

        return back()->with('success', "Display category {$category->name} added.");
    }

    public function update(Request $request, Property $property, Outlet $outlet, OutletDisplayCategory $displayCategory): RedirectResponse
    {
        $displayCategory->update($this->validated($request));
        OutletController::log($request, $property, "{$outlet->name}: display category {$displayCategory->name} updated");

        return back()->with('success', "Display category {$displayCategory->name} updated.");
    }

    public function destroy(Request $request, Property $property, Outlet $outlet, OutletDisplayCategory $displayCategory): RedirectResponse
    {
        if ($displayCategory->items()->exists()) {
            return back()->with('error', "Display category {$displayCategory->name} still has items. Move or delete them first.");
        }

        $displayCategory->delete();
        OutletController::log($request, $property, "{$outlet->name}: display category {$displayCategory->name} deleted");

        return back()->with('success', "Display category {$displayCategory->name} deleted.");
    }

    /**
     * @return array{name: string, display_order?: int|null}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'display_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
