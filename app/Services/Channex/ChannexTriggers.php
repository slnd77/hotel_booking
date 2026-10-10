<?php

namespace App\Services\Channex;

use App\Jobs\PushChannexAri;
use App\Models\Booking;
use App\Models\ChannexConnection;
use App\Models\RoomBlock;
use App\Models\SubBooking;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Queues a Channex push when something changes what a property can sell: a booking, a
 * room's stay, a room block (availability) or Rate Setup (rates). Only for properties
 * connected to Channex; the push runs after the change commits.
 */
class ChannexTriggers
{
    public function bookingChanged(Booking $booking): void
    {
        $this->availabilityChanged($booking->property_id, [$booking->check_in, $booking->getOriginal('check_in')], [$booking->check_out, $booking->getOriginal('check_out')]);
    }

    public function stayChanged(SubBooking $stay): void
    {
        $propertyId = Booking::query()->whereKey($stay->booking_id)->value('property_id');

        if ($propertyId !== null) {
            $this->availabilityChanged((int) $propertyId, [$stay->check_in_date, $stay->getOriginal('check_in_date')], [$stay->check_out_date, $stay->getOriginal('check_out_date')]);
        }
    }

    public function blockChanged(RoomBlock $block): void
    {
        $this->availabilityChanged((int) $block->property_id, [$block->start_date, $block->getOriginal('start_date')], [$block->end_date, $block->getOriginal('end_date')]);
    }

    public function ratesChanged(int $propertyId): void
    {
        if ($connection = $this->activeConnection($propertyId)) {
            PushChannexAri::dispatch($connection->id, null, null, false, true)->afterCommit();
        }
    }

    /**
     * @param  list<CarbonInterface|string|null>  $starts  first nights, before and after the change
     * @param  list<CarbonInterface|string|null>  $ends  departure days (exclusive), before and after
     */
    private function availabilityChanged(int $propertyId, array $starts, array $ends): void
    {
        $connection = $this->activeConnection($propertyId);
        $starts = array_filter(array_map($this->date(...), $starts));
        $ends = array_filter(array_map($this->date(...), $ends));

        if ($connection === null || $starts === [] || $ends === []) {
            return;
        }

        // The last night is the day before departure.
        $lastNight = CarbonImmutable::parse(max($ends))->subDay()->toDateString();

        PushChannexAri::dispatch($connection->id, min($starts), max(min($starts), $lastNight), true, false)->afterCommit();
    }

    private function activeConnection(int $propertyId): ?ChannexConnection
    {
        return ChannexConnection::query()->where('property_id', $propertyId)->where('is_active', true)->first(['id']);
    }

    private function date(CarbonInterface|string|null $value): ?string
    {
        return match (true) {
            $value instanceof CarbonInterface => $value->toDateString(),
            is_string($value) && $value !== '' => substr($value, 0, 10),
            default => null,
        };
    }
}
