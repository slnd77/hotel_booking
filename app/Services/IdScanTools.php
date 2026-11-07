<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

/**
 * The programs that read an ID photo on this server: zbarimg finds QR codes and tesseract
 * reads text. Each is optional; a missing one is skipped.
 */
class IdScanTools
{
    public function canReadQrCodes(): bool
    {
        return $this->binary('zbarimg') !== null;
    }

    public function canReadText(): bool
    {
        return $this->binary('tesseract') !== null;
    }

    /**
     * The text of each QR code in the image.
     *
     * @return list<string>
     */
    public function qrCodes(string $imagePath): array
    {
        $binary = $this->binary('zbarimg');

        if ($binary === null) {
            return [];
        }

        $result = Process::timeout($this->timeout())->run([$binary, '--quiet', '--raw', '-Sdisable', '-Sqrcode.enable', '-Sbinary', $imagePath]);

        if (! $result->successful()) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\R/', $result->output()) ?: []), fn (string $line): bool => $line !== ''));
    }

    /**
     * The image's text (capital letters, digits and "<", as in a machine-readable zone).
     */
    public function text(string $imagePath): ?string
    {
        $binary = $this->binary('tesseract');

        if ($binary === null) {
            return null;
        }

        $result = Process::timeout($this->timeout())->run([$binary, $imagePath, 'stdout', '--psm', '6', '-c', 'tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<']);

        return $result->successful() ? $result->output() : null;
    }

    private function binary(string $name): ?string
    {
        $configured = (string) config("services.id_scan.{$name}", $name);

        if ($configured === '') {
            return null;
        }

        if (is_file($configured) && is_executable($configured)) {
            return $configured;
        }

        return (new ExecutableFinder)->find($configured);
    }

    private function timeout(): int
    {
        return max(5, (int) config('services.id_scan.timeout', 20));
    }
}
