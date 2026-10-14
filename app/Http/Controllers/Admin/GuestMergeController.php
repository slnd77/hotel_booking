<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Services\GuestMerge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Databases → Guests → Merge: the same person entered twice becomes one guest.
 */
class GuestMergeController extends Controller
{
    public function __construct(private GuestMerge $merge) {}

    /**
     * Likely duplicates of the guest (same phone, e-mail or name), or guests matching a search.
     */
    public function candidates(Request $request, Guest $guest): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:255']]);

        return response()->json([
            'guests' => $this->merge->candidates($guest, $data['q'] ?? null)->map(fn (Guest $candidate): array => [
                'id' => $candidate->id,
                'name' => trim("{$candidate->first_name} {$candidate->last_name}"),
                'phone' => trim("{$candidate->phone_country_code} {$candidate->phone}") ?: null,
                'email' => collect((array) $candidate->emails)->filter()->first(),
                'bookings' => $candidate->bookings()->count(),
            ])->values(),
        ]);
    }

    public function store(Request $request, Guest $guest): RedirectResponse
    {
        $request->validate(['duplicate_id' => ['required', 'integer']]);

        // Looked up through the model, so a guest of another business account is never found.
        $duplicate = Guest::query()->find($request->integer('duplicate_id'))
            ?? throw ValidationException::withMessages(['duplicate_id' => 'That guest was not found.']);

        $this->merge->merge($guest, $duplicate, $request->user());

        return back()->with('success', "Guest #{$duplicate->id} was merged into guest #{$guest->id}.");
    }
}
