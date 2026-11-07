<?php

namespace Database\Factories;

/**
 * Builds promo code arrays matching the shape expected by deals promotion settings.
 */
class PromoCodeFactory
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public const PUBLIC_PRESETS = [
        [
            'code' => 'WELCOME10',
            'tag_line' => '10% off your stay',
            'be_carousel_label' => 'Welcome Offer: 10% Off',
            'description' => 'Enjoy 10% off on your stay when booking directly.',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'booking_amount_range' => ['enabled' => false, 'min' => null, 'max' => null],
        ],
        [
            'code' => 'FRIGID15',
            'tag_line' => '15% off',
            'be_carousel_label' => '15% off on above 10000',
            'description' => '15% off on bookings of AED 10,000 or more.',
            'discount_type' => 'percentage',
            'discount_value' => 15,
            'booking_amount_range' => ['enabled' => true, 'min' => 10000, 'max' => 50000],
        ],
        [
            'code' => 'SUMMER20',
            'tag_line' => '20% off summer special',
            'be_carousel_label' => 'Summer Special Deal: 20% Off',
            'description' => 'Exclusive 20% discount on all room bookings this summer.',
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'booking_amount_range' => ['enabled' => false, 'min' => null, 'max' => null],
        ],
        [
            'code' => 'GETAWAY500',
            'tag_line' => 'AED 500 flat discount',
            'be_carousel_label' => 'AED 500 Off Getaway',
            'description' => 'Flat AED 500 discount on bookings of AED 3,000 or more.',
            'discount_type' => 'fixed',
            'discount_value' => 500,
            'booking_amount_range' => ['enabled' => true, 'min' => 3000, 'max' => null],
        ],
    ];

    /**
     * @var array<int, array<string, mixed>>
     */
    public const SECRET_PRESETS = [
        [
            'code' => 'VIPSECRET',
            'tag_line' => 'Exclusive 25% VIP Discount',
            'be_carousel_label' => 'VIP Exclusive Offer',
            'description' => 'Exclusive secret promo for VIP guests.',
            'discount_type' => 'percentage',
            'discount_value' => 25,
            'booking_amount_range' => ['enabled' => false, 'min' => null, 'max' => null],
        ],
        [
            'code' => 'SECRET500',
            'tag_line' => 'AED 500 Off Secret Voucher',
            'be_carousel_label' => 'AED 500 Secret Voucher',
            'description' => 'AED 500 off on bookings of AED 3,000 or more.',
            'discount_type' => 'fixed',
            'discount_value' => 500,
            'booking_amount_range' => ['enabled' => true, 'min' => 3000, 'max' => null],
        ],
        [
            'code' => 'CORP30',
            'tag_line' => '30% Corporate Partner Rate',
            'be_carousel_label' => 'Corporate Partner Discount',
            'description' => 'Special corporate rate for business travelers.',
            'discount_type' => 'percentage',
            'discount_value' => 30,
            'booking_amount_range' => ['enabled' => true, 'min' => 5000, 'max' => null],
        ],
        [
            'code' => 'MEMBERSHIP15',
            'tag_line' => '15% Member Private Deal',
            'be_carousel_label' => 'Members Club Discount',
            'description' => 'Private promotion for loyalty program members.',
            'discount_type' => 'percentage',
            'discount_value' => 15,
            'booking_amount_range' => ['enabled' => false, 'min' => null, 'max' => null],
        ],
    ];

    /**
     * Build base promo template.
     *
     * @return array<string, mixed>
     */
    public static function template(int $id = 1): array
    {
        return [
            'id' => $id,
            'deal_type' => 'basic',
            'code' => 'PROMO'.$id,
            'tag_line' => 'Special Discount',
            'be_carousel_label' => 'Special Offer',
            'description' => 'Special promotion on room bookings.',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'required_specific_payment_gateway' => false,
            'channels' => ['booking_engine'],
            'min_length_of_stay' => null,
            'free_night_available_is' => 'check_in',
            'booking_creation_date_range' => ['enabled' => false, 'start' => null, 'end' => null],
            'checkin_date_range' => ['enabled' => false, 'start' => null, 'end' => null],
            'checkout_date_range' => ['enabled' => false, 'start' => null, 'end' => null],
            'stay_days_of_week' => ['enabled' => false, 'days' => []],
            'exclusion_date_range' => ['enabled' => false, 'start' => null, 'end' => null],
            'bar_rate_plans' => ['enabled' => false],
            'package_rate_plans' => ['enabled' => false],
            'cut_off_days' => ['enabled' => false, 'value' => null],
            'last_minute' => ['enabled' => false, 'value' => null],
            'length_of_stay' => ['enabled' => false, 'value' => null],
            'booking_amount_range' => ['enabled' => false, 'min' => null, 'max' => null],
            'usage_limit' => ['enabled' => false, 'value' => null],
            'daily_room_count_budget' => ['enabled' => false, 'value' => null],
            'max_discount_per_booking' => ['enabled' => false, 'value' => null],
            'max_total_budget' => ['enabled' => false, 'value' => null],
            'applicable_on_pay_at_hotel' => true,
            'terms_and_conditions' => 'Standard promotional terms and conditions apply.',
            'status' => true,
            'deleted' => false,
        ];
    }

    /**
     * Create a public promo code.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function public(array $attributes = [], int $id = 1): array
    {
        $presetIndex = ($id - 1) % count(self::PUBLIC_PRESETS);
        $preset = self::PUBLIC_PRESETS[$presetIndex];

        return array_replace_recursive(self::template($id), $preset, $attributes);
    }

    /**
     * Create a secret promo code.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function secret(array $attributes = [], int $id = 1): array
    {
        $presetIndex = ($id - 1) % count(self::SECRET_PRESETS);
        $preset = self::SECRET_PRESETS[$presetIndex];

        return array_replace_recursive(self::template($id), $preset, $attributes);
    }

    /**
     * Generic factory make.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function make(array $attributes = [], int $id = 1, bool $secret = false): array
    {
        return $secret ? self::secret($attributes, $id) : self::public($attributes, $id);
    }

    /**
     * Make multiple promo codes.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<int, array<string, mixed>>
     */
    public static function makeMany(int $count = 2, array $attributes = [], bool $secret = false): array
    {
        $promos = [];
        for ($i = 1; $i <= $count; $i++) {
            $promos[] = self::make($attributes, $i, $secret);
        }

        return $promos;
    }
}
