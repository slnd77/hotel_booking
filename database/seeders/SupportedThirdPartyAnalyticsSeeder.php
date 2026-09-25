<?php

namespace Database\Seeders;

use App\Models\SupportedThirdPartyAnalytics;
use Illuminate\Database\Seeder;

class SupportedThirdPartyAnalyticsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        collect([
            'Google Analytics',
            'Google Tag Manager',
            'Meta Pixel',
            'Hotjar',
            'Mixpanel',
        ])->each(fn (string $name) => SupportedThirdPartyAnalytics::query()->firstOrCreate(
            ['slug' => str($name)->slug()],
            ['name' => $name],
        ));
    }
}
