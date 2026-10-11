<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Property;
use App\Services\WebCheckins;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The booking detail page's Web Check-in: make the guest's link, e-mail it, and view what
 * they submitted (their ID document and signature come from the private disk).
 */
class BookingWebCheckinController extends Controller
{
    public function __construct(private WebCheckins $checkins) {}

    /**
     * The guest's link (made on first use), to copy and share.
     */
    public function link(Property $property, Booking $booking): JsonResponse
    {
        abort_unless($booking->property_id === $property->id, 404);
        abort_unless($this->checkins->isEnabled($property), 422, 'Turn on web check-in under Property → Web Check-in first.');

        return response()->json(['url' => $this->checkins->url($this->checkins->for($booking))]);
    }

    public function send(Request $request, Property $property, Booking $booking): RedirectResponse
    {
        abort_unless($booking->property_id === $property->id, 404);
        $email = $request->validate(['email' => ['nullable', 'email:rfc', 'max:255']])['email'] ?? null;

        $this->checkins->send($booking, $email, $request->user());

        return back()->with('success', 'The web check-in link will be e-mailed to the guest.');
    }

    public function document(Property $property, Booking $booking): StreamedResponse
    {
        return $this->file($property, $booking, 'id_document_path');
    }

    public function signature(Property $property, Booking $booking): StreamedResponse
    {
        return $this->file($property, $booking, 'signature_path');
    }

    private function file(Property $property, Booking $booking, string $column): StreamedResponse
    {
        abort_unless($booking->property_id === $property->id, 404);
        $path = $booking->webCheckin?->{$column};
        abort_unless(filled($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, no-store']);
    }
}
