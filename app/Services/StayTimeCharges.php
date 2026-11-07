<?php

namespace App\Services;

use App\Enums\SubBookingStatus;
use App\Models\ActivityLog;
use App\Models\BookingAddon;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\SubBooking;
use App\Models\User;
use Carbon\CarbonInterface;
use Closure;

/**
 * Property → Early / Late charges: what a guest pays for arriving before the property's
 * check-in time or leaving after its check-out time (Property → Policies). Each side has
 * tiers by clock time ("arriving before 09:00: 50% of the night rate"); the front desk sees
 * the charge when it checks a room in or out, and may waive it.
 *
 * A charge is a booking add-on line for that room, so it reaches the master folio, the
 * outstanding amount and the books like any add-on; undoing the check-in or check-out
 * removes it again.
 */
class StayTimeCharges
{
    public const KEY = 'stay_time_charges';

    public const EARLY = 'early_check_in';

    public const LATE = 'late_check_out';

    public function __construct(private PropertySettings $propertySettings, private BookingAddons $addons) {}

    /**
     * @return array{early_check_in: array{enabled: bool, tiers: list<array{time: string, type: string, value: float}>}, late_check_out: array{enabled: bool, tiers: list<array{time: string, type: string, value: float}>}, grace_minutes: int, tax_rate: float, sac_hsn_code: string}
     */
    public function defaults(): array
    {
        return [
            self::EARLY => ['enabled' => false, 'tiers' => []],
            self::LATE => ['enabled' => false, 'tiers' => []],
            'grace_minutes' => 0,
            'tax_rate' => 0.0,
            'sac_hsn_code' => '',
        ];
    }

    /**
     * @return array{early_check_in: array{enabled: bool, tiers: list<array{time: string, type: string, value: float}>}, late_check_out: array{enabled: bool, tiers: list<array{time: string, type: string, value: float}>}, grace_minutes: int, tax_rate: float, sac_hsn_code: string}
     */
    public function settings(Property $property): array
    {
        $stored = $this->propertySettings->json($property, self::KEY) ?? [];
        $settings = $this->defaults();

        foreach ([self::EARLY, self::LATE] as $kind) {
            $side = (array) ($stored[$kind] ?? []);
            $settings[$kind] = [
                'enabled' => (bool) ($side['enabled'] ?? false),
                'tiers' => collect((array) ($side['tiers'] ?? []))
                    ->map(fn (mixed $tier): array => ['time' => (string) ($tier['time'] ?? ''), 'type' => ($tier['type'] ?? '') === 'fixed' ? 'fixed' : 'percent', 'value' => (float) ($tier['value'] ?? 0)])
                    ->filter(fn (array $tier): bool => preg_match('/^\d{2}:\d{2}$/', $tier['time']) === 1)
                    ->sortBy('time')
                    ->values()
                    ->all(),
            ];
        }

        $settings['grace_minutes'] = (int) ($stored['grace_minutes'] ?? 0);
        $settings['tax_rate'] = (float) ($stored['tax_rate'] ?? 0);
        $settings['sac_hsn_code'] = (string) ($stored['sac_hsn_code'] ?? '');

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $settings  validated
     */
    public function saveSettings(Property $property, array $settings, ?User $user = null): void
    {
        $fieldId = MetaField::query()->firstOrCreate(['key' => self::KEY], ['key_label' => 'Early Check-in / Late Check-out Charges', 'status' => true])->id;
        $property->metaFieldValues()->updateOrCreate(['meta_fields_key_id' => $fieldId], ['enabled' => true, 'data' => json_encode($settings)]);
        $this->propertySettings->forget($property);

        if ($user !== null) {
            ActivityLog::create(['user_id' => $user->id, 'property_id' => $property->id, 'action' => 'Early check-in / late check-out charges updated', 'date' => now()]);
        }
    }

    /**
     * The property's standard check-in and check-out times (Property → Policies), as "HH:MM".
     *
     * @return array{check_in: string, check_out: string}
     */
    public function standardTimes(Property $property): array
    {
        $policy = $this->propertySettings->json($property, 'checkin_checkout_time') ?? [];
        $time = fn (string $prefix, int $hour): string => sprintf('%02d:%02d', (int) ($policy["{$prefix}_hour"] ?? $hour), (int) ($policy["{$prefix}_minute"] ?? 0));

        return ['check_in' => $time('check_in', 12), 'check_out' => $time('check_out', 11)];
    }

    /**
     * What arriving (early check-in) or leaving (late check-out) at this time costs for the
     * room, or null when nothing is due.
     *
     * @return array{kind: string, sub_booking_id: int, room: string, tier_time: string, amount: float, tax_rate: float, tax_amount: float, total: float}|null
     */
    public function quote(SubBooking $room, string $kind, CarbonInterface $at): ?array
    {
        $property = $room->booking->property;
        $settings = $this->settings($property);

        if (! $settings[$kind]['enabled'] || $settings[$kind]['tiers'] === []) {
            return null;
        }

        $local = $property->localTime($at);
        $date = $local->toDateString();
        $time = $local->format('H:i');
        $standard = $this->standardTimes($property);
        $grace = $settings['grace_minutes'];
        $tiers = collect($settings[$kind]['tiers']);

        if ($kind === self::EARLY) {
            // Only arrivals on the arrival date, before the check-in time less the grace period.
            $limit = $local->copy()->setTimeFromTimeString($standard['check_in'])->subMinutes($grace)->format('H:i');

            $tier = $date === $room->check_in_date->toDateString() && $time < $limit
                ? $tiers->filter(fn (array $tier): bool => $time < $tier['time'] && $tier['time'] <= $standard['check_in'])->first()
                : null;
        } else {
            $checkOutDate = $room->check_out_date->toDateString();
            $limit = $local->copy()->setTimeFromTimeString($standard['check_out'])->addMinutes($grace)->format('H:i');

            // Leaving after the departure date takes the latest tier; leaving early costs nothing extra.
            $tier = match (true) {
                $date > $checkOutDate => $tiers->last(),
                $date === $checkOutDate && $time > $limit => $tiers->filter(fn (array $tier): bool => $time >= $tier['time'] && $tier['time'] >= $standard['check_out'])->last(),
                default => null,
            };
        }

        if ($tier === null) {
            return null;
        }

        $amount = round($tier['type'] === 'fixed' ? $tier['value'] : (float) $room->rate_per_night * $tier['value'] / 100, 2);

        if ($amount <= 0) {
            return null;
        }

        $tax = round($amount * $settings['tax_rate'] / 100, 2);

        return [
            'kind' => $kind,
            'sub_booking_id' => $room->id,
            'room' => $room->inventoryUnit?->name ?? 'Unassigned room',
            'tier_time' => $tier['time'],
            'amount' => $amount,
            'tax_rate' => $settings['tax_rate'],
            'tax_amount' => $tax,
            'total' => round($amount + $tax, 2),
        ];
    }

    /**
     * The charges these rooms take if they check in (early) or out (late) at this time.
     *
     * @param  iterable<SubBooking>  $rooms
     * @return list<array{kind: string, sub_booking_id: int, room: string, tier_time: string, amount: float, tax_rate: float, tax_amount: float, total: float}>
     */
    public function quotes(iterable $rooms, string $kind, CarbonInterface $at): array
    {
        return collect($rooms)->map(fn (SubBooking $room): ?array => $this->quote($room, $kind, $at))->filter()->values()->all();
    }

    /**
     * Moves a room's stay (the callback) and follows it: checking in early or out late adds the
     * charge (unless the front desk waived it); undoing the check-in or check-out takes it off
     * again. The charge is priced on the stay as it was before the move (an early departure
     * shortens it).
     *
     * @param  Closure(): void  $move
     */
    public function around(SubBooking $room, SubBookingStatus $to, CarbonInterface $at, bool $waive, Closure $move): void
    {
        $from = $room->status;

        $kind = match (true) {
            $from === SubBookingStatus::Booked && $to === SubBookingStatus::CheckedIn => self::EARLY,
            $from === SubBookingStatus::CheckedIn && $to === SubBookingStatus::CheckedOut => self::LATE,
            default => null,
        };

        $removed = match (true) {
            $to === SubBookingStatus::Booked => [self::EARLY, self::LATE],
            $from === SubBookingStatus::CheckedOut && $to === SubBookingStatus::CheckedIn => [self::LATE],
            default => [],
        };

        $quote = $kind === null ? null : $this->quote($room, $kind, $at);

        $move();

        $booking = $room->booking;
        $changed = false;

        if ($removed !== []) {
            $changed = BookingAddon::query()->where('sub_booking_id', $room->id)->whereIn('added_from', $removed)->get()->each->delete()->isNotEmpty();
        }

        if ($quote !== null && $waive) {
            ActivityLog::create([
                'user_id' => auth()->id(),
                'property_id' => $booking->property_id,
                'booking_id' => $booking->id,
                'guest_id' => $booking->guest_id,
                'action' => "{$this->label($kind)} charge of {$quote['total']} waived for {$quote['room']}.",
                'date' => now(),
            ]);
        } elseif ($quote !== null) {
            $settings = $this->settings($booking->property);

            $booking->addons()->create([
                'sub_booking_id' => $room->id,
                'name' => "{$this->label($kind)} · {$quote['room']}",
                'pricing' => 'per_booking',
                'unit_price' => $quote['amount'],
                'quantity' => 1,
                'multiplier' => 1,
                'amount' => $quote['amount'],
                'tax_rate' => $quote['tax_rate'],
                'tax_amount' => $quote['tax_amount'],
                'total' => $quote['total'],
                'sac_hsn_code' => $settings['sac_hsn_code'] ?: null,
                'added_from' => $kind,
                'created_by' => auth()->id(),
            ]);
            $changed = true;
        }

        if ($changed) {
            $this->addons->refreshTotal($booking);
        }
    }

    /**
     * What these rooms' late check-out adds to the amount due before they may leave.
     *
     * @param  iterable<SubBooking>  $rooms
     */
    public function lateCheckOutTotal(iterable $rooms, CarbonInterface $at, bool $waive = false): float
    {
        return $waive ? 0.0 : round((float) collect($this->quotes($rooms, self::LATE, $at))->sum('total'), 2);
    }

    public function label(string $kind): string
    {
        return $kind === self::EARLY ? 'Early check-in' : 'Late check-out';
    }
}
