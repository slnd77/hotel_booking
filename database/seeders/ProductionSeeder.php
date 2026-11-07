<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * A new production install's reference data: places, currencies, languages, settings keys,
 * amenities, booking channels and the base roles. No demo properties (DatabaseSeeder makes
 * those with factories, which a --no-dev install doesn't have). Safe to run again.
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);
        $adminRole->givePermissionTo(Permission::firstOrCreate(['name' => 'checkout_on_outstanding_payments']));

        $this->call([
            CountrySeeder::class,
            StateSeeder::class,
            CitySeeder::class,
            CurrencySeeder::class,
            LanguageSeeder::class,
            SupportedThirdPartyAnalyticsSeeder::class,
            SupportedThirdPartyAdTrackingSeeder::class,
            SupportedMetasearchPlatformSeeder::class,
            FormFieldSeeder::class,
            MetaFieldSeeder::class,
            AmenityCategoryAndAmenitySeeder::class,
            BookingChannelSeeder::class,
        ]);
    }
}
