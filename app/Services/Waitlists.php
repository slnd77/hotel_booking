<?php

namespace App\Services;

use App\Enums\WaitlistStatus;
use App\Models\Booking;
use App\Models\Property;
use App\Models\User;
use App\Models\WaitlistRequest;
use Illuminate\Support\Collection;

/**
 * Waitlist: hold requests for sold-out dates. A request waits until the rooms it asks for are
 * free for its whole stay (a cancellation, a shortened stay, an unblocked room), when it turns
 * "available" for staff to convert into a booking. Requests past their check-in date expire.
 */
class Waitlists
{
    public function __construct(private readonly RoomInventory $inventory) {}

    /**
     * Logs a request and checks straight away whether its rooms are already free (someone may
     * join the list for dates that have just opened up).
     *
     * @param  array{room_category_id?: int|null, guest_id?: int|null, guest_name: string, phone?: string|null, email?: string|null, check_in: string, check_out: string, rooms?: int, adults?: int, children?: int, notes?: string|null}  $attributes
     */
    public function create(Property $property, array $attributes, string $source = WaitlistRequest::SOURCE_FRONT_DESK, ?User $user = null): WaitlistRequest
    {
        $request = $property->waitlistRequests()->create([
            ...$attributes,
            'rooms' => max(1, (int) ($attributes['rooms'] ?? 1)),
            'adults' => max(1, (int) ($attributes['adults'] ?? 2)),
            'children' => max(0, (int) ($attributes['children'] ?? 0)),
            'source' => $source,
            'status' => WaitlistStatus::Waiting,
            'created_by' => $user?->id,
        ]);

        $this->refreshRequest($request, $this->availability($property));

        return $request;
    }

    /**
     * Whether the rooms the request asks for are free for its whole stay: in its category, or
     * (for "any category") across the property.
     */
    public function hasAvailability(WaitlistRequest $request): bool
    {
        return $this->fits($request, $this->availability($request->property));
    }

    /**
     * Re-checks every open request of the property: ones whose rooms freed up turn available
     * (ones whose rooms were taken again go back to waiting); ones past check-in expire.
     *
     * @return array{available: int, waiting: int, expired: int}
     */
    public function refresh(Property $property): array
    {
        $counts = ['available' => 0, 'waiting' => 0, 'expired' => 0];
        $today = $property->today();

        $property->waitlistRequests()->open()->orderBy('created_at')->each(function (WaitlistRequest $request) use (&$counts, $today): void {
            if ($request->check_in->toDateString() < $today) {
                $request->update(['status' => WaitlistStatus::Expired]);
                $counts['expired']++;

                return;
            }

            $status = $this->refreshRequest($request, $this->availability($request->property));
            $counts[$status === WaitlistStatus::Available ? 'available' : 'waiting']++;
        });

        return $counts;
    }

    /**
     * The request became a booking.
     */
    public function markConverted(WaitlistRequest $request, Booking $booking): void
    {
        $request->update(['status' => WaitlistStatus::Converted, 'booking_id' => $booking->id]);
    }

    /**
     * What the booking form starts with when converting the request.
     *
     * @return array{check_in: string, check_out: string, rooms: list<array{room_category_id: int|null, room_id: null}>, waitlist_request_id: int, guest_name: string, guest_id: int|null}
     */
    public function prefill(WaitlistRequest $request): array
    {
        return [
            'check_in' => $request->check_in->toDateString(),
            'check_out' => $request->check_out->toDateString(),
            'rooms' => array_fill(0, $request->rooms, ['room_category_id' => $request->room_category_id, 'room_id' => null]),
            'waitlist_request_id' => $request->id,
            'guest_name' => $request->guest_name,
            'guest_id' => $request->guest_id,
        ];
    }

    private function refreshRequest(WaitlistRequest $request, callable $availability): WaitlistStatus
    {
        $status = $this->fits($request, $availability) ? WaitlistStatus::Available : WaitlistStatus::Waiting;

        if ($status !== $request->status) {
            $request->update(['status' => $status, 'available_since' => $status === WaitlistStatus::Available ? now() : null]);
        }

        return $status;
    }

    private function fits(WaitlistRequest $request, callable $availability): bool
    {
        /** @var Collection<int, int> $free */
        $free = $availability($request->check_in->toDateString(), $request->check_out->toDateString());

        if ($request->room_category_id !== null) {
            return (int) ($free[$request->room_category_id] ?? 0) >= $request->rooms;
        }

        return (int) $free->sum() >= $request->rooms;
    }

    /**
     * Rooms free per category for a stay, with the property's capacity read once.
     *
     * @return callable(string, string): Collection<int, int>
     */
    private function availability(Property $property): callable
    {
        $capacity = $this->inventory->capacityByCategory($property->id);

        return fn (string $checkIn, string $checkOut): Collection => $this->inventory->availabilityByCategory($property->id, $capacity, $checkIn, $checkOut);
    }
}
