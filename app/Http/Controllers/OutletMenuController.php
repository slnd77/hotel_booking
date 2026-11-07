<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Outlet;
use App\Models\OutletItem;
use App\Services\OutletBills;
use App\Services\OutletPricing;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The outlet's public menu, opened from its QR code: what it sells today and at what price.
 * The URL carries only the outlet's random menu token (and the table the QR code is on).
 */
class OutletMenuController extends Controller
{
    public function show(Request $request, string $menuToken, OutletPricing $pricing, OutletBills $outletBills): View
    {
        $outlet = Outlet::query()->where('menu_token', $menuToken)->where('status', true)->with('property.currency')->firstOrFail();
        $businessDate = $outletBills->businessDate($outlet->property);

        $categories = $outlet->items()
            ->where('status', true)
            ->with(['displayCategory', 'taxRate'])
            ->get()
            ->map(fn (OutletItem $item): array => [
                'name' => $item->name,
                'category' => $item->displayCategory?->name ?? 'Others',
                'category_order' => $item->displayCategory?->display_order ?? PHP_INT_MAX,
                'price' => $pricing->priceFor($item, $businessDate),
                'tax_rate' => $item->taxRate && $item->taxRate->status ? (float) $item->taxRate->rate : 0.0,
            ])
            ->filter(fn (array $item): bool => $item['price'] !== null)
            ->sortBy('category_order')
            ->groupBy('category');

        return view('menu.show', [
            'outlet' => $outlet,
            'property' => $outlet->property,
            'currency' => Currency::forProperty($outlet->property)['symbol'],
            'table' => Str::limit(trim((string) $request->query('table', '')), 20, ''),
            'categories' => $categories,
        ]);
    }
}
