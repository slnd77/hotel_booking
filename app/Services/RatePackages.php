<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\SubBooking;
use Carbon\CarbonImmutable;

/**
 * Selling Rate Settings → Package: a package is a rate sold for chosen room categories and
 * meal plans on the dates the Packages Calendar maps it to, under its own conditions (length
 * of stay, booking window, cut-off, last minute, usage limit, check-in / check-out ranges,
 * days of the week). Its nightly rates come from its own rate grid, rounded like any rate.
 */
class RatePackages
{
    private const DAY_KEYS = [0 => 'sun', 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat'];

    public function __construct(private PropertySettings $settings, private BookingEngineService $rates, private RateRounding $rounding) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(Property $property): array
    {
        return array_values($this->settings->json($property, 'rate_settings_packages')['packages'] ?? []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(Property $property, int $packageId): ?array
    {
        return collect($this->all($property))->firstWhere('id', $packageId);
    }

    /**
     * The packages a room can be booked under for the stay, priced: on the booking engine only
     * those shown there.
     *
     * @return list<array{id: int, name: string, color: string|null, inclusions: string|null, terms: string|null, pay_at_hotel: bool, rate_per_night: float, amount: float, nights: int}>
     */
    public function offers(Property $property, RoomCategory $category, string $mealPlan, int $adults, string $checkIn, string $checkOut, bool $bookingEngine = false, string $channel = 'direct'): array
    {
        $offers = [];

        foreach ($this->all($property) as $package) {
            if ($bookingEngine && ! ($package['show_at_booking_engine'] ?? true)) {
                continue;
            }

            $price = $this->price($property, $package, $category, $mealPlan, $adults, $checkIn, $checkOut, $channel);

            if ($price !== null && $this->rejection($property, $package, $category, $mealPlan, $checkIn, $checkOut) === null) {
                $offers[] = $price;
            }
        }

        return $offers;
    }

    /**
     * Why the package can't be sold for the stay, or null when it can.
     *
     * @param  array<string, mixed>  $package
     */
    public function rejection(Property $property, array $package, RoomCategory $category, string $mealPlan, string $checkIn, string $checkOut): ?string
    {
        $arrival = CarbonImmutable::parse($checkIn)->startOfDay();
        $departure = CarbonImmutable::parse($checkOut)->startOfDay();
        $today = CarbonImmutable::parse($property->today());
        $nights = max(1, (int) $arrival->diffInDays($departure));
        $daysAhead = (int) $today->diffInDays($arrival, false);
        $name = (string) ($package['name'] ?? 'This package');

        if (! in_array($mealPlan, (array) ($package['rooms'][(string) $category->id] ?? $package['rooms'][$category->id] ?? []), true)) {
            return "{$name} isn't sold for {$category->name} with this meal plan.";
        }

        if (($package['length_of_stay_linked'] ?? false) && ((($min = $package['min_nights'] ?? null) && $nights < $min) || (($max = $package['max_nights'] ?? null) && $nights > $max))) {
            return "{$name} is for stays of ".($package['min_nights'] ?? 1).($package['max_nights'] ? '–'.$package['max_nights'] : '+').' nights.';
        }

        if ((filled($package['booking_creation_from'] ?? null) && $today->toDateString() < $package['booking_creation_from'])
            || (filled($package['booking_creation_to'] ?? null) && $today->toDateString() > $package['booking_creation_to'])) {
            return "{$name} can't be booked today.";
        }

        if (($package['cut_off_days']['enabled'] ?? false) && $daysAhead < (int) ($package['cut_off_days']['value'] ?? 0)) {
            return "{$name} must be booked at least {$package['cut_off_days']['value']} days before arrival.";
        }

        if (($package['last_minute']['enabled'] ?? false) && $daysAhead > (int) ($package['last_minute']['value'] ?? 0)) {
            return "{$name} is only sold up to {$package['last_minute']['value']} days before arrival.";
        }

        if (($package['checkin_date_range']['enabled'] ?? false) && ! $this->within($arrival, $package['checkin_date_range'])) {
            return "{$name} isn't available for this check-in date.";
        }

        if (($package['checkout_date_range']['enabled'] ?? false) && ! $this->within($departure, $package['checkout_date_range'])) {
            return "{$name} isn't available for this check-out date.";
        }

        $calendar = $this->settings->json($property, 'rate_settings_packages_planner')['assignments'] ?? [];
        $days = ($package['stay_days_of_week']['enabled'] ?? false) ? (array) ($package['stay_days_of_week']['days'] ?? []) : null;

        for ($night = $arrival; $night->lt($departure); $night = $night->addDay()) {
            if (! in_array((int) $package['id'], array_map('intval', (array) ($calendar[$night->toDateString()] ?? [])), true)) {
                return "{$name} isn't on sale for the night of ".$night->format('d M Y').'.';
            }

            if ($days !== null && ! in_array(self::DAY_KEYS[(int) $night->format('w')], $days, true)) {
                return "{$name} isn't sold on ".$night->format('l').'s.';
            }
        }

        if (($package['usage_limit']['enabled'] ?? false) && $this->timesSold($property, (int) $package['id']) >= (int) ($package['usage_limit']['value'] ?? 0)) {
            return "{$name} is sold out.";
        }

        return null;
    }

    /**
     * The package's price for one room over the stay, from its rate grid (rounded), or null
     * when a night has no rate.
     *
     * @param  array<string, mixed>  $package
     * @return array{id: int, name: string, color: string|null, inclusions: string|null, terms: string|null, pay_at_hotel: bool, rate_per_night: float, amount: float, nights: int}|null
     */
    public function price(Property $property, array $package, RoomCategory $category, string $mealPlan, int $adults, string $checkIn, string $checkOut, string $channel = 'direct'): ?array
    {
        $arrival = CarbonImmutable::parse($checkIn)->startOfDay();
        $departure = CarbonImmutable::parse($checkOut)->startOfDay();
        $nights = max(1, (int) $arrival->diffInDays($departure));
        $rowKey = "{$category->id}:{$mealPlan}:{$this->rates->occupancyLabel($category, $adults)}";
        $grid = $package['rates'] ?? [];
        $amount = 0.0;

        for ($night = $arrival; $night->lt($departure); $night = $night->addDay()) {
            $day = self::DAY_KEYS[(int) $night->format('w')];
            $rate = $grid[$channel][$rowKey][$day] ?? $grid['direct'][$rowKey][$day] ?? null;

            if (! is_numeric($rate) || (float) $rate <= 0) {
                return null;
            }

            $amount += (float) $this->rounding->apply($property, (float) $rate, $channel);
        }

        return [
            'id' => (int) $package['id'],
            'name' => (string) ($package['name'] ?? 'Package'),
            'color' => $package['color'] ?? null,
            'inclusions' => ($package['override_inclusions'] ?? false) ? ($package['inclusions'] ?? null) : null,
            'terms' => ($package['override_terms'] ?? false) ? ($package['terms'] ?? null) : null,
            'pay_at_hotel' => (bool) ($package['pay_at_hotel'] ?? true),
            'rate_per_night' => round($amount / $nights, 2),
            'amount' => round($amount, 2),
            'nights' => $nights,
        ];
    }

    /**
     * One night's package rate for a channel (the channel manager pushes it), or null.
     */
    public function nightlyRate(Property $property, int $packageId, RoomCategory $category, string $mealPlan, int $adults, CarbonImmutable $night, string $channel = 'ota'): ?float
    {
        $package = $this->find($property, $packageId);
        $calendar = $this->settings->json($property, 'rate_settings_packages_planner')['assignments'] ?? [];

        if ($package === null || ! in_array($packageId, array_map('intval', (array) ($calendar[$night->toDateString()] ?? [])), true)) {
            return null;
        }

        return $this->price($property, $package, $category, $mealPlan, $adults, $night->toDateString(), $night->addDay()->toDateString(), $channel)['amount'] ?? null;
    }

    /**
     * Rooms already sold under the package (cancelled bookings don't count).
     */
    public function timesSold(Property $property, int $packageId): int
    {
        return SubBooking::query()
            ->where('package_id', $packageId)
            ->whereHas('booking', fn ($query) => $query->where('property_id', $property->id)->whereNotIn('status', [BookingStatus::Cancelled, ...BookingStatus::enquiryStatuses()]))
            ->count();
    }

    /**
     * @param  array{start?: string|null, end?: string|null}  $range
     */
    private function within(CarbonImmutable $date, array $range): bool
    {
        return (blank($range['start'] ?? null) || $date->toDateString() >= $range['start'])
            && (blank($range['end'] ?? null) || $date->toDateString() <= $range['end']);
    }
}
