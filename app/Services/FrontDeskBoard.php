<?php

namespace App\Services;

use App\Enums\SubBookingStatus;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\SubBooking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The front desk's day: who arrives, who leaves, who is in the house, and the night's
 * occupancy, ADR and RevPAR (counted like the night audit's day-end report).
 */
class FrontDeskBoard
{
    /**
     * @return array{date: string, is_today: bool, kpis: array<string, int|float>, arrivals: list<array<string, mixed>>, departures: list<array<string, mixed>>, in_house: list<array<string, mixed>>}
     */
    public function forDate(Property $property, CarbonImmutable $date): array
    {
        $day = $date->toDateString();
        $isToday = $day === $property->today();
        $ofProperty = fn (Builder $query) => $query->where('property_id', $property->id);

        $arrivals = $this->stays($property)
            ->whereDate('check_in_date', $day)
            ->whereIn('status', [SubBookingStatus::Hold, SubBookingStatus::Booked, SubBookingStatus::CheckedIn, SubBookingStatus::NoShow])
            ->get();
        $departures = $this->stays($property)
            ->whereDate('check_out_date', $day)
            ->whereIn('status', [SubBookingStatus::CheckedIn, SubBookingStatus::CheckedOut])
            ->get();
        // Today: the guests checked in now. Another day: the guests staying over that night,
        // who arrived on an earlier day; that day's arrivals are only under Arrivals until checked in.
        $inHouse = $isToday
            ? $this->stays($property)->where('status', SubBookingStatus::CheckedIn)->get()
            : $this->stays($property)->whereIn('status', [SubBookingStatus::Hold, SubBookingStatus::Booked, SubBookingStatus::CheckedIn, SubBookingStatus::CheckedOut])->whereDate('check_in_date', '<', $day)->whereDate('check_out_date', '>', $day)->get();

        $sold = SubBooking::query()
            ->whereIn('status', [SubBookingStatus::Booked, SubBookingStatus::CheckedIn, SubBookingStatus::CheckedOut])
            ->whereDate('check_in_date', '<=', $day)
            ->whereDate('check_out_date', '>', $day)
            ->whereHas('booking', $ofProperty)
            ->get(['id', 'rate_per_night']);
        $roomsAvailable = Room::query()->whereHas('roomCategory', $ofProperty)->count();
        $outOfOrder = RoomBlock::query()->where('property_id', $property->id)->overlapping($day, $date->addDay()->toDateString())->distinct()->count('room_id');
        $roomRevenue = round((float) $sold->sum('rate_per_night'), 2);
        $roomsSold = $sold->count();

        return [
            'date' => $day,
            'is_today' => $isToday,
            'kpis' => [
                'rooms_available' => $roomsAvailable,
                'rooms_sold' => $roomsSold,
                'out_of_order' => $outOfOrder,
                'vacant' => max(0, $roomsAvailable - $roomsSold - $outOfOrder),
                'occupancy_percent' => $roomsAvailable > 0 ? round($roomsSold / $roomsAvailable * 100, 2) : 0.0,
                'adr' => $roomsSold > 0 ? round($roomRevenue / $roomsSold, 2) : 0.0,
                'revpar' => $roomsAvailable > 0 ? round($roomRevenue / $roomsAvailable, 2) : 0.0,
                'room_revenue' => $roomRevenue,
                'arrivals_expected' => $arrivals->count(),
                'arrivals_done' => $arrivals->where('status', SubBookingStatus::CheckedIn)->count(),
                'departures_expected' => $departures->count(),
                'departures_done' => $departures->where('status', SubBookingStatus::CheckedOut)->count(),
                'in_house_rooms' => $inHouse->count(),
                'in_house_guests' => (int) $inHouse->sum(fn (SubBooking $stay): int => (int) $stay->adults + (int) $stay->children),
            ],
            'arrivals' => $this->rows($arrivals),
            'departures' => $this->rows($departures),
            'in_house' => $this->rows($inHouse),
        ];
    }

    /**
     * @return Builder<SubBooking>
     */
    private function stays(Property $property): Builder
    {
        return SubBooking::query()
            ->whereHas('booking', fn (Builder $query) => $query->where('property_id', $property->id))
            ->with(['booking:id,booking_ref_id,guest_id,source,outstanding,special_instructions,city_ledger_account_id', 'booking.cityLedgerAccount:id,name', 'booking.primaryGuest:id,first_name,last_name,phone', 'room', 'roomCategory:id,name'])
            ->orderBy('check_in_date')
            ->orderBy('id');
    }

    /**
     * @param  Collection<int, SubBooking>  $stays
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $stays): array
    {
        return $stays->map(function (SubBooking $stay): array {
            $guest = $stay->booking?->primaryGuest;

            return [
                'sub_booking_id' => $stay->id,
                'booking_id' => $stay->booking_id,
                'booking_ref_id' => $stay->booking?->booking_ref_id,
                'guest' => $guest ? trim("{$guest->first_name} {$guest->last_name}") : null,
                // Bill To Guest Company: the company's City Ledger account pays.
                'company' => $stay->booking?->cityLedgerAccount?->name,
                'phone' => $guest?->phone,
                'room' => $stay->room?->name,
                'category' => $stay->roomCategory?->name,
                'adults' => (int) $stay->adults,
                'children' => (int) $stay->children,
                'check_in' => $stay->check_in_date->toDateString(),
                'check_out' => $stay->check_out_date->toDateString(),
                'status' => $stay->status->value,
                'source' => $stay->booking?->source,
                'balance' => round((float) ($stay->booking?->outstanding ?? 0), 2),
                'notes' => $stay->booking?->special_instructions,
            ];
        })->values()->all();
    }
}
