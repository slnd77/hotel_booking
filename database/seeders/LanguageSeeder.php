<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        collect([
            'en' => 'English',
            'fr' => 'French',
            'es' => 'Spanish',
            'de' => 'German',
            'hi' => 'Hindi',
            'ar' => 'Arabic',
            'zh' => 'Chinese',
            'ja' => 'Japanese',
            'pt' => 'Portuguese',
            'ru' => 'Russian',
        ])->each(fn (string $name, string $code) => Language::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $name],
        ));
    }
}
