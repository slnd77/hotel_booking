<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $path = database_path('data/locations/states.csv');
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        $rows = [];

        while (($record = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $record);

            $rows[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'country_id' => $row['country_id'],
                'state_code' => $row['state_code'] ?: null,
                'type' => $row['type'] ?: null,
                'latitude' => $row['latitude'] ?: null,
                'longitude' => $row['longitude'] ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($rows) >= 500) {
                DB::table('states')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('states')->insert($rows);
        }

        fclose($handle);
    }
}
