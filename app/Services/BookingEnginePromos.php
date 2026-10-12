<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Promo codes on the booking engine, from Rates → Deals & Promo. Public codes are listed on
 * the page; secret codes are only ever matched here, by the code the guest types. Checkout
 * re-checks the code and prices its discount on the server, and the booking keeps the code
 * so its usage limit and budgets count it.
 */
class BookingEnginePromos
{
    /** Rule fields the booking engine page needs to show and pre-check a code. */
    private const PUBLIC_FIELDS = [
        'id', 'deal_type', 'code', 'tag_line', 'description', 'discount_type', 'discount_value', 'channels',
        'min_length_of_stay', 'length_of_stay', 'cut_off_days', 'last_minute', 'checkin_date_range',
        'checkout_date_range', 'booking_creation_date_range', 'stay_days_of_week', 'exclusion_date_range',
        'booking_amount_range', 'max_discount_per_booking', 'applicable_on_pay_at_hotel',
        'terms_and_conditions', 'status', 'deleted',
    ];

    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function __construct(private PropertySettings $settings) {}

    /**
     * Whether a public code that qualifies is applied without the guest picking it.
     */
    public function autoApplyPublic(Property $property): bool
    {
        return (bool) ($this->settings->json($property, 'rate_settings_deals_promotion')['auto_apply_public_promo'] ?? true);
    }

    /**
     * The live public codes for the booking engine page, without usage limits or budgets.
     *
     * @return list<array<string, mixed>>
     */
    public function publicOffers(Property $property): array
    {
        return array_values($this->list($property, 'public_promo_codes')
            ->filter(fn (array $promo): bool => $this->isLive($promo))
            ->map(fn (array $promo): array => $this->forDisplay($promo))
            ->all());
    }

    /**
     * A live public or secret code by what the guest typed (any letter case).
     *
     * @return array<string, mixed>|null
     */
    public function find(Property $property, ?string $code): ?array
    {
        $code = strtoupper(trim((string) $code));

        if ($code === '') {
            return null;
        }

        return $this->list($property, 'secret_promo_codes')
            ->merge($this->list($property, 'public_promo_codes'))
            ->first(fn (array $promo): bool => $this->isLive($promo) && strtoupper(trim((string) ($promo['code'] ?? ''))) === $code);
    }

    /**
     * Why the code can't be used on this stay, or null when it can.
     *
     * @param  array<string, mixed>  $promo
     */
    public function rejection(Property $property, array $promo, string $checkIn, string $checkOut, float $roomTotal, int $roomCount, bool $payAtHotel = false): ?string
    {
        $from = CarbonImmutable::parse($checkIn)->startOfDay();
        $to = CarbonImmutable::parse($checkOut)->startOfDay();
        $nights = max(1, (int) $from->diffInDays($to));
        $today = CarbonImmutable::parse($property->today());

        foreach ([(int) ($promo['min_length_of_stay'] ?? 0), $this->enabledValue($promo, 'length_of_stay')] as $minimumNights) {
            if ($minimumNights > 0 && $nights < $minimumNights) {
                return "Minimum stay of {$minimumNights} night(s) required.";
            }
        }

        $cutOffDays = $this->enabledValue($promo, 'cut_off_days');

        if ($cutOffDays > 0 && $today->diffInDays($from, false) < $cutOffDays) {
            return "Must be booked at least {$cutOffDays} day(s) before check-in.";
        }

        $lastMinuteHours = $this->enabledValue($promo, 'last_minute');
        $checkInStarts = CarbonImmutable::parse($checkIn, $property->timezoneName())->startOfDay();

        if ($lastMinuteHours > 0 && now()->diffInHours($checkInStarts, false) > $lastMinuteHours) {
            return "Only valid within {$lastMinuteHours} hour(s) of check-in.";
        }

        foreach (['checkin_date_range' => [$checkIn, 'check-in'], 'checkout_date_range' => [$checkOut, 'check-out'], 'booking_creation_date_range' => [$property->today(), 'bookings made']] as $rangeKey => [$date, $label]) {
            [$start, $end] = $this->enabledRange($promo, $rangeKey);

            if ($start !== null && $date < $start) {
                return "Valid for {$label} on or after {$start}.";
            }

            if ($end !== null && $date > $end) {
                return $rangeKey === 'booking_creation_date_range' ? "Promo code expired on {$end}." : "Valid for {$label} on or before {$end}.";
            }
        }

        [$blackoutStart, $blackoutEnd] = $this->enabledRange($promo, 'exclusion_date_range');

        if ($blackoutStart !== null && $blackoutEnd !== null && $checkIn < $blackoutEnd && $checkOut > $blackoutStart) {
            return "Blackout dates apply between {$blackoutStart} and {$blackoutEnd}.";
        }

        if (($promo['stay_days_of_week']['enabled'] ?? false) && ! empty($promo['stay_days_of_week']['days'])) {
            for ($night = $from; $night->lt($to); $night = $night->addDay()) {
                if (! in_array(self::DAY_KEYS[(int) $night->format('w')], $promo['stay_days_of_week']['days'], true)) {
                    return 'Not valid for a stay on '.$night->format('l').'.';
                }
            }
        }

        if ($promo['booking_amount_range']['enabled'] ?? false) {
            $minimum = (float) ($promo['booking_amount_range']['min'] ?? 0);
            $maximum = (float) ($promo['booking_amount_range']['max'] ?? 0);

            if ($minimum > 0 && $roomTotal < $minimum) {
                return 'Minimum booking amount of '.number_format($minimum, 2).' required.';
            }

            if ($maximum > 0 && $roomTotal > $maximum) {
                return 'Maximum booking amount for this promo is '.number_format($maximum, 2).'.';
            }
        }

        if ($payAtHotel && array_key_exists('applicable_on_pay_at_hotel', $promo) && ! $promo['applicable_on_pay_at_hotel']) {
            return 'This promo code needs the full amount paid now.';
        }

        return $this->budgetRejection($property, $promo, $from, $to, $this->discount($promo, $roomTotal), $roomCount);
    }

    /**
     * The code's discount on the room charges (before tax), capped by its per-booking maximum.
     *
     * @param  array<string, mixed>  $promo
     */
    public function discount(array $promo, float $roomTotal): float
    {
        if ($roomTotal <= 0) {
            return 0.0;
        }

        $value = (float) ($promo['discount_value'] ?? 0);
        $discount = ($promo['discount_type'] ?? 'percentage') === 'percentage' ? $roomTotal * $value / 100 : $value;
        $maximum = $this->enabledValue($promo, 'max_discount_per_booking');

        if ($maximum > 0) {
            $discount = min($discount, $maximum);
        }

        return round(max(0.0, min($roomTotal, $discount)), 2);
    }

    /**
     * The fields the booking engine page may see.
     *
     * @param  array<string, mixed>  $promo
     * @return array<string, mixed>
     */
    public function forDisplay(array $promo): array
    {
        return array_intersect_key($promo, array_flip(self::PUBLIC_FIELDS));
    }

    /**
     * The usage limit, total discount budget and per-night room budget, counted over the
     * bookings that carry the code (cancelled ones give their share back).
     *
     * @param  array<string, mixed>  $promo
     */
    private function budgetRejection(Property $property, array $promo, CarbonImmutable $from, CarbonImmutable $to, float $discount, int $roomCount): ?string
    {
        $usageLimit = $this->enabledValue($promo, 'usage_limit');
        $totalBudget = $this->enabledValue($promo, 'max_total_budget');
        $dailyRoomBudget = $this->enabledValue($promo, 'daily_room_count_budget');

        if ($usageLimit <= 0 && $totalBudget <= 0 && $dailyRoomBudget <= 0) {
            return null;
        }

        $bookings = Booking::query()
            ->where('property_id', $property->id)
            ->where('promo_code', (string) $promo['code'])
            ->whereNotIn('status', [BookingStatus::Cancelled, BookingStatus::ExpiredQuote])
            ->withCount('rooms');

        if ($usageLimit > 0 && (clone $bookings)->count() >= $usageLimit) {
            return 'This promo code has been fully redeemed.';
        }

        if ($totalBudget > 0 && (float) (clone $bookings)->sum('discount') + $discount > $totalBudget) {
            return 'This promo code has been fully redeemed.';
        }

        if ($dailyRoomBudget > 0) {
            $overlapping = (clone $bookings)
                ->where('check_in', '<', $to->toDateString())
                ->where('check_out', '>', $from->toDateString())
                ->get(['id', 'check_in', 'check_out']);

            for ($night = $from; $night->lt($to); $night = $night->addDay()) {
                $roomsTaken = $overlapping
                    ->filter(fn (Booking $booking): bool => $booking->check_in->lte($night) && $booking->check_out->gt($night))
                    ->sum('rooms_count');

                if ($roomsTaken + $roomCount > $dailyRoomBudget) {
                    return 'This promo code is sold out for '.$night->format('d M Y').'.';
                }
            }
        }

        return null;
    }

    /**
     * The saved codes of one kind (public or secret), as decoded from the settings JSON.
     *
     * @return Collection<int, array<mixed>>
     */
    private function list(Property $property, string $key): Collection
    {
        $saved = $this->settings->json($property, 'rate_settings_deals_promotion')[$key] ?? [];
        $promos = [];

        foreach (is_array($saved) ? $saved : [] as $promo) {
            if (is_array($promo) && trim((string) ($promo['code'] ?? '')) !== '') {
                $promos[] = $promo;
            }
        }

        return collect($promos);
    }

    /**
     * Switched on, not deleted, and sold on the booking engine.
     *
     * @param  array<string, mixed>  $promo
     */
    private function isLive(array $promo): bool
    {
        $channels = $promo['channels'] ?? [];

        return ($promo['status'] ?? false) && ! ($promo['deleted'] ?? false)
            && (empty($channels) || in_array('booking_engine', $channels, true));
    }

    /**
     * @param  array<string, mixed>  $promo
     */
    private function enabledValue(array $promo, string $key): float
    {
        return ($promo[$key]['enabled'] ?? false) ? (float) ($promo[$key]['value'] ?? 0) : 0.0;
    }

    /**
     * @param  array<string, mixed>  $promo
     * @return array{0: ?string, 1: ?string}
     */
    private function enabledRange(array $promo, string $key): array
    {
        if (! ($promo[$key]['enabled'] ?? false)) {
            return [null, null];
        }

        return [($promo[$key]['start'] ?? null) ?: null, ($promo[$key]['end'] ?? null) ?: null];
    }
}
