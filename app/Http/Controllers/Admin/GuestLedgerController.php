<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Property;
use App\Services\GuestLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Booking page → Guest ledger: moves the booking's credit onto its guest's ledger, or uses the
 * ledger's balance on what the booking owes.
 */
class GuestLedgerController extends Controller
{
    public function deposit(Request $request, Property $property, Booking $booking, GuestLedger $ledger): RedirectResponse
    {
        abort_unless($booking->property_id === $property->id, 404);

        $amount = (float) $request->validate(['amount' => ['required', 'numeric', 'min:0.01']])['amount'];

        try {
            $ledger->depositFromBooking($booking, $amount, $request->user());
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->first());
        }

        return back()->with('success', number_format($amount, 2).' moved to the guest ledger.');
    }

    public function apply(Request $request, Property $property, Booking $booking, GuestLedger $ledger): RedirectResponse
    {
        abort_unless($booking->property_id === $property->id, 404);

        $amount = (float) $request->validate(['amount' => ['required', 'numeric', 'min:0.01']])['amount'];

        try {
            $ledger->applyToBooking($booking, $amount, $request->user());
        } catch (ValidationException $exception) {
            return back()->with('error', collect($exception->errors())->flatten()->first());
        }

        return back()->with('success', number_format($amount, 2).' used from the guest ledger.');
    }
}
