<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Property;
use App\Models\SubFolio;
use App\Services\FolioTransfers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Booking page → Folios → Transfer: moves a room folio's (or the master folio's) balance
 * to another folio of the booking.
 */
class FolioTransferController extends Controller
{
    public function __invoke(Request $request, Property $property, Booking $booking, FolioTransfers $transfers): RedirectResponse
    {
        abort_unless($booking->property_id === $property->id, 404);

        $data = $request->validate([
            'from_sub_folio_id' => ['required', 'integer', 'exists:sub_folios,id'],
            'to_sub_folio_id' => ['required', 'integer', 'exists:sub_folios,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $from = SubFolio::query()->with('folio.owner')->findOrFail($data['from_sub_folio_id']);
        $to = SubFolio::query()->with('folio.owner')->findOrFail($data['to_sub_folio_id']);

        try {
            $transfers->transfer($booking, $from, $to, (float) $data['amount']);
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->first());
        }

        $message = number_format((float) $data['amount'], 2).' transferred from '.$transfers->label($from).' to '.$transfers->label($to).'.';

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $property->id,
            'booking_id' => $booking->id,
            'guest_id' => $booking->guests()->first()?->id,
            'action' => "Folio balance of {$message}",
            'date' => now(),
        ]);

        return back()->with('success', $message);
    }
}
