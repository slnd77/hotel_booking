<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;
use Money\Currencies\ISOCurrencies;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        foreach (new ISOCurrencies as $currency) {
            Currency::query()->firstOrCreate(
                ['code' => $currency->getCode()],
                ['name' => $currency->getCode()],
            );
        }
    }
}
