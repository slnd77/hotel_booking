<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads a guest's identity document (Aadhaar, passport, voter ID, driving licence, PAN) from a
 * photo or scan and returns its fields, for the Add/Update Guest form to fill in. The image is
 * sent to the Claude API once and never stored. Staff review the fields before saving.
 */
class IdDocumentOcr
{
    public const DOCUMENT_TYPES = ['passport', 'aadhaar', 'voter_id', 'driving_licence', 'pan', 'other', 'not_an_id'];

    public const DOCUMENT_LABELS = [
        'passport' => 'Passport',
        'aadhaar' => 'Aadhaar',
        'voter_id' => 'Voter ID',
        'driving_licence' => 'Driving Licence',
        'pan' => 'PAN',
        'other' => 'ID document',
        'not_an_id' => 'Not an ID document',
    ];

    private const SYSTEM_PROMPT = <<<'PROMPT'
You read identity documents for a hotel's front desk in India and return the fields printed on them. Transcribe exactly what is printed; never guess or invent a value — use null for anything not legible or not on the document. Dates as YYYY-MM-DD. Names split into first name (given names) and last name (surname); a single-word name goes in first_name. Document numbers without spaces (an Aadhaar number is 12 digits; a voter EPIC is 10 characters; a passport number is 8 characters). Nationality as the country name in English (an Aadhaar, voter ID, PAN or Indian driving licence means "India"). Address as one line, with the PIN code separately. Set document_type to not_an_id when the image is not an identity document.
PROMPT;

    /**
     * @return array{document_type: string, document_number: string|null, first_name: string|null, last_name: string|null, gender: string|null, date_of_birth: string|null, nationality: string|null, address: string|null, postal_code: string|null, date_of_issue: string|null, date_of_expiry: string|null, place_of_issue: string|null, confidence: string, notes: string|null}
     */
    public function read(UploadedFile $image): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('ID scanning is not set up: add ANTHROPIC_API_KEY to the environment.');
        }

        $contents = $image->get();

        if ($contents === false) {
            throw new RuntimeException('The ID could not be read: the uploaded file could not be opened.');
        }

        $response = Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.api_key'),
            'anthropic-version' => '2026-10-21',
            // A declined request is re-run on a fallback model inside the same call.
            'anthropic-beta' => 'server-side-fallback-2026-07-01',
        ])
            ->timeout(90)
            ->post(rtrim((string) config('services.anthropic.base_url'), '/').'/v1/messages', [
                'model' => (string) config('services.anthropic.model'),
                'max_tokens' => 2048,
                'fallbacks' => 'default',
                'system' => self::SYSTEM_PROMPT,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image->getMimeType() ?: 'image/jpeg', 'data' => base64_encode($contents)]],
                        ['type' => 'text', 'text' => 'Read this identity document and return its fields.'],
                    ],
                ]],
                'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::schema()]],
            ]);

        if ($response->failed()) {
            $message = (string) ($response->json('error.message') ?? 'The ID could not be read.');

            throw new RuntimeException("ID scan failed ({$response->status()}): {$message}");
        }

        if ($response->json('stop_reason') === 'refusal') {
            throw new RuntimeException('The ID could not be read: the request was declined. Try a clearer photo of the document only.');
        }

        $text = $response->collect('content')->firstWhere('type', 'text')['text'] ?? '';
        $fields = json_decode($text, true);

        if (! is_array($fields) || ! isset($fields['document_type'])) {
            throw new RuntimeException('The ID could not be read: the scanner returned no fields.');
        }

        return $this->normalise($fields);
    }

    public function isConfigured(): bool
    {
        return filled(config('services.anthropic.api_key'));
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array{document_type: string, document_number: string|null, first_name: string|null, last_name: string|null, gender: string|null, date_of_birth: string|null, nationality: string|null, address: string|null, postal_code: string|null, date_of_issue: string|null, date_of_expiry: string|null, place_of_issue: string|null, confidence: string, notes: string|null}
     */
    private function normalise(array $fields): array
    {
        $text = fn (string $key): ?string => filled($fields[$key] ?? null) && is_string($fields[$key]) ? trim($fields[$key]) : null;
        $date = function (string $key) use ($text): ?string {
            $value = $text($key);

            return $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : null;
        };
        $gender = match (strtolower((string) $text('gender'))) {
            'male', 'm' => 'Male',
            'female', 'f' => 'Female',
            'other', 'transgender', 'x' => 'Other',
            default => null,
        };

        return [
            'document_type' => in_array($fields['document_type'], self::DOCUMENT_TYPES, true) ? $fields['document_type'] : 'other',
            'document_number' => ($number = $text('document_number')) !== null ? strtoupper(preg_replace('/\s+/', '', $number) ?? $number) : null,
            'first_name' => $text('first_name'),
            'last_name' => $text('last_name'),
            'gender' => $gender,
            'date_of_birth' => $date('date_of_birth'),
            'nationality' => $text('nationality'),
            'address' => $text('address'),
            'postal_code' => $text('postal_code'),
            'date_of_issue' => $date('date_of_issue'),
            'date_of_expiry' => $date('date_of_expiry'),
            'place_of_issue' => $text('place_of_issue'),
            'confidence' => in_array($fields['confidence'] ?? null, ['low', 'medium', 'high'], true) ? $fields['confidence'] : 'low',
            'notes' => $text('notes'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type' => 'object',
            'properties' => [
                'document_type' => ['type' => 'string', 'enum' => self::DOCUMENT_TYPES],
                'document_number' => $nullableString,
                'first_name' => $nullableString,
                'last_name' => $nullableString,
                'gender' => ['type' => ['string', 'null'], 'enum' => ['Male', 'Female', 'Other', null]],
                'date_of_birth' => $nullableString,
                'nationality' => $nullableString,
                'address' => $nullableString,
                'postal_code' => $nullableString,
                'date_of_issue' => $nullableString,
                'date_of_expiry' => $nullableString,
                'place_of_issue' => $nullableString,
                'confidence' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                'notes' => $nullableString,
            ],
            'required' => ['document_type', 'document_number', 'first_name', 'last_name', 'gender', 'date_of_birth', 'nationality', 'address', 'postal_code', 'date_of_issue', 'date_of_expiry', 'place_of_issue', 'confidence', 'notes'],
            'additionalProperties' => false,
        ];
    }
}
