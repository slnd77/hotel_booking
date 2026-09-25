<?php

namespace Database\Seeders;

use App\Models\PropertyGroups;
use Illuminate\Database\Seeder;

class PropertyGroupsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        PropertyGroups::factory()->count(3)->create();
    }
}
