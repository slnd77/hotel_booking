<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Property;
use App\Services\ExchangeRates;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Exchange rates: what other currencies are worth in the property's own, by day.
 */
class ExchangeRateController extends Controller
{
    /**
     * Opens on the property picked in the header.
     */
    public function index(): Response
    {
        return Inertia::render('admin/exchange-rates/Index');
    }

    public function edit(Property $property, ExchangeRates $rates): Response
    {
        return Inertia::render('admin/exchange-rates/Edit', [
            'property' => $property->only(['id', 'name']),
            'baseCurrency' => Currency::forProperty($property)['code'],
            'today' => $property->today(),
            'current' => $rates->current($property),
            'history' => ExchangeRate::query()
                ->where('property_id', $property->id)
                ->with('creator:id,name')
                ->orderByDesc('effective_date')
                ->orderBy('currency_code')
                ->limit(200)
                ->get()
                ->map(fn (ExchangeRate $rate): array => [
                    'id' => $rate->id,
                    'currency_code' => $rate->currency_code,
                    'rate' => (float) $rate->rate,
                    'effective_date' => $rate->effective_date->toDateString(),
                    'set_by' => $rate->creator?->name,
                ]),
            'currencies' => Currency::query()->orderBy('code')->pluck('code'),
        ]);
    }

    public function store(Request $request, Property $property, ExchangeRates $rates): RedirectResponse
    {
        $validated = $request->validate([
            'currency_code' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'rate' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $rates->set($property, $validated['currency_code'], (float) $validated['rate'], CarbonImmutable::parse($validated['effective_date']), $request->user());

        return back()->with('success', "Rate for {$validated['currency_code']} saved.");
    }

    /**
     * Today's rate of each currency, for converting a payment while it is entered.
     */
    public function current(Property $property, ExchangeRates $rates): JsonResponse
    {
        return response()->json([
            'base' => Currency::forProperty($property)['code'],
            'rates' => $rates->current($property),
        ]);
    }
}
