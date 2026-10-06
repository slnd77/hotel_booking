<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\SubBookingStatus;
use App\Models\Property;
use App\Models\SubBooking;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The room stays a night's reports work from (Occupied Rooms, Daily Transaction, House
 * Keeping), and what one night of a stay costs.
 */
class NightStays
{
    /** Booking statuses whose rooms count as sold. */
    public const FIRM_BOOKING_STATUSES = [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::CheckedOut];

    /** Room stay statuses that count as sold. */
    public const SOLD_STAY_STATUSES = [SubBookingStatus::Booked, SubBookingStatus::CheckedIn, SubBookingStatus::CheckedOut];

    /**
     * Rooms of firm bookings whose stay covers the night of the date, by room number
     * (rooms not yet assigned last).
     *
     * @return Collection<int, SubBooking>
     */
    public function soldFor(Property $property, CarbonImmutable $date): Collection
    {
        $night = $date->toDateString();

        return $this->sortedByRoom($this->query($property)
            ->whereDate('check_in_date', '<=', $night)
            ->whereDate('check_out_date', '>', $night)
            ->get());
    }

    /**
     * Firm stays touching the date: in house that night, arriving or departing that day.
     *
     * @return Collection<int, SubBooking>
     */
    public function touching(Property $property, CarbonImmutable $date): Collection
    {
        $day = $date->toDateString();

        return $this->query($property)
            ->whereDate('check_in_date', '<=', $day)
            ->whereDate('check_out_date', '>=', $day)
            ->get();
    }

    /**
     * Overstays on the date: guests still in house (checked in, not out) whose check-out
     * date has passed - they keep their room every day up to today.
     *
     * @return Collection<int, SubBooking>
     */
    public function overstaysOn(Property $property, CarbonImmutable $date): Collection
    {
        $day = $date->toDateString();

        if ($day > $property->today()) {
            return new Collection;
        }

        return $this->query($property)
            ->where('status', SubBookingStatus::CheckedIn->value)
            ->whereDate('check_out_date', '<', $day)
            ->get();
    }

    /**
     * One night of the stay: its rate, its share of the booking's discount and taxes
     * (by its part of the booking's room total), and the net and gross price.
     *
     * @return array{rate: float, discount: float, net: float, tax: float, gross: float}
     */
    public function nightPrice(SubBooking $stay): array
    {
        $booking = $stay->booking;
        $rate = (float) $stay->rate_per_night;
        $roomTotal = $booking->rooms->sum(fn (SubBooking $room): float => (float) $room->rate_per_night * BookingPricing::nights($room->check_in_date->toDateString(), $room->check_out_date->toDateString()));
        $share = $roomTotal > 0 ? $rate / $roomTotal : 0.0;
        $discount = round((float) $booking->discount * $share, 2);
        $tax = round((float) $booking->taxes * $share, 2);
        $net = round($rate - $discount, 2);

        return ['rate' => $rate, 'discount' => $discount, 'net' => $net, 'tax' => $tax, 'gross' => round($net + $tax, 2)];
    }

    /**
     * @return Builder<SubBooking>
     */
    private function query(Property $property): Builder
    {
        return SubBooking::query()
            ->whereHas('booking', fn (Builder $query) => $query
                ->where('property_id', $property->id)
                ->whereIn('status', array_map(fn (BookingStatus $status): string => $status->value, self::FIRM_BOOKING_STATUSES)))
            ->whereIn('status', array_map(fn (SubBookingStatus $status): string => $status->value, self::SOLD_STAY_STATUSES))
            ->with(['booking.guests', 'booking.rooms', 'booking.agent.agency', 'room', 'roomCategory', 'folio']);
    }

    /**
     * @param  Collection<int, SubBooking>  $stays
     * @return Collection<int, SubBooking>
     */
    private function sortedByRoom(Collection $stays): Collection
    {
        return $stays
            ->sortBy([
                fn (SubBooking $a, SubBooking $b): int => ($a->room === null) <=> ($b->room === null),
                fn (SubBooking $a, SubBooking $b): int => strnatcasecmp((string) $a->room?->name, (string) $b->room?->name),
            ])
            ->values();
    }
}
