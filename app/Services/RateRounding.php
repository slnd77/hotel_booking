<?php

namespace App\Services;

use App\Models\Property;

/**
 * Rate Settings → Rounding: nightly rates from Rate Setup (rate plans and packages) are rounded
 * before they are sold — on the booking engine, the booking form's rate suggestions and the
 * channel manager — to a multiple (1, 5, 10, 50, 100…) up, down or to the nearest, optionally
 * ending in set digits (round to 100 ending in 99: 2 040 → 1 999). Prices typed by staff are
 * left as typed.
 */
class RateRounding
{
    public const KEY = 'rate_settings_rounding';

    public const METHODS = ['nearest', 'up', 'down'];

    public const CHANNELS = ['direct', 'ota'];

    /**
     * @return array{enabled: bool, method: string, multiple: float, ending: float|null, channels: list<string>}
     */
    public function defaults(): array
    {
        return ['enabled' => false, 'method' => 'nearest', 'multiple' => 1.0, 'ending' => null, 'channels' => self::CHANNELS];
    }

    /**
     * @return array{enabled: bool, method: string, multiple: float, ending: float|null, channels: list<string>}
     */
    public function get(Property $property): array
    {
        $stored = app(PropertySettings::class)->json($property, self::KEY) ?? [];
        $settings = [...$this->defaults(), ...array_intersect_key($stored, $this->defaults())];

        return [
            'enabled' => (bool) $settings['enabled'],
            'method' => in_array($settings['method'], self::METHODS, true) ? $settings['method'] : 'nearest',
            'multiple' => max(0.01, (float) $settings['multiple']),
            'ending' => is_numeric($settings['ending']) ? (float) $settings['ending'] : null,
            'channels' => array_values(array_intersect(self::CHANNELS, (array) $settings['channels'])),
        ];
    }

    /**
     * The rate as the property sells it on the channel; null stays null.
     */
    public function apply(Property $property, ?float $rate, string $channel = 'direct'): ?float
    {
        if ($rate === null) {
            return null;
        }

        $settings = $this->get($property);
        // Channels other than the booking engine and OTAs (agents, corporate) follow "direct".
        $channelKey = in_array($channel, self::CHANNELS, true) ? $channel : 'direct';

        if (! $settings['enabled'] || ! in_array($channelKey, $settings['channels'], true) || $rate <= 0) {
            return $rate;
        }

        return self::round($rate, $settings['method'], $settings['multiple'], $settings['ending']);
    }

    /**
     * Rounds to the multiple; with an ending, the result is the closest price at or below the
     * rounded one that ends in it (never below zero).
     */
    public static function round(float $rate, string $method, float $multiple, ?float $ending): float
    {
        $steps = $rate / $multiple;
        $steps = match ($method) {
            'up' => ceil(round($steps, 6)),
            'down' => floor(round($steps, 6)),
            default => round($steps),
        };
        $rounded = $steps * $multiple;

        if ($ending !== null && $ending > 0 && $ending < $multiple && $rounded >= $multiple) {
            $rounded = $rounded - $multiple + $ending;
        }

        return round(max(0.0, $rounded), 2);
    }
}
