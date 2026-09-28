<?php

namespace App\Services;

use App\Http\Controllers\Concerns\CalculatesRoomTaxes;
use App\Models\Property;

/**
 * A booking's price: each room's rate × its nights, less the discount (shared by
 * room amount), plus room taxes per the property's tax settings. Rooms of a split
 * booking can have different nights.
 */
class BookingPricing
{
    use CalculatesRoomTaxes;

    /**
     * @param  list<array{rate_per_night: float|int|string, nights: int}>  $rooms
     * @return array{room_total: float, discount: float, taxes: float, total: float, tax_lines: list<array{label: string, amount: float}>}
     */
    public function totals(Property $property, array $rooms, float $discount): array
    {
        $taxComponents = $this->activeRoomTaxComponents($property);
        $roomTotal = round(collect($rooms)->sum(fn (array $room): float => (float) $room['rate_per_night'] * max(1, (int) $room['nights'])), 2);

        // Each tax component's amount over all rooms, so it can be shown on its own line.
        $componentTaxes = array_fill(0, count($taxComponents), 0.0);

        foreach ($rooms as $room) {
            $nights = max(1, (int) $room['nights']);
            $rackRate = (float) $room['rate_per_night'];
            $roomAmount = $rackRate * $nights;
            $discountShare = $roomTotal > 0 ? $discount * ($roomAmount / $roomTotal) : 0.0;
            $netRate = max(0, $roomAmount - $discountShare) / $nights;

            foreach (array_values($taxComponents) as $index => $component) {
                $componentTaxes[$index] += $this->taxForAmount([$component], $rackRate, $netRate) * $nights;
            }
        }

        $taxes = round(array_sum($componentTaxes), 2);
        $taxLines = array_values(collect(array_values($taxComponents))
            ->map(fn (array $component, int $index): array => ['label' => (string) ($component['component_choice'] ?? 'Tax'), 'amount' => round($componentTaxes[$index], 2)])
            ->filter(fn (array $line): bool => $line['amount'] != 0)
            ->all());

        return ['room_total' => $roomTotal, 'discount' => $discount, 'taxes' => $taxes, 'total' => round($roomTotal - $discount + $taxes, 2), 'tax_lines' => $taxLines];
    }

    public static function nights(string $checkIn, string $checkOut): int
    {
        return max(1, (int) round((strtotime($checkOut) - strtotime($checkIn)) / 86400));
    }
}
