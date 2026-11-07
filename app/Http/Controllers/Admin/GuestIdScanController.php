<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Property;
use App\Services\IdDocumentOcr;
use App\Services\IdDocumentScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Add/Update Guest → "Scan ID": reads an identity document photo (Aadhaar Secure QR or passport
 * machine-readable zone on this server, otherwise AI) and returns the guest fields it fills in.
 * The image is read once and never stored; staff review the fields before saving.
 */
class GuestIdScanController extends Controller
{
    public function store(Request $request, Property $property, IdDocumentScanner $scanner): JsonResponse
    {
        if (! $scanner->isAvailable()) {
            return response()->json(['message' => 'ID scanning is not set up: install zbar-tools and tesseract-ocr on the server, or add ANTHROPIC_API_KEY to the environment.'], 503);
        }

        $data = $request->validate([
            'document' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ], ['document.max' => 'The image must be 5 MB or smaller.', 'document.mimes' => 'Upload a JPG, PNG or WebP photo of the document.']);

        try {
            ['method' => $method, 'fields' => $scan] = $scanner->read($data['document']);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if ($scan['document_type'] === 'not_an_id') {
            return response()->json(['message' => "That doesn't look like an identity document. Upload a clear photo of the ID."], 422);
        }

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $property->id,
            'action' => 'Guest ID scanned ('.IdDocumentOcr::DOCUMENT_LABELS[$scan['document_type']].', '.IdDocumentScanner::METHOD_LABELS[$method].') via Web',
            'date' => now(),
        ]);

        return response()->json([
            'scan' => [
                'document_type' => $scan['document_type'],
                'document_label' => IdDocumentOcr::DOCUMENT_LABELS[$scan['document_type']],
                'method' => $method,
                'method_label' => IdDocumentScanner::METHOD_LABELS[$method],
                'document_number' => $scan['document_number'],
                'confidence' => $scan['confidence'],
                'notes' => $scan['notes'],
            ],
            'guest' => $this->guestFields($scan),
        ]);
    }

    /**
     * The scanned fields in the guest form's shape (only what the document carries).
     *
     * @param  array<string, mixed>  $scan
     * @return array<string, mixed>
     */
    private function guestFields(array $scan): array
    {
        $fields = array_filter([
            'first_name' => $scan['first_name'],
            'last_name' => $scan['last_name'],
            'gender' => $scan['gender'],
            'dob' => $scan['date_of_birth'],
            'nationality' => $scan['nationality'],
        ], fn (mixed $value): bool => $value !== null);

        if ($scan['address'] !== null || $scan['postal_code'] !== null) {
            $fields['address'] = array_filter(['address' => $scan['address'], 'postal_code' => $scan['postal_code']], fn (mixed $value): bool => $value !== null);
        }

        if ($scan['document_type'] === 'passport') {
            $fields['international_details'] = array_filter([
                'passport_number' => $scan['document_number'],
                'passport_issue_date' => $scan['date_of_issue'],
                'passport_validity' => $scan['date_of_expiry'],
                'passport_issue_place' => $scan['place_of_issue'],
            ], fn (mixed $value): bool => $value !== null);
        }

        return $fields;
    }
}
