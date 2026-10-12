<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Guest;
use App\Services\GuestPrivacy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Databases → Guests → Privacy: a guest's data access, erasure and marketing withdrawal
 * requests (GDPR / DPDP).
 */
class GuestPrivacyController extends Controller
{
    public function __construct(private GuestPrivacy $privacy) {}

    /**
     * Everything held about the guest, as a JSON download.
     */
    public function export(Request $request, Guest $guest): JsonResponse
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $guest->property_id,
            'guest_id' => $guest->id,
            'action' => "Personal data of guest #{$guest->id} exported.",
            'date' => now(),
        ]);

        return response()->json($this->privacy->export($guest), 200, [
            'Content-Disposition' => "attachment; filename=\"guest-{$guest->id}-data.json\"",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function erase(Request $request, Guest $guest): RedirectResponse
    {
        $this->privacy->erase($guest, $request->user());

        return back()->with('success', "Guest #{$guest->id}'s personal data was erased. Their bookings, invoices and payments are kept under an anonymous name.");
    }

    public function withdrawMarketing(Request $request, Guest $guest): RedirectResponse
    {
        $this->privacy->withdrawMarketing($guest, $request->user());

        return back()->with('success', "Guest #{$guest->id} will no longer receive marketing.");
    }
}
