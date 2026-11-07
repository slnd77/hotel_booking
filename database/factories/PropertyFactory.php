<?php

namespace Database\Factories;

use App\Models\City;
use App\Models\Country;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\PropertyGroups;
use App\Models\PropertyMetaField;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\State;
use Database\Seeders\TaxCategorySeeder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    /**
     * Hotel room category names, per
     * https://rategain.com/blog/comprehensive-guide-to-the-different-types-of-hotel-rooms/
     *
     * @var array<int, string>
     */
    private const ROOM_CATEGORY_NAMES = [
        'Single Room',
        'Double Room',
        'Twin Room',
        'Triple Room',
        'Queen Room',
        'King Room',
        'Studio Room',
        'Suite',
        'Junior Suite',
        'Deluxe Room',
        'Executive Room',
        'Accessible Room',
    ];

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Property $property): void {
            foreach (self::ROOM_CATEGORY_NAMES as $index => $name) {
                $roomCategory = RoomCategory::factory()->create([
                    'property_id' => $property->id,
                    'name' => $name,
                    'display_order' => $index,
                ]);

                $roomCount = fake()->numberBetween(1, 10);

                for ($number = 1; $number <= $roomCount; $number++) {
                    Room::factory()->create([
                        'room_category_id' => $roomCategory->id,
                        'name' => (string) 'Room '.$number,
                        'display_order' => $number,
                    ]);
                }
            }

            $ratePlanSettings = RatePlanFactory::make($property);
            self::saveMetaFieldData($property, 'rate_settings_rate_plan', $ratePlanSettings);

            $ratePlannerSettings = RatePlannerFactory::make($ratePlanSettings['plans']);
            self::saveMetaFieldData($property, 'rate_settings_rate_planner', $ratePlannerSettings);

            TaxCategorySeeder::applyDefaultsTo($property);

            $taxRuleSettings = TaxRuleFactory::make($property);
            self::saveMetaFieldData($property, 'tax_settings_tax_rule', $taxRuleSettings);

            $dealsPromotionSettings = DealsPromotionFactory::make($property);
            self::saveMetaFieldData($property, 'rate_settings_deals_promotion', $dealsPromotionSettings);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function saveMetaFieldData(Property $property, string $metaFieldKey, array $data): void
    {
        $metaField = MetaField::query()->where('key', $metaFieldKey)->first();

        if (! $metaField) {
            return;
        }

        PropertyMetaField::query()->updateOrCreate(
            ['property_id' => $property->id, 'meta_fields_key_id' => $metaField->id],
            ['enabled' => true, 'data' => json_encode($data)],
        );
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $country = Country::query()->firstOrCreate(
            ['iso2' => 'TL'],
            ['name' => 'Testland', 'iso3' => 'TST'],
        );

        $state = State::query()->firstOrCreate(
            ['name' => 'Test State', 'country_id' => $country->id],
        );

        $city = City::query()->firstOrCreate(
            ['name' => 'Test City', 'state_id' => $state->id, 'country_id' => $country->id],
        );

        return [
            'name' => fake()->unique()->company().' Hotel',
            'property_legal_name' => fake()->company().' Pvt Ltd',
            'property_group_id' => PropertyGroups::factory(),
            'abbreviation' => strtoupper(fake()->lexify('???')),
            'property_type' => fake()->numberBetween(1, 5),

            'third_party_analytics_enabled' => false,
            'third_party_ad_tracking_enabled' => false,

            'property_contact_number' => fake()->phoneNumber(),
            'outlet_sac_code_enabled' => false,

            'address_line_1' => fake()->streetAddress(),
            'address_line_2' => fake()->secondaryAddress(),
            'postal_code' => fake()->postcode(),
            'city_id' => $city->id,
            'state_id' => $state->id,
            'country_id' => $country->id,

            'corporate_address_permanent_address_same' => true,
            'corporate_address_line_1' => fake()->streetAddress(),
            'corporate_address_line_2' => fake()->secondaryAddress(),
            'corporate_postal_code' => fake()->postcode(),
            'corporate_city_id' => $city->id,
            'corporate_state_id' => $state->id,
            'corporate_country_id' => $country->id,

            'currency_id' => 1,
            'timezone' => 'Asia/Kolkata',
            'ignore_decimal_places_in_bill_settlement' => false,
            'checkout_tolerance_value' => 0.1,

            'language' => 1,

            'location_map_url' => fake()->url(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'property_segment' => 1,

            'star_rating' => fake()->numberBetween(1, 5),
            'tax_type' => 1,
            'metasearch_engines_listings_enabled' => false,

            'customise_name_for+room_invoice' => null,
        ];
    }
}
