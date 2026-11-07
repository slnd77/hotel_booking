<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Spatie\LaravelPdf\Facades\Pdf;
use Throwable;

#[Signature('ops:pdf-check')]
#[Description('Render a one-page PDF through the configured driver (Puppeteer and Chrome by default) to check invoices, prints and reports can be made')]
class PdfCheckCommand extends Command
{
    public function handle(): int
    {
        $driver = (string) config('laravel-pdf.driver', 'browsershot');
        $chrome = config('laravel-pdf.browsershot.chrome_path');
        $this->line("Driver: {$driver}");

        if ($driver === 'browsershot') {
            $this->line('Chrome: '.($chrome ?: '(Puppeteer\'s own download; LARAVEL_PDF_CHROME_PATH is not set)'));

            if (filled($chrome) && ! is_file((string) $chrome)) {
                $this->error("LARAVEL_PDF_CHROME_PATH points at a missing file: {$chrome}. Run deploy/deploy.sh again, or install it with: npx puppeteer browsers install chrome-headless-shell");

                return self::FAILURE;
            }
        }

        try {
            $contents = Pdf::html('<h1>'.e(config('app.name')).' PDF check</h1>')->generatePdfContent();
        } catch (Throwable $exception) {
            $this->error('Rendering failed: '.$exception->getMessage());

            if (str_contains($exception->getMessage(), 'No usable sandbox')) {
                $this->warn('Chrome cannot start its sandbox here (common on Ubuntu 24.04). Set LARAVEL_PDF_NO_SANDBOX=true in .env, then php artisan config:cache.');
            }

            return self::FAILURE;
        }

        if (! str_starts_with($contents, '%PDF')) {
            $this->error('The driver answered, but not with a PDF.');

            return self::FAILURE;
        }

        $this->info('PDF rendering works ('.number_format(strlen($contents)).' bytes).');

        return self::SUCCESS;
    }
}
