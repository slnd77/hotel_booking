<?php

namespace Database\Factories;

/**
 * Builds secret promo code arrays for booking engine private/confidential promotions.
 */
class SecretPromoCodeFactory
{
    /**
     * Create a single secret promo code.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function make(array $attributes = [], int $id = 1): array
    {
        return PromoCodeFactory::secret($attributes, $id);
    }

    /**
     * Create multiple secret promo codes.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<int, array<string, mixed>>
     */
    public static function makeMany(int $count = 2, array $attributes = []): array
    {
        return PromoCodeFactory::makeMany($count, $attributes, true);
    }
}
