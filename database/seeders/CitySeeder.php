<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $path = database_path('data/locations/cities.csv');
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        $rows = [];

        while (($record = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $record);

            $rows[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'state_id' => $row['state_id'],
                'country_id' => $row['country_id'],
                'latitude' => $row['latitude'] ?: null,
                'longitude' => $row['longitude'] ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($rows) >= 1000) {
                DB::table('cities')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('cities')->insert($rows);
        }

        fclose($handle);
    }
}
