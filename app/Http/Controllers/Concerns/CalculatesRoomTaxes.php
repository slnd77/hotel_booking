<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Property;
use App\Services\PropertySettings;

trait CalculatesRoomTaxes
{
    /**
     * @return array<string, mixed>|null
     */
    private function metaFieldData(Property $property, string $metaFieldKey): ?array
    {
        return app(PropertySettings::class)->json($property, $metaFieldKey);
    }

    /**
     * The array stored under one key of a setting's JSON (e.g. a tax rule's "rules"),
     * or an empty array when the setting or the key is missing.
     *
     * @return array<array-key, mixed>
     */
    private function metaFieldArray(Property $property, string $metaFieldKey, string $key): array
    {
        $value = $this->metaFieldData($property, $metaFieldKey)[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * The tax rule components for the "Room" tax category that are currently in
     * date range — this is what Quick Book applies to every room line's rate.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activeRoomTaxComponents(Property $property): array
    {
        $roomCategory = collect($this->metaFieldArray($property, 'tax_settings_tax_category', 'categories'))->firstWhere('name', 'Room');

        if (! $roomCategory) {
            return [];
        }

        $today = $property->today();

        $rule = collect($this->metaFieldArray($property, 'tax_settings_tax_rule', 'rules'))
            ->where('deleted', false)
            ->where('tax_category_id', $roomCategory['id'])
            ->first(fn (array $rule) => (! $rule['start_date'] || $rule['start_date'] <= $today)
                && (! $rule['end_date'] || $rule['end_date'] >= $today));

        return $rule['components'] ?? [];
    }

    /**
     * Each component is taxed on the rack rate (room price before discount) or the
     * discounted net rate (room price after its share of the booking discount),
     * per the component's "Applied on" setting from Tax Rule settings.
     *
     * @param  array<int, array<string, mixed>>  $components
     */
    private function taxForAmount(array $components, float $rackRateAmount, float $discountedNetRateAmount): float
    {
        $tax = 0.0;

        foreach ($components as $component) {
            $amount = ($component['applied_on'] ?? 'rack_rate') === 'discounted_net_rate'
                ? $discountedNetRateAmount
                : $rackRateAmount;

            if ($component['define_tax_slab'] ?? false) {
                $slabs = $component['slabs'] ?? [];
                $slab = collect(is_array($slabs) ? $slabs : [])
                    ->first(fn (array $slab) => $amount >= $slab['start_range'] && $amount <= $slab['end_range']);

                $rate = (float) ($slab['tax'] ?? 0);
                $type = $slab['tax_value_type'] ?? 'percentage';
            } else {
                $rate = (float) ($component['tax_value'] ?? 0);
                $type = $component['tax_value_type'] ?? 'percentage';
            }

            $tax += $type === 'fixed' ? $rate : $amount * ($rate / 100);
        }

        return $tax;
    }
}
