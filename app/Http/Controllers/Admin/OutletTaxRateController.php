<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\OutletTaxRate;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * An outlet's Tax Settings tab: the tax rates its catalogue items can carry.
 */
class OutletTaxRateController extends Controller
{
    public function store(Request $request, Property $property, Outlet $outlet): RedirectResponse
    {
        $taxRate = $outlet->taxRates()->create($this->validated($request));
        OutletController::log($request, $property, "{$outlet->name}: tax rate {$taxRate->name} added");

        return back()->with('success', "Tax rate {$taxRate->name} added.");
    }

    public function update(Request $request, Property $property, Outlet $outlet, OutletTaxRate $taxRate): RedirectResponse
    {
        $taxRate->update($this->validated($request));
        OutletController::log($request, $property, "{$outlet->name}: tax rate {$taxRate->name} updated");

        return back()->with('success', "Tax rate {$taxRate->name} updated.");
    }

    public function destroy(Request $request, Property $property, Outlet $outlet, OutletTaxRate $taxRate): RedirectResponse
    {
        if ($taxRate->items()->exists()) {
            return back()->with('error', "Tax rate {$taxRate->name} is used by items. Change their tax rate or switch it off instead.");
        }

        $taxRate->delete();
        OutletController::log($request, $property, "{$outlet->name}: tax rate {$taxRate->name} deleted");

        return back()->with('success', "Tax rate {$taxRate->name} deleted.");
    }

    /**
     * @return array{name: string, rate: float|string, status: bool}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'status' => ['boolean'],
        ]);
    }
}
