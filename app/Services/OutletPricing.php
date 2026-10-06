<?php

namespace App\Services;

use App\Models\OutletItem;
use App\Models\OutletPricePlan;
use App\Models\OutletPricePlanItem;

/**
 * An outlet item's price on a business date: from the active price plan covering that
 * date (the one starting latest when plans overlap) that prices the item.
 */
class OutletPricing
{
    public function planFor(OutletItem $item, string $businessDate): ?OutletPricePlan
    {
        return OutletPricePlan::query()
            ->where('outlet_id', $item->outlet_id)
            ->where('status', true)
            ->whereDate('start_date', '<=', $businessDate)
            ->whereDate('end_date', '>=', $businessDate)
            ->whereHas('prices', fn ($query) => $query->where('outlet_item_id', $item->id))
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Tax on an amount of the item, at its tax rate (none without one, or while the rate is switched off).
     */
    public function taxFor(OutletItem $item, float $amount): float
    {
        $taxRate = $item->taxRate;

        return $taxRate && $taxRate->status ? round($amount * (float) $taxRate->rate / 100, 2) : 0.0;
    }

    public function priceFor(OutletItem $item, string $businessDate): ?float
    {
        $plan = $this->planFor($item, $businessDate);

        if ($plan === null) {
            return null;
        }

        $price = OutletPricePlanItem::query()->where('outlet_price_plan_id', $plan->id)->where('outlet_item_id', $item->id)->value('price');

        return $price === null ? null : (float) $price;
    }
}
