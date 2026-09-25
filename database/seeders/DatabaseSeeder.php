<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'user']);

        $permission = Permission::firstOrCreate(['name' => 'checkout_on_outstanding_payments']);
        $adminRole->givePermissionTo($permission);

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
            PropertyGroupsSeeder::class,
            PropertySeeder::class,
            TaxCategorySeeder::class,
            BookingChannelSeeder::class,
        ]);
    }
}
