<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Property;
use App\Models\SubBooking;
use App\Services\RoomStays;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Split bookings: rooms of one booking keeping their own dates (e.g. one room
 * arriving a day early). The booking's dates then span its rooms, and its price
 * follows each room's nights.
 */
class RoomDatesController extends Controller
{
    public function split(Request $request, Property $property, Booking $booking, RoomStays $stays): RedirectResponse
    {
        abort_unless($booking->property_id === $property->id, 404);

        $stays->split($booking);
        $this->log($request, $booking, 'Reservation split: each room now keeps its own dates');

        return back()->with('success', 'Booking split — each room can now have its own dates.');
    }

    /**
     * The booking's total before and after the change, for the confirmation step.
     */
    public function preview(Request $request, Property $property, Booking $booking, SubBooking $subBooking, RoomStays $stays): JsonResponse
    {
        $this->ensureBelongs($property, $booking, $subBooking);
        $dates = $this->validatedDates($request);

        return response()->json($stays->previewRoomDates($subBooking, $dates['check_in'], $dates['check_out']));
    }

    public function update(Request $request, Property $property, Booking $booking, SubBooking $subBooking, RoomStays $stays): RedirectResponse
    {
        $this->ensureBelongs($property, $booking, $subBooking);
        $dates = $this->validatedDates($request);
        $roomName = $subBooking->room->name ?? 'Unassigned room';
        $from = $subBooking->check_in_date->format('d M').' – '.$subBooking->check_out_date->format('d M');

        $price = $stays->changeRoomDates($subBooking, $dates['check_in'], $dates['check_out']);

        $this->log($request, $booking, "Room dates changed for {$roomName}: {$from} → ".CarbonImmutable::parse($dates['check_in'])->format('d M').' – '.CarbonImmutable::parse($dates['check_out'])->format('d M Y'));

        return back()->with('success', "Dates changed for {$roomName}.".self::priceNote($price));
    }

    /**
     * " Total changed from X to Y." when the change moved the price, else "".
     *
     * @param  array{current_total: float, new_total: float, difference: float}  $price
     */
    public static function priceNote(array $price): string
    {
        if (abs($price['difference']) < 0.01) {
            return ' The total is unchanged.';
        }

        return ' Total changed from '.number_format($price['current_total'], 2).' to '.number_format($price['new_total'], 2)
            .' ('.($price['difference'] > 0 ? '+' : '−').number_format(abs($price['difference']), 2).').';
    }

    /**
     * @return array{check_in: string, check_out: string}
     */
    private function validatedDates(Request $request): array
    {
        return $request->validate([
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
        ]);
    }

    private function ensureBelongs(Property $property, Booking $booking, SubBooking $subBooking): void
    {
        abort_unless($booking->property_id === $property->id && $subBooking->booking_id === $booking->id, 404);
    }

    private function log(Request $request, Booking $booking, string $action): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $booking->property_id,
            'booking_id' => $booking->id,
            'guest_id' => $booking->guests()->first()?->id,
            'action' => $action,
            'date' => now(),
        ]);
    }
}
