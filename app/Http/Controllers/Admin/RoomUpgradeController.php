<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\RoomUpgrade;
use App\Models\SubBooking;
use App\Services\RoomUpgrades;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Booking page → a room → Upgrade: the categories it can move to (with free rooms and the
 * suggested supplement), and the upgrade itself, complimentary or paid, with its reason.
 */
class RoomUpgradeController extends Controller
{
    public function options(Property $property, Booking $booking, SubBooking $subBooking, RoomUpgrades $upgrades): JsonResponse
    {
        $this->ensureRoomOfBooking($property, $booking, $subBooking);

        return response()->json($upgrades->options($subBooking));
    }

    public function store(Request $request, Property $property, Booking $booking, SubBooking $subBooking, RoomUpgrades $upgrades): RedirectResponse
    {
        $this->ensureRoomOfBooking($property, $booking, $subBooking);

        $data = $request->validate([
            'room_category_id' => ['required', 'integer', Rule::exists(RoomCategory::class, 'id')->where('property_id', $property->id)],
            'room_id' => ['nullable', 'integer'],
            'type' => ['required', Rule::in([RoomUpgrade::COMPLIMENTARY, RoomUpgrade::PAID])],
            'supplement_per_night' => ['required_if:type,'.RoomUpgrade::PAID, 'nullable', 'numeric', 'min:0', 'max:9999999'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $upgrade = $upgrades->upgrade(
            $subBooking,
            RoomCategory::query()->findOrFail($data['room_category_id']),
            isset($data['room_id']) ? (int) $data['room_id'] : null,
            $data['type'],
            (float) ($data['supplement_per_night'] ?? 0),
            $data['reason'],
            $request->user(),
        );

        return back()->with('success', 'Room upgraded to '.$upgrade->toCategory->name.($upgrade->type === RoomUpgrade::PAID ? ' (paid).' : ' (complimentary).'));
    }

    private function ensureRoomOfBooking(Property $property, Booking $booking, SubBooking $subBooking): void
    {
        abort_unless($booking->property_id === $property->id && $subBooking->booking_id === $booking->id, 404);
    }
}
