<?php

namespace App\Services;

use RuntimeException;

/**
 * Reads the Secure QR code printed on an Aadhaar (card, e-Aadhaar or PVC card): the QR holds a
 * base-10 number whose bytes are a gzip-compressed record, its fields separated by byte 255,
 * followed by the holder's photo and UIDAI's signature. Only the printed fields are used; the
 * Aadhaar number in it is masked (its last four digits).
 */
class AadhaarSecureQr
{
    /**
     * Fields in order after the optional version ("V2", "V3"): the email/mobile indicator,
     * the reference id (last four digits of the Aadhaar number and a timestamp), then the
     * demographic fields.
     */
    private const FIELDS = ['indicator', 'reference', 'name', 'dob', 'gender', 'care_of', 'district', 'landmark', 'house', 'location', 'pincode', 'post_office', 'state', 'street', 'sub_district', 'vtc'];

    /**
     * Whether a QR code's text is an Aadhaar Secure QR (a long base-10 number).
     */
    public static function looksLike(string $text): bool
    {
        $text = trim($text);

        return strlen($text) >= 500 && ctype_digit($text);
    }

    /**
     * @return array{document_type: string, document_number: string|null, first_name: string|null, last_name: string|null, gender: string|null, date_of_birth: string|null, nationality: string|null, address: string|null, postal_code: string|null, date_of_issue: string|null, date_of_expiry: string|null, place_of_issue: string|null, confidence: string, notes: string|null}
     */
    public function read(string $text): array
    {
        if (! self::looksLike($text)) {
            throw new RuntimeException('That QR code is not an Aadhaar Secure QR.');
        }

        $record = $this->decompress($this->decimalToBytes(trim($text)));
        $parts = explode("\xFF", $record);
        $offset = preg_match('/^V\d+$/', $parts[0]) === 1 ? 1 : 0;

        if (count($parts) < $offset + count(self::FIELDS)) {
            throw new RuntimeException('The Aadhaar QR code could not be read: it has too few fields.');
        }

        /** @var array<string, string> $fields */
        $fields = [];

        foreach (self::FIELDS as $index => $name) {
            $fields[$name] = trim(mb_scrub($parts[$offset + $index], 'UTF-8'));
        }

        if ($fields['name'] === '') {
            throw new RuntimeException('The Aadhaar QR code could not be read: it has no name.');
        }

        [$firstName, $lastName] = $this->splitName($fields['name']);
        $lastFour = preg_match('/^\d{4}/', $fields['reference'], $match) === 1 ? $match[0] : null;
        $pincode = preg_match('/^\d{6}$/', $fields['pincode']) === 1 ? $fields['pincode'] : null;
        $address = implode(', ', array_values(array_filter(
            [$fields['house'], $fields['street'], $fields['landmark'], $fields['location'], $fields['vtc'], $fields['sub_district'], $fields['district'], $fields['state']],
            fn (string $part): bool => $part !== '',
        )));

        return [
            'document_type' => 'aadhaar',
            'document_number' => $lastFour !== null ? 'XXXXXXXX'.$lastFour : null,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => match (strtoupper($fields['gender'])) {
                'M' => 'Male',
                'F' => 'Female',
                'T' => 'Other',
                default => null,
            },
            'date_of_birth' => $this->date($fields['dob']),
            'nationality' => 'India',
            'address' => $address !== '' ? $address : null,
            'postal_code' => $pincode,
            'date_of_issue' => null,
            'date_of_expiry' => null,
            'place_of_issue' => null,
            'confidence' => 'high',
            'notes' => 'Read from the Aadhaar Secure QR code (its digital signature is not checked).',
        ];
    }

    /**
     * The QR number's bytes, most significant first.
     */
    private function decimalToBytes(string $decimal): string
    {
        if (! is_numeric($decimal)) {
            throw new RuntimeException('That QR code is not an Aadhaar Secure QR.');
        }

        if (function_exists('gmp_init')) {
            return gmp_export(gmp_init($decimal, 10));
        }

        if (! function_exists('bcdiv')) {
            throw new RuntimeException('Reading an Aadhaar QR code needs the PHP bcmath or gmp extension.');
        }

        $bytes = '';

        while (bccomp($decimal, '0') > 0) {
            $bytes = chr((int) bcmod($decimal, '256')).$bytes;
            $decimal = bcdiv($decimal, '256', 0);
        }

        return $bytes;
    }

    private function decompress(string $bytes): string
    {
        $record = @gzdecode($bytes);

        if ($record === false) {
            $record = @zlib_decode($bytes);
        }

        if ($record === false) {
            throw new RuntimeException('The Aadhaar QR code could not be read: its data is not compressed as expected.');
        }

        return $record;
    }

    /**
     * Given names and the surname (the last word); a one-word name is a first name.
     *
     * @return array{0: string, 1: string|null}
     */
    private function splitName(string $name): array
    {
        $words = preg_split('/\s+/', $name) ?: [$name];

        if (count($words) === 1) {
            return [$name, null];
        }

        $lastName = (string) array_pop($words);

        return [implode(' ', $words), $lastName];
    }

    /**
     * The date of birth as YYYY-MM-DD (the QR has DD-MM-YYYY, DD/MM/YYYY or YYYY-MM-DD).
     */
    private function date(string $value): ?string
    {
        if (preg_match('/^(\d{2})[-\/](\d{2})[-\/](\d{4})$/', $value, $match) === 1 && checkdate((int) $match[2], (int) $match[1], (int) $match[3])) {
            return "{$match[3]}-{$match[2]}-{$match[1]}";
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match) === 1 && checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            return $value;
        }

        return null;
    }
}
