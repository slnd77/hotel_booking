<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Property;
use App\Models\PropertyGroups;
use Illuminate\Database\Seeder;

class PropertySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $propertyGroups = PropertyGroups::query()->get();

        $propertyGroups->each(function (PropertyGroups $propertyGroup): void {
            $city = City::query()->inRandomOrder()->first();

            Property::factory()->create([
                'property_group_id' => $propertyGroup->id,
                'city_id' => $city->id,
                'state_id' => $city->state_id,
                'country_id' => $city->country_id,
                'corporate_city_id' => $city->id,
                'corporate_state_id' => $city->state_id,
                'corporate_country_id' => $city->country_id,
            ]);
        });
    }
}
