<?php

namespace Database\Seeders;

use App\Models\SupportedMetasearchPlatform;
use Illuminate\Database\Seeder;

class SupportedMetasearchPlatformSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            'Trivago', 'TripAdvisor', 'Google Hotel Ads', 'Kayak', 'HolidayCheck',
        ])->each(fn (string $name) => SupportedMetasearchPlatform::query()->firstOrCreate(
            ['slug' => str($name)->slug()],
            ['name' => $name],
        ));
    }
}
