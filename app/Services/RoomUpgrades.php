<?php

namespace App\Services;

use App\Enums\SubBookingStatus;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoomUpgrade;
use App\Models\SubBooking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Upgrading a booked or in-house room to another room category. A complimentary upgrade keeps
 * the rate; a paid one adds a supplement per night for the nights from the upgrade (today, for
 * a guest already in house) to check-out, so the booking's room total, taxes and total grow by
 * that much and the folio follows. Categories marked "Exclude from upgrade" and sell-by-bed
 * dormitories are never offered. Each upgrade is kept with its reason and who made it.
 */
class RoomUpgrades
{
    public function __construct(private RoomInventory $inventory, private BookingEngineService $rates, private BookingPricing $pricing) {}

    /**
     * The categories the room can move to for the nights it has left, each with its free rooms
     * and the suggested supplement (its rate less the room's, per night).
     *
     * @return array{nights: int, from: string, to: string, current_rate: float, options: list<array{id: int, name: string, free_rooms: list<array{id: int, name: string}>, suggested_supplement: float}>}
     */
    public function options(SubBooking $room): array
    {
        $this->assertUpgradable($room);
        $room->loadMissing(['booking.property', 'roomCategory']);
        [$from, $to, $nights] = $this->upgradedNights($room);
        $property = $room->booking->property;
        $capacity = $this->inventory->capacityByCategory($property->id);
        $available = $this->inventory->availabilityByCategory($property->id, $capacity, $from, $to, null, $room->id);
        $taken = $this->inventory->unavailableRoomIds($property->id, $from, $to, null, $room->id);

        $options = array_values(RoomCategory::query()
            ->where('property_id', $property->id)
            ->whereKeyNot($room->room_category_id)
            ->where('exclude_from_upgrade', false)
            ->where('sell_by_bed', false)
            ->orderBy('display_order')
            ->get()
            ->filter(fn (RoomCategory $category): bool => (int) ($available[$category->id] ?? 0) > 0)
            ->map(fn (RoomCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'free_rooms' => array_values(Room::query()->where('room_category_id', $category->id)->whereNotIn('id', $taken)->orderBy('display_order')->orderBy('name')->get(['id', 'name'])->map(fn (Room $freeRoom): array => ['id' => $freeRoom->id, 'name' => $freeRoom->name])->all()),
                'suggested_supplement' => $this->suggestedSupplement($room, $category, $from, $to),
            ])
            ->all());

        return ['nights' => $nights, 'from' => $from, 'to' => $to, 'current_rate' => (float) $room->rate_per_night, 'options' => $options];
    }

    /**
     * @throws ValidationException
     */
    public function upgrade(SubBooking $room, RoomCategory $category, ?int $roomId, string $type, float $supplementPerNight, string $reason, User $user): RoomUpgrade
    {
        $this->assertUpgradable($room);
        $room->loadMissing(['booking.property', 'roomCategory']);
        $booking = $room->booking;
        $property = $booking->property;

        if ($category->property_id !== $property->id || $category->id === $room->room_category_id || $category->exclude_from_upgrade || $category->sell_by_bed) {
            throw ValidationException::withMessages(['room_category_id' => "The room can't be upgraded to {$category->name}."]);
        }

        [$from, $to, $nights] = $this->upgradedNights($room);
        $capacity = $this->inventory->capacityByCategory($property->id)->only([$category->id]);

        if ((int) ($this->inventory->availabilityByCategory($property->id, $capacity, $from, $to, null, $room->id)[$category->id] ?? 0) < 1) {
            throw ValidationException::withMessages(['room_category_id' => "No {$category->name} room is free for these nights."]);
        }

        $taken = $this->inventory->unavailableRoomIds($property->id, $from, $to, null, $room->id);

        if ($roomId !== null && (! Room::query()->whereKey($roomId)->where('room_category_id', $category->id)->exists() || $taken->contains($roomId))) {
            throw ValidationException::withMessages(['room_id' => 'That room is not a free room of the new category.']);
        }

        $supplement = $type === RoomUpgrade::PAID ? round(max(0.0, $supplementPerNight), 2) : 0.0;

        if ($type === RoomUpgrade::PAID && $supplement <= 0) {
            throw ValidationException::withMessages(['supplement_per_night' => 'A paid upgrade needs a supplement per night.']);
        }

        return DB::transaction(function () use ($room, $booking, $property, $category, $roomId, $taken, $type, $supplement, $nights, $reason, $user): RoomUpgrade {
            $fromCategory = $room->roomCategory;
            $fromRoomId = $room->room_id;
            $fromRoomName = $room->room?->name;
            $stayNights = BookingPricing::nights($room->check_in_date->toDateString(), $room->check_out_date->toDateString());
            $rateBefore = (float) $room->rate_per_night;
            // The supplement is for the nights left, spread over the room's stay.
            $rateAfter = round($rateBefore + $supplement * $nights / $stayNights, 2);
            $targetRoomId = $roomId ?? $this->inventory->firstFreeRoomId($category->id, $taken);

            $priceDelta = $this->priceDelta($booking, $room, $rateAfter);

            $room->update(['room_category_id' => $category->id, 'room_id' => $targetRoomId, 'rate_per_night' => $rateAfter]);

            if ($priceDelta !== null) {
                $booking->forceFill([
                    'room_total' => round((float) $booking->room_total + $priceDelta['room_total'], 2),
                    'taxes' => round((float) $booking->taxes + $priceDelta['taxes'], 2),
                    'total' => round((float) $booking->total + $priceDelta['total'], 2),
                ])->save();
            }

            app(BookingLedgerSync::class)->schedule($booking->id);

            $upgrade = RoomUpgrade::query()->create([
                'property_id' => $property->id,
                'booking_id' => $booking->id,
                'sub_booking_id' => $room->id,
                'from_room_category_id' => $fromCategory->id,
                'to_room_category_id' => $category->id,
                'from_room_id' => $fromRoomId,
                'to_room_id' => $targetRoomId,
                'type' => $type,
                'supplement_per_night' => $supplement,
                'nights' => $nights,
                'rate_before' => $rateBefore,
                'rate_after' => $rateAfter,
                'reason' => $reason,
                'upgraded_by' => $user->id,
            ]);

            $toRoomName = $targetRoomId ? Room::query()->whereKey($targetRoomId)->value('name') : null;
            ActivityLog::create([
                'user_id' => $user->id,
                'property_id' => $property->id,
                'booking_id' => $booking->id,
                'guest_id' => $booking->guest_id,
                'action' => ($type === RoomUpgrade::PAID ? 'Paid' : 'Complimentary')." upgrade: {$fromCategory->name}".($fromRoomName ? " ({$fromRoomName})" : '')
                    ." → {$category->name}".($toRoomName ? " ({$toRoomName})" : '')
                    .($type === RoomUpgrade::PAID ? ', supplement '.number_format($supplement, 2)." × {$nights} night(s)" : '')
                    .". Reason: {$reason}",
                'date' => now(),
            ]);

            return $upgrade;
        });
    }

    /**
     * The nights the upgrade covers: from today for a guest in house, else the whole stay.
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private function upgradedNights(SubBooking $room): array
    {
        $today = $room->booking->property->today();
        $from = $room->status === SubBookingStatus::CheckedIn ? max($room->check_in_date->toDateString(), $today) : $room->check_in_date->toDateString();
        $to = $room->check_out_date->toDateString();

        if ($from >= $to) {
            throw ValidationException::withMessages(['room' => 'The room has no nights left to upgrade.']);
        }

        return [$from, $to, BookingPricing::nights($from, $to)];
    }

    /**
     * The new category's average rate less the room's, per night (never below zero).
     */
    private function suggestedSupplement(SubBooking $room, RoomCategory $category, string $from, string $to): float
    {
        $mealPlans = $category->meal_plans ?: ['EP'];
        $mealPlan = in_array($room->meal_plan, $mealPlans, true) ? $room->meal_plan : $mealPlans[0];
        $rate = $this->rates->averageNightlyRate($room->booking->property, $category, (string) $mealPlan, max(1, (int) $room->adults), CarbonImmutable::parse($from), CarbonImmutable::parse($to));

        return $rate > 0 ? round(max(0.0, $rate - (float) $room->rate_per_night), 2) : 0.0;
    }

    /**
     * How the booking's room total, taxes and total change when the room's rate changes, priced
     * the way the booking form prices it. Null when nothing changes.
     *
     * @return array{room_total: float, taxes: float, total: float}|null
     */
    private function priceDelta(Booking $booking, SubBooking $upgraded, float $rateAfter): ?array
    {
        if (abs($rateAfter - (float) $upgraded->rate_per_night) < 0.005) {
            return null;
        }

        $rooms = $booking->rooms()->get();
        $lines = fn (bool $after): array => array_values($rooms->map(fn (SubBooking $room): array => [
            'rate_per_night' => $after && $room->is($upgraded) ? $rateAfter : (float) $room->rate_per_night,
            'nights' => BookingPricing::nights($room->check_in_date->toDateString(), $room->check_out_date->toDateString()),
        ])->all());

        $before = $this->pricing->totals($booking->property, $lines(false), (float) $booking->discount);
        $after = $this->pricing->totals($booking->property, $lines(true), (float) $booking->discount);

        return [
            'room_total' => round($after['room_total'] - $before['room_total'], 2),
            'taxes' => round($after['taxes'] - $before['taxes'], 2),
            'total' => round($after['total'] - $before['total'], 2),
        ];
    }

    private function assertUpgradable(SubBooking $room): void
    {
        if (! in_array($room->status, [SubBookingStatus::Booked, SubBookingStatus::Hold, SubBookingStatus::CheckedIn], true)) {
            throw ValidationException::withMessages(['room' => 'Only a booked or in-house room can be upgraded.']);
        }
    }
}
