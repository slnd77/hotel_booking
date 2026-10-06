<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\OutletPricePlan;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * An outlet's Price Plans tab: price lists for a range of business dates, and each
 * plan's item prices ("View Items").
 */
class OutletPricePlanController extends Controller
{
    public function store(Request $request, Property $property, Outlet $outlet): RedirectResponse
    {
        $plan = $outlet->pricePlans()->create($this->validated($request));
        OutletController::log($request, $property, "{$outlet->name}: price plan {$plan->name} added");

        return back()->with('success', "Price plan {$plan->name} added. Set its item prices under View Items.");
    }

    public function update(Request $request, Property $property, Outlet $outlet, OutletPricePlan $pricePlan): RedirectResponse
    {
        $pricePlan->update($this->validated($request));
        OutletController::log($request, $property, "{$outlet->name}: price plan {$pricePlan->name} updated");

        return back()->with('success', "Price plan {$pricePlan->name} updated.");
    }

    public function destroy(Request $request, Property $property, Outlet $outlet, OutletPricePlan $pricePlan): RedirectResponse
    {
        $pricePlan->delete();
        OutletController::log($request, $property, "{$outlet->name}: price plan {$pricePlan->name} deleted");

        return back()->with('success', "Price plan {$pricePlan->name} deleted.");
    }

    /**
     * View Items → Save: the plan's price for each item; an item left blank has no price in this plan.
     */
    public function updatePrices(Request $request, Property $property, Outlet $outlet, OutletPricePlan $pricePlan): RedirectResponse
    {
        $itemIds = $outlet->items()->pluck('id')->all();

        $data = $request->validate([
            'prices' => ['present', 'array'],
            'prices.*.outlet_item_id' => ['required', 'integer', 'in:'.implode(',', $itemIds ?: [0])],
            'prices.*.price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ]);

        DB::transaction(function () use ($data, $pricePlan): void {
            foreach ($data['prices'] as $row) {
                if ($row['price'] === null || $row['price'] === '') {
                    $pricePlan->prices()->where('outlet_item_id', $row['outlet_item_id'])->delete();

                    continue;
                }

                $pricePlan->prices()->updateOrCreate(['outlet_item_id' => $row['outlet_item_id']], ['price' => round((float) $row['price'], 2)]);
            }
        });

        OutletController::log($request, $property, "{$outlet->name}: price plan {$pricePlan->name} item prices saved");

        return back()->with('success', "Prices of {$pricePlan->name} saved.");
    }

    /**
     * @return array{name: string, start_date: string, end_date: string, status: bool}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'status' => ['boolean'],
        ]);
    }
}
