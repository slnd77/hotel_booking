<?php

namespace Database\Factories;

/**
 * Builds public promo code arrays for booking engine public promotions.
 */
class PublicPromoCodeFactory
{
    /**
     * Create a single public promo code.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function make(array $attributes = [], int $id = 1): array
    {
        return PromoCodeFactory::public($attributes, $id);
    }

    /**
     * Create multiple public promo codes.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<int, array<string, mixed>>
     */
    public static function makeMany(int $count = 2, array $attributes = []): array
    {
        return PromoCodeFactory::makeMany($count, $attributes, false);
    }
}
