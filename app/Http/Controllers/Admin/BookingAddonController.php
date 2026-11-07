<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\Property;
use App\Services\BookingAddons;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The booking detail page's Add-ons: add a service to the booking, or remove one ("Delete
 * Addon" permission). The ledger sync charges or reverses it on the master folio.
 */
class BookingAddonController extends Controller
{
    public function store(Request $request, Property $property, Booking $booking, BookingAddons $addons): RedirectResponse
    {
        $this->assertChangeable($property, $booking);

        $data = $request->validate([
            'addon_id' => ['required', 'integer', Rule::exists(Addon::class, 'id')->where('property_id', $property->id)->where('status', true)],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $line = $addons->add($booking, Addon::query()->findOrFail($data['addon_id']), (int) $data['quantity'], $request->user());
        $this->log($request, $booking, "add-on {$line->name} × {$line->quantity} added");

        return back()->with('success', "{$line->name} added to the booking.");
    }

    public function destroy(Request $request, Property $property, Booking $booking, BookingAddon $bookingAddon, BookingAddons $addons): RedirectResponse
    {
        $this->assertChangeable($property, $booking);
        abort_unless($bookingAddon->booking_id === $booking->id, 404);

        $addons->remove($bookingAddon);
        $this->log($request, $booking, "add-on {$bookingAddon->name} removed");

        return back()->with('success', "{$bookingAddon->name} removed from the booking.");
    }

    private function assertChangeable(Property $property, Booking $booking): void
    {
        abort_unless($booking->property_id === $property->id, 404);
        abort_if(in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::NoShow, BookingStatus::CheckedOut, BookingStatus::ExpiredQuote], true), 422, "A {$booking->status->label()} booking's add-ons can't be changed.");
    }

    private function log(Request $request, Booking $booking, string $action): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $booking->property_id,
            'booking_id' => $booking->id,
            'guest_id' => $booking->guest_id,
            'action' => "Booking {$booking->booking_ref_id}: {$action}.",
            'date' => now(),
        ]);
    }
}
