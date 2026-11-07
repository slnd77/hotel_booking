<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Reads a guest's ID photo, first on this server and only then with AI: an Aadhaar's Secure QR
 * code, then a passport's machine-readable zone, then the Claude reader (IdDocumentOcr) for
 * any other ID when it is set up. The photo is never stored.
 */
class IdDocumentScanner
{
    public const METHOD_AADHAAR_QR = 'aadhaar_qr';

    public const METHOD_PASSPORT_MRZ = 'passport_mrz';

    public const METHOD_AI = 'ai';

    public const METHOD_LABELS = [
        self::METHOD_AADHAAR_QR => 'Aadhaar Secure QR',
        self::METHOD_PASSPORT_MRZ => 'passport machine-readable zone',
        self::METHOD_AI => 'AI reading',
    ];

    public function __construct(
        private IdScanTools $tools,
        private AadhaarSecureQr $aadhaar,
        private PassportMrz $passport,
        private IdDocumentOcr $ai,
    ) {}

    /**
     * Whether any reader is available.
     */
    public function isAvailable(): bool
    {
        return $this->tools->canReadQrCodes() || $this->tools->canReadText() || $this->ai->isConfigured();
    }

    /**
     * @return array{method: string, fields: array{document_type: string, document_number: string|null, first_name: string|null, last_name: string|null, gender: string|null, date_of_birth: string|null, nationality: string|null, address: string|null, postal_code: string|null, date_of_issue: string|null, date_of_expiry: string|null, place_of_issue: string|null, confidence: string, notes: string|null}}
     */
    public function read(UploadedFile $image): array
    {
        $path = (string) $image->getRealPath();

        foreach ($this->tools->qrCodes($path) as $code) {
            if (AadhaarSecureQr::looksLike($code)) {
                try {
                    return ['method' => self::METHOD_AADHAAR_QR, 'fields' => $this->aadhaar->read($code)];
                } catch (RuntimeException) {
                    // An unreadable QR code: try the other readers.
                }
            }
        }

        $text = $this->tools->text($path);
        $lines = $text !== null ? $this->passport->find($text) : null;

        if ($lines !== null) {
            try {
                return ['method' => self::METHOD_PASSPORT_MRZ, 'fields' => $this->passport->read(...$lines)];
            } catch (RuntimeException) {
                // A misread zone: let the AI reader try.
            }
        }

        if ($this->ai->isConfigured()) {
            return ['method' => self::METHOD_AI, 'fields' => $this->ai->read($image)];
        }

        throw new RuntimeException('No Aadhaar QR code or passport machine-readable zone was found. Try a sharper, well-lit photo of the whole document; other IDs need AI reading (ANTHROPIC_API_KEY).');
    }
}
