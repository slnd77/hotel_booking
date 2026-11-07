<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SubBookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Property;
use App\Services\PermissionGate;
use App\Services\RoomStays;
use App\Services\StayTimeCharges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Early check-in / late check-out charges: the property's tiers (Property → Early / Late
 * charges) and what a check-in or check-out at a given time would charge (shown in the
 * booking page's check-in and check-out dialogs).
 */
class StayTimeChargeController extends Controller
{
    public function __construct(private StayTimeCharges $charges) {}

    public function update(Request $request, Property $property): RedirectResponse
    {
        $tierRules = fn (string $side): array => [
            "{$side}.enabled" => ['boolean'],
            "{$side}.tiers" => ['array', 'max:6'],
            "{$side}.tiers.*.time" => ['required', 'date_format:H:i', 'distinct'],
            "{$side}.tiers.*.type" => ['required', Rule::in(['percent', 'fixed'])],
            "{$side}.tiers.*.value" => ['required', 'numeric', 'min:0', 'max:1000000'],
        ];

        $data = $request->validate([
            ...$tierRules(StayTimeCharges::EARLY),
            ...$tierRules(StayTimeCharges::LATE),
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'sac_hsn_code' => ['nullable', 'string', 'max:8'],
        ], [
            '*.tiers.*.time.distinct' => 'Two tiers have the same time.',
        ]);

        $settings = $this->charges->defaults();

        foreach ([StayTimeCharges::EARLY, StayTimeCharges::LATE] as $side) {
            $settings[$side] = [
                'enabled' => (bool) ($data[$side]['enabled'] ?? false),
                'tiers' => collect($data[$side]['tiers'] ?? [])
                    ->map(fn (array $tier): array => ['time' => $tier['time'], 'type' => $tier['type'], 'value' => round((float) $tier['value'], 2)])
                    ->sortBy('time')
                    ->values()
                    ->all(),
            ];
        }

        $settings['grace_minutes'] = (int) $data['grace_minutes'];
        $settings['tax_rate'] = round((float) $data['tax_rate'], 2);
        $settings['sac_hsn_code'] = trim((string) ($data['sac_hsn_code'] ?? ''));

        $this->charges->saveSettings($property, $settings, $request->user());

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'early-late-charges'])->with('success', 'Early check-in / late check-out charges saved.');
    }

    /**
     * What checking these rooms in (early) or out (late) at the reported time would charge.
     */
    public function preview(Request $request, Property $property, Booking $booking): JsonResponse
    {
        abort_unless($booking->property_id === $property->id, 404);

        $data = $request->validate([
            'kind' => ['required', Rule::in([StayTimeCharges::EARLY, StayTimeCharges::LATE])],
            'sub_booking_ids' => ['nullable', 'array'],
            'sub_booking_ids.*' => ['integer'],
            'reported_at' => ['nullable', 'date'],
        ]);

        $at = RoomStays::reportedAt($data['reported_at'] ?? null);
        $from = $data['kind'] === StayTimeCharges::EARLY ? SubBookingStatus::Booked : SubBookingStatus::CheckedIn;
        $rooms = $booking->subBookings()
            ->where('status', $from)
            ->when(filled($data['sub_booking_ids'] ?? null), fn ($query) => $query->whereKey($data['sub_booking_ids']))
            ->with(['booking.property', 'inventoryUnit'])
            ->orderBy('id')
            ->get();

        $quotes = $this->charges->quotes($rooms, $data['kind'], $at);

        return response()->json([
            'charges' => $quotes,
            'total' => round((float) collect($quotes)->sum('total'), 2),
            'standard_time' => $this->charges->standardTimes($property)[$data['kind'] === StayTimeCharges::EARLY ? 'check_in' : 'check_out'],
            'can_waive' => app(PermissionGate::class)->allows($request->user(), 'reservation.waive_stay_charges'),
        ]);
    }
}
