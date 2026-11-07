<?php

namespace App\Services;

use App\Models\Property;
use App\Models\RoomCategory;
use Carbon\CarbonImmutable;

/**
 * Rate Settings → Occupancy Pricing: nightly rates from the rate plans move with how full the
 * property (or the room category) already is on that night. Tiers are occupancy bands
 * (e.g. 0–40% −10%, 80–100% +15%) with a percentage or fixed amount adjustment, applied before
 * rounding, on the channels chosen. Rates typed by staff are left as typed.
 */
class OccupancyPricing
{
    public const KEY = 'rate_settings_occupancy_pricing';

    public const CHANNELS = ['direct', 'ota'];

    public const BASES = ['property', 'category'];

    public const ADJUSTMENT_TYPES = ['percent', 'amount'];

    /**
     * Occupancy per (property, basis, category, night) within one request, since a quote asks
     * for every night of every category.
     *
     * @var array<string, float>
     */
    private array $occupancyByNight = [];

    public function __construct(private readonly RoomInventory $inventory) {}

    /**
     * @return array{enabled: bool, basis: string, channels: list<string>, tiers: list<array{id: int, from: float, to: float, adjustment_type: string, adjustment: float}>}
     */
    public function defaults(): array
    {
        return ['enabled' => false, 'basis' => 'property', 'channels' => self::CHANNELS, 'tiers' => []];
    }

    /**
     * @return array{enabled: bool, basis: string, channels: list<string>, tiers: list<array{id: int, from: float, to: float, adjustment_type: string, adjustment: float}>}
     */
    public function get(Property $property): array
    {
        $stored = app(PropertySettings::class)->json($property, self::KEY) ?? [];
        $settings = [...$this->defaults(), ...array_intersect_key($stored, $this->defaults())];

        $tiers = array_values(collect(is_array($settings['tiers']) ? $settings['tiers'] : [])
            ->filter(fn (mixed $tier): bool => is_array($tier) && is_numeric($tier['from'] ?? null) && is_numeric($tier['to'] ?? null) && is_numeric($tier['adjustment'] ?? null))
            ->map(fn (array $tier, int $index): array => [
                'id' => (int) ($tier['id'] ?? $index + 1),
                'from' => max(0.0, min(100.0, (float) $tier['from'])),
                'to' => max(0.0, min(100.0, (float) $tier['to'])),
                'adjustment_type' => in_array($tier['adjustment_type'] ?? null, self::ADJUSTMENT_TYPES, true) ? $tier['adjustment_type'] : 'percent',
                'adjustment' => (float) $tier['adjustment'],
            ])
            ->filter(fn (array $tier): bool => $tier['from'] <= $tier['to'])
            ->sortBy('from')
            ->all());

        return [
            'enabled' => (bool) $settings['enabled'],
            'basis' => in_array($settings['basis'], self::BASES, true) ? $settings['basis'] : 'property',
            'channels' => array_values(array_intersect(self::CHANNELS, (array) $settings['channels'])),
            'tiers' => $tiers,
        ];
    }

    /**
     * The rate for the night as occupancy pricing sells it on the channel; null stays null and a
     * zero rate stays zero.
     */
    public function apply(Property $property, RoomCategory $category, CarbonImmutable $night, ?float $rate, string $channel = 'direct'): ?float
    {
        if ($rate === null || $rate <= 0) {
            return $rate;
        }

        $settings = $this->get($property);
        // Channels other than the booking engine and OTAs (agents, corporate) follow "direct".
        $channelKey = in_array($channel, self::CHANNELS, true) ? $channel : 'direct';

        if (! $settings['enabled'] || $settings['tiers'] === [] || ! in_array($channelKey, $settings['channels'], true)) {
            return $rate;
        }

        $tier = self::tierFor($settings['tiers'], $this->occupancyPercent($property, $settings['basis'] === 'category' ? $category : null, $night));

        return $tier === null ? $rate : self::adjust($rate, $tier['adjustment_type'], $tier['adjustment']);
    }

    /**
     * How full the property (or one category) is on the night: sold rooms (or dorm beds) over
     * what there is to sell, in percent. Blocked rooms count as sold, since they aren't for sale.
     * Categories excluded from occupancy (Room Categories → "Exclude from occupancy") are left
     * out of the property-wide figure.
     */
    public function occupancyPercent(Property $property, ?RoomCategory $category, CarbonImmutable $night): float
    {
        $date = $night->toDateString();
        $key = "{$property->id}:".($category->id ?? 'all').":{$date}";

        if (isset($this->occupancyByNight[$key])) {
            return $this->occupancyByNight[$key];
        }

        $capacity = $this->inventory->capacityByCategory($property->id);

        if ($category !== null) {
            $capacity = $capacity->only([$category->id]);
        } else {
            $excluded = RoomCategory::query()->where('property_id', $property->id)->where('exclude_from_occupancy', true)->pluck('id')->all();
            $capacity = $capacity->except($excluded);
        }

        $total = (int) $capacity->sum();

        if ($total <= 0) {
            return $this->occupancyByNight[$key] = 0.0;
        }

        $available = (int) $this->inventory->availabilityByCategory($property->id, $capacity, $date, $night->addDay()->toDateString())->sum();

        return $this->occupancyByNight[$key] = round(max(0, $total - $available) / $total * 100, 2);
    }

    /**
     * Drops the per-request occupancy figures (after a booking is made or cancelled).
     */
    public function forget(): void
    {
        $this->occupancyByNight = [];
    }

    /**
     * The first tier whose band covers the occupancy (bands are inclusive at both ends).
     *
     * @param  list<array{id: int, from: float, to: float, adjustment_type: string, adjustment: float}>  $tiers
     * @return array{id: int, from: float, to: float, adjustment_type: string, adjustment: float}|null
     */
    public static function tierFor(array $tiers, float $occupancyPercent): ?array
    {
        foreach ($tiers as $tier) {
            if ($occupancyPercent >= $tier['from'] && $occupancyPercent <= $tier['to']) {
                return $tier;
            }
        }

        return null;
    }

    public static function adjust(float $rate, string $adjustmentType, float $adjustment): float
    {
        $adjusted = $adjustmentType === 'amount' ? $rate + $adjustment : $rate * (1 + $adjustment / 100);

        return round(max(0.0, $adjusted), 2);
    }
}
