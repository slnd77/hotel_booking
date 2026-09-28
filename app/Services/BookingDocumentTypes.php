<?php

namespace App\Services;

use App\Models\Property;

/**
 * Document types offered in a booking's "Upload Documents" dialog, driven by
 * Booking Settings → Document Settings (`booking_settings_document`).
 *
 * The defaults here must stay in sync with `booking-settings/Edit.vue`, which
 * shows them before a property saves its own configuration.
 */
class BookingDocumentTypes
{
    /**
     * @var list<string>
     */
    public const DEFAULT_NAMES = [
        'Others', 'Aadhaar Card', 'Driving License', 'Passport', 'Pan Card', 'Voter ID', 'User DP',
        'Letter Of Authorization', 'Signature', 'Purchase Order', 'Business Travel Authorization',
        'Certificate of Availability of Fund', 'Contract', 'Memorandum of Agreement', 'Signed Guest Folio',
        'Sales Voucher Form', 'Sales Head Authorization Voucher', 'DNI', 'Carnet De Ext.',
    ];

    /**
     * @var list<string>
     */
    private const DEFAULT_FOLIO_DOCUMENTS = ['Others', 'Aadhaar Card', 'Driving License', 'Passport', 'Pan Card', 'Voter ID', 'User DP'];

    /**
     * Types switched on (Status) and marked as a Folio Document, sorted by name.
     *
     * @return list<array{name: string, require_expiry_date: bool, require_document_number: bool}>
     */
    public function forProperty(Property $property): array
    {
        return array_values(collect($this->configuredRows($property))
            ->filter(fn (array $row): bool => (bool) ($row['status'] ?? false) && (bool) ($row['folio_document'] ?? false))
            ->map(fn (array $row): array => [
                'name' => (string) $row['name'],
                'require_expiry_date' => (bool) ($row['require_expiry_date'] ?? false),
                'require_document_number' => (bool) ($row['require_document_number'] ?? false),
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function configuredRows(Property $property): array
    {
        return app(PropertySettings::class)->remember($property, 'booking_documents.rows', fn (): array => $this->resolveRows($property));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveRows(Property $property): array
    {
        $documents = app(PropertySettings::class)->json($property, 'booking_settings_document')['documents'] ?? [];
        $saved = collect(is_array($documents) ? $documents : [])->filter(fn ($row): bool => is_array($row) && filled($row['name'] ?? null));

        $defaults = collect(self::DEFAULT_NAMES)->map(fn (string $name): array => $saved->firstWhere('name', $name) ?? [
            'name' => $name,
            'status' => in_array($name, self::DEFAULT_FOLIO_DOCUMENTS, true),
            'folio_document' => in_array($name, self::DEFAULT_FOLIO_DOCUMENTS, true),
            'require_expiry_date' => false,
            'require_document_number' => false,
        ]);

        $custom = $saved->reject(fn (array $row): bool => in_array($row['name'], self::DEFAULT_NAMES, true));

        return array_values($defaults->concat($custom)->all());
    }
}
