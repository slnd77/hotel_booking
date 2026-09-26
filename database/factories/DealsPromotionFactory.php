<?php

namespace Database\Factories;

use App\Models\MetaField;
use App\Models\Property;
use App\Models\PropertyMetaField;

/**
 * Builds a `rate_settings_deals_promotion` JSON payload containing public and secret promos.
 */
class DealsPromotionFactory
{
    /**
     * @param  array<int, array<string, mixed>>|null  $publicPromos
     * @param  array<int, array<string, mixed>>|null  $secretPromos
     * @return array{auto_apply_public_promo: bool, public_promo_codes: array<int, array<string, mixed>>, secret_promo_codes: array<int, array<string, mixed>>, payment_coupons: array{}}
     */
    public static function make(?Property $property = null, ?array $publicPromos = null, ?array $secretPromos = null, bool $autoApplyPublicPromo = true): array
    {
        $publicPromos ??= [
            PublicPromoCodeFactory::make([
                'id' => 1,
                'code' => 'FRIGID15',
                'tag_line' => '15% off',
                'be_carousel_label' => '15% off on above 10000',
                'description' => '15% off on bookings of AED 10,000 or more.',
                'discount_type' => 'percentage',
                'discount_value' => 15,
                'booking_amount_range' => [
                    'enabled' => true,
                    'min' => 10000,
                    'max' => 50000,
                ],
            ], 1),
            PublicPromoCodeFactory::make([
                'id' => 2,
                'code' => 'WELCOME10',
                'tag_line' => '10% off your stay',
                'be_carousel_label' => 'Welcome Offer: 10% Off',
                'description' => 'Enjoy 10% off on your stay when booking directly.',
                'discount_type' => 'percentage',
                'discount_value' => 10,
                'booking_amount_range' => [
                    'enabled' => false,
                    'min' => null,
                    'max' => null,
                ],
            ], 2),
        ];

        $secretPromos ??= [
            SecretPromoCodeFactory::make([
                'id' => 1,
                'code' => 'VIPSECRET',
                'tag_line' => 'Exclusive 25% VIP Discount',
                'be_carousel_label' => 'VIP Exclusive Offer',
                'description' => 'Exclusive secret promo for VIP guests.',
                'discount_type' => 'percentage',
                'discount_value' => 25,
            ], 1),
            SecretPromoCodeFactory::make([
                'id' => 2,
                'code' => 'SECRET500',
                'tag_line' => 'AED 500 Off Secret Voucher',
                'be_carousel_label' => 'AED 500 Secret Voucher',
                'description' => 'AED 500 off on bookings of AED 3,000 or more.',
                'discount_type' => 'fixed',
                'discount_value' => 500,
                'booking_amount_range' => [
                    'enabled' => true,
                    'min' => 3000,
                    'max' => null,
                ],
            ], 2),
        ];

        return [
            'auto_apply_public_promo' => $autoApplyPublicPromo,
            'public_promo_codes' => $publicPromos,
            'secret_promo_codes' => $secretPromos,
            'payment_coupons' => [],
        ];
    }

    /**
     * Apply deals promotion settings directly to a Property.
     *
     * @param  array<int, array<string, mixed>>|null  $publicPromos
     * @param  array<int, array<string, mixed>>|null  $secretPromos
     */
    public static function applyTo(Property $property, ?array $publicPromos = null, ?array $secretPromos = null, bool $autoApply = true): PropertyMetaField
    {
        $metaField = MetaField::query()->firstOrCreate(
            ['key' => 'rate_settings_deals_promotion'],
            [
                'key_label' => 'Rate Settings Deals Promotion',
                'status' => true,
            ],
        );

        $data = self::make($property, $publicPromos, $secretPromos, $autoApply);

        /** @var PropertyMetaField */
        return $property->metaFieldValues()->updateOrCreate(
            ['meta_fields_key_id' => $metaField->id],
            [
                'enabled' => true,
                'data' => json_encode($data),
            ],
        );
    }
}
