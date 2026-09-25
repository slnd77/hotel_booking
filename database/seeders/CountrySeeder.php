<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $path = database_path('data/locations/countries.csv');
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Unable to open {$path}.");
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            throw new RuntimeException("{$path} has no header row.");
        }

        $header = array_map(fn (?string $column): string => $column ?? '', $header);

        $rows = [];

        while (($record = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $record);

            $rows[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'iso3' => $row['iso3'],
                'iso2' => $row['iso2'],
                'numeric_code' => $row['numeric_code'] ?: null,
                'phonecode' => $row['phonecode'] ?: null,
                'capital' => $row['capital'] ?: null,
                'currency' => $row['currency'] ?: null,
                'currency_name' => $row['currency_name'] ?: null,
                'currency_symbol' => $row['currency_symbol'] ?: null,
                'native' => $row['native'] ?: null,
                'region' => $row['region'] ?: null,
                'subregion' => $row['subregion'] ?: null,
                'latitude' => $row['latitude'] ?: null,
                'longitude' => $row['longitude'] ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($rows) >= 500) {
                DB::table('countries')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('countries')->insert($rows);
        }

        fclose($handle);
    }
}
