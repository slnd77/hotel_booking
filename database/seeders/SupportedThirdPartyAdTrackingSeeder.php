<?php

namespace Database\Seeders;

use App\Models\SupportedThirdPartyAdTracking;
use Illuminate\Database\Seeder;

class SupportedThirdPartyAdTrackingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        collect([
            'Google Ads',
            'Meta Ads',
            'TikTok Ads Pixel',
            'Bing Ads',
            'LinkedIn Insight Tag',
        ])->each(fn (string $name) => SupportedThirdPartyAdTracking::query()->firstOrCreate(
            ['slug' => str($name)->slug()],
            ['name' => $name],
        ));
    }
}
