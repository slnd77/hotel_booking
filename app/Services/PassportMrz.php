<?php

namespace App\Services;

use App\Models\Country;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Reads a passport's machine-readable zone (ICAO 9303, TD3: two lines of 44 characters) from
 * OCR text. Every field with a check digit must check out, so a misread line is rejected
 * rather than filled in wrong.
 */
class PassportMrz
{
    private const LINE_LENGTH = 44;

    /**
     * The two MRZ lines in OCR text, or null when there are none.
     *
     * @return array{0: string, 1: string}|null
     */
    public function find(string $text): ?array
    {
        $lines = array_values(array_filter(array_map(
            fn (string $line): string => preg_replace('/[^A-Z0-9<]/', '', strtoupper(str_replace(['«', '‹', ' '], ['<', '<', ''], $line))) ?? '',
            preg_split('/\R/', $text) ?: [],
        ), fn (string $line): bool => strlen($line) >= self::LINE_LENGTH - 2));

        foreach ($lines as $index => $line) {
            $next = $lines[$index + 1] ?? null;

            if ($next !== null && str_starts_with($line, 'P') && str_contains($line, '<<')) {
                return [$this->fit($line), $this->fit($next)];
            }
        }

        return null;
    }

    /**
     * @return array{document_type: string, document_number: string|null, first_name: string|null, last_name: string|null, gender: string|null, date_of_birth: string|null, nationality: string|null, address: string|null, postal_code: string|null, date_of_issue: string|null, date_of_expiry: string|null, place_of_issue: string|null, confidence: string, notes: string|null}
     */
    public function read(string $first, string $second): array
    {
        $first = $this->letterFix($this->fit($first), [2, 3, 4]);
        $second = $this->letterFix($this->fit($this->numericFix($second)), [10, 11, 12]);

        if ($first[0] !== 'P') {
            throw new RuntimeException('That machine-readable zone is not a passport\'s.');
        }

        $number = substr($second, 0, 9);
        $birth = substr($second, 13, 6);
        $expiry = substr($second, 21, 6);
        $composite = substr($second, 0, 10).substr($second, 13, 7).substr($second, 21, 22);

        foreach ([[$number, $second[9]], [$birth, $second[19]], [$expiry, $second[27]], [$composite, $second[43]]] as [$field, $digit]) {
            if ((string) $this->checkDigit($field) !== $digit) {
                throw new RuntimeException('The passport\'s machine-readable zone did not read cleanly (a check digit does not match).');
            }
        }

        // Names end at "<<": the filler after them is often misread (as "K", "C", …).
        [$surname, $given] = array_pad(explode('<<', substr($first, 5), 2), 2, '');
        $given = explode('<<', $given, 2)[0];
        $name = fn (string $value): ?string => ($value = trim((string) preg_replace('/\s+/', ' ', str_replace('<', ' ', $value)))) !== '' ? ucwords(strtolower($value)) : null;

        return [
            'document_type' => 'passport',
            'document_number' => rtrim($number, '<'),
            'first_name' => $name($given) ?? $name($surname),
            'last_name' => $name($given) !== null ? $name($surname) : null,
            'gender' => match ($second[20]) {
                'M' => 'Male',
                'F' => 'Female',
                'X' => 'Other',
                default => null,
            },
            'date_of_birth' => $this->date($birth, past: true),
            'nationality' => $this->country(substr($second, 10, 3)),
            'address' => null,
            'postal_code' => null,
            'date_of_issue' => null,
            'date_of_expiry' => $this->date($expiry, past: false),
            'place_of_issue' => null,
            'confidence' => 'high',
            'notes' => 'Read from the passport\'s machine-readable zone (check digits verified).',
        ];
    }

    /**
     * ICAO 9303 check digit: weights 7, 3, 1; digits as themselves, A–Z as 10–35, "<" as 0.
     */
    public function checkDigit(string $value): int
    {
        $weights = [7, 3, 1];
        $sum = 0;

        foreach (str_split($value) as $index => $character) {
            $number = match (true) {
                ctype_digit($character) => (int) $character,
                ctype_upper($character) => ord($character) - 55,
                default => 0,
            };
            $sum += $number * $weights[$index % 3];
        }

        return $sum % 10;
    }

    private function fit(string $line): string
    {
        return str_pad(substr($line, 0, self::LINE_LENGTH), self::LINE_LENGTH, '<');
    }

    /**
     * OCR reads some digits as letters; in the second line's date and check-digit positions
     * only digits (or "<") are possible.
     */
    private function numericFix(string $line): string
    {
        $line = $this->fit($line);
        $map = ['O' => '0', 'Q' => '0', 'D' => '0', 'I' => '1', 'L' => '1', 'Z' => '2', 'S' => '5', 'B' => '8', 'G' => '6'];

        foreach ([9, 13, 14, 15, 16, 17, 18, 19, 21, 22, 23, 24, 25, 26, 27, 43] as $position) {
            $line[$position] = $map[$line[$position]] ?? $line[$position];
        }

        return $line;
    }

    /**
     * In the country-code positions only letters are possible; OCR reads some as digits.
     *
     * @param  list<int>  $positions
     */
    private function letterFix(string $line, array $positions): string
    {
        $map = ['0' => 'O', '1' => 'I', '2' => 'Z', '5' => 'S', '8' => 'B', '6' => 'G'];

        foreach ($positions as $position) {
            $line[$position] = $map[$line[$position]] ?? $line[$position];
        }

        return $line;
    }

    /**
     * YYMMDD as YYYY-MM-DD: a birth date is in the past, an expiry date within this century.
     */
    private function date(string $value, bool $past): ?string
    {
        if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $value, $match) !== 1) {
            return null;
        }

        $year = 2000 + (int) $match[1];

        if ($past && $year > (int) CarbonImmutable::now()->format('Y')) {
            $year -= 100;
        }

        return checkdate((int) $match[2], (int) $match[3], $year) ? sprintf('%04d-%s-%s', $year, $match[2], $match[3]) : null;
    }

    /**
     * A nationality code (ISO 3166 alpha-3; Germany is "D") as the country's name.
     */
    private function country(string $code): ?string
    {
        $code = rtrim($code, '<');
        $code = $code === 'D' ? 'DEU' : $code;

        if ($code === '') {
            return null;
        }

        return Country::query()->where('iso3', $code)->value('name') ?? $code;
    }
}
