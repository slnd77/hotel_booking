<?php

namespace App\Console\Commands;

use App\Services\QzTraySigning;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('pos:qz-certificate {--name= : Certificate common name (default: the app URL host)} {--organisation= : Organisation on the certificate (default: the app name)} {--days=3650 : Days the certificate is valid} {--force : Replace an existing key pair}')]
#[Description('Makes the private key and self-signed certificate that sign QZ Tray print requests')]
class QzTrayCertificate extends Command
{
    public function handle(QzTraySigning $signing): int
    {
        $certificatePath = $signing->path('certificate');
        $keyPath = $signing->path('private_key');

        if ((File::exists($certificatePath) || File::exists($keyPath)) && ! $this->option('force')) {
            $this->error("A QZ Tray key pair already exists ({$keyPath}). Pass --force to replace it; QZ Tray computers then need the new certificate.");

            return self::FAILURE;
        }

        $pair = $signing->generate(
            (string) ($this->option('name') ?: parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost'),
            (string) ($this->option('organisation') ?: config('app.name')),
            (int) $this->option('days'),
        );

        File::ensureDirectoryExists(dirname($certificatePath));
        File::ensureDirectoryExists(dirname($keyPath));
        File::put($certificatePath, $pair['certificate']);
        File::put($keyPath, $pair['private_key']);
        @chmod($keyPath, 0600);

        $this->info("Certificate: {$certificatePath}");
        $this->info("Private key: {$keyPath} (keep it secret; never commit it)");
        $this->line('Self-signed: copy the certificate to each POS computer as override.crt in the QZ Tray folder, then restart QZ Tray.');

        return self::SUCCESS;
    }
}
