<?php

namespace App\Services;

use App\Classes\PaymentGateway\Cashfree;
use App\Classes\PaymentGateway\PaymentGatewayInterface;
use App\Classes\PaymentGateway\PaymentGatewayManager;
use App\Classes\PaymentGateway\Razorpay;
use App\Enums\BookingStatus;
use App\Enums\PaymentMode;
use App\Http\Controllers\Concerns\CalculatesRoomTaxes;
use App\Models\Addon;
use App\Models\Booking;
use App\Models\FormField;
use App\Models\Guest;
use App\Models\GuestConsent;
use App\Models\Payment;
use App\Models\Property;
use App\Models\PropertyCancellationRule;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomCategory;
use App\Models\SubBooking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingEngineService
{
    use CalculatesRoomTaxes;

    public function __construct(private RoomInventory $roomInventory) {}

    /**
     * @var array<int, string>
     */
    private const DAY_KEY_BY_ISO_WEEKDAY = [0 => 'sun', 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat'];

    /**
     * Prepare data required for rendering the public booking engine page.
     * Returns null if the booking engine is disabled for this property.
     *
     * @param  array{check_in?: string|null, check_out?: string|null, rooms?: array<int, array<string, mixed>>|null}  $searchParams
     * @return array<string, mixed>|null
     */
    public function getWebPageData(Property $property, array $searchParams): ?array
    {
        $bookingEngineSettings = $this->metaFieldData($property, 'booking_engine_settings') ?? [];

        if (! ($bookingEngineSettings['enable_booking_engine'] ?? true)) {
            return null;
        }

        $checkIn = CarbonImmutable::parse($searchParams['check_in'] ?? now()->addDay()->toDateString());
        $checkOut = CarbonImmutable::parse($searchParams['check_out'] ?? $checkIn->addDay()->toDateString());

        $rooms = collect($searchParams['rooms'] ?? [['room_category_id' => null, 'adults' => 1, 'children' => 0, 'infants' => 0]])
            ->map(fn (array $room) => [
                'room_category_id' => $room['room_category_id'] ?? null,
                'adults' => $room['adults'] ?? 1,
                'children' => $room['children'] ?? 0,
                'infants' => $room['infants'] ?? 0,
            ])
            ->values();

        $mealPlanCatalog = collect(FormField::options('meal_plans'))->keyBy('constant_name');

        $roomCategories = RoomCategory::query()
            ->where('property_id', $property->id)
            ->with(['images', 'bedTypes', 'amenities.amenityCategory', 'rooms:id,room_category_id'])
            ->orderBy('display_order')
            ->get();

        $availabilityByCategory = $this->roomInventory->availabilityByCategory(
            $property->id,
            $this->roomInventory->capacityByCategory($property->id),
            $checkIn->toDateString(),
            $checkOut->toDateString(),
        );

        $taxComponents = $this->activeRoomTaxComponents($property);
        $restrictions = app(StayRestrictions::class);
        $packages = app(RatePackages::class);
        $allowZeroRate = $this->allowsZeroRateBookings($property);

        $roomCategoryRows = $roomCategories->map(function (RoomCategory $category) use ($property, $checkIn, $checkOut, $mealPlanCatalog, $taxComponents, $availabilityByCategory, $restrictions, $packages, $allowZeroRate): array {
            // Rate Settings → Restrictions: a stay they rule out isn't offered.
            $restriction = $restrictions->violation($property->id, $category->id, $checkIn->toDateString(), $checkOut->toDateString());
            $available = $restriction === null ? (int) ($availabilityByCategory[$category->id] ?? 0) : 0;

            $idealChildren = $category->default_number_of_children;
            $idealAdults = max(1, $category->ideal_occupancy - $idealChildren);

            $mealPlans = ! empty($category->meal_plans) ? $category->meal_plans : ['EP'];

            // A meal plan with no rate for these dates (0.00) is only sold when zero-rate bookings are allowed.
            $mealPlanOptions = collect($mealPlans)->map(function (string $mealPlanConstant) use ($property, $category, $idealAdults, $checkIn, $checkOut, $mealPlanCatalog, $taxComponents, $allowZeroRate): array {
                $rackRate = $this->averageNightlyRate($property, $category, $mealPlanConstant, $idealAdults, $checkIn, $checkOut);
                $nightlyPrice = round($rackRate + $this->taxForAmount($taxComponents, $rackRate, $rackRate), 2);
                $displayName = $mealPlanCatalog[$mealPlanConstant]['display_name'] ?? $mealPlanConstant;

                return [
                    'plan' => $mealPlanConstant,
                    'label' => $displayName,
                    'rack_rate' => $rackRate,
                    'nightly_price' => $nightlyPrice,
                    'has_rate' => $rackRate > 0 || $allowZeroRate,
                ];
            })->filter(fn (array $option): bool => $option['has_rate'])->values()->all();

            $firstOption = $mealPlanOptions[0] ?? null;

            // Packages sold for this category on these dates (Rate Settings → Package), priced like the meal plans.
            $packageOptions = collect($mealPlans)->flatMap(fn (string $mealPlanConstant): array => array_map(fn (array $offer): array => [
                'package_id' => $offer['id'],
                'name' => $offer['name'],
                'color' => $offer['color'],
                'inclusions' => $offer['inclusions'],
                'terms' => $offer['terms'],
                'plan' => $mealPlanConstant,
                'label' => $mealPlanCatalog[$mealPlanConstant]['display_name'] ?? $mealPlanConstant,
                'rack_rate' => $offer['rate_per_night'],
                'nightly_price' => round($offer['rate_per_night'] + $this->taxForAmount($taxComponents, $offer['rate_per_night'], $offer['rate_per_night']), 2),
            ], $packages->offers($property, $category, $mealPlanConstant, $idealAdults, $checkIn->toDateString(), $checkOut->toDateString(), bookingEngine: true)))->values()->all();

            // Nothing priced to sell: the category shows as unavailable.
            $noRate = $mealPlanOptions === [] && $packageOptions === [];

            return [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'inclusion_details' => $category->inclusion_details,
                'max_adults' => $category->max_adults,
                'max_children' => $category->max_children,
                'ideal_adults' => $idealAdults,
                'ideal_children' => $idealChildren,
                'available' => $noRate ? 0 : $available,
                'no_rate' => $noRate,
                'restriction' => $restriction,
                'images' => $category->images->map(fn ($image) => ['id' => $image->id, 'url' => $image->url])->values(),
                'bed_types' => $category->bedTypes->map(fn ($bedType) => ['name' => $bedType->name, 'quantity' => $bedType->quantity])->values(),
                'amenities' => $category->amenities->pluck('name')->values(),
                'categorized_amenities' => $category->amenities
                    ->sortBy(fn ($amenity) => $amenity->amenityCategory->id ?? 9999)
                    ->groupBy(fn ($amenity) => $amenity->amenityCategory->name ?? 'Other')
                    ->map(fn ($items, $catName) => [
                        'category' => $catName,
                        'amenities' => $items->pluck('name')->values()->all(),
                    ])
                    ->values()
                    ->all(),
                'meal_plan' => $firstOption['plan'] ?? null,
                'meal_plan_label' => $firstOption['label'] ?? null,
                'nightly_price' => $firstOption['nightly_price'] ?? 0.0,
                'meal_plan_options' => $mealPlanOptions,
                'package_options' => $packageOptions,
            ];
        })->values();

        $cancellationPolicy = $this->cancellationPolicyLines($property, $checkIn);
        $cancellationRuleSummary = $this->cancellationRuleSummary($property, $checkIn);
        // Public codes only: a secret code is checked on the server when the guest types it.
        $promos = app(BookingEnginePromos::class);
        $dealsPromotion = [
            'auto_apply_public_promo' => $promos->autoApplyPublic($property),
            'public_promo_codes' => $promos->publicOffers($property),
        ];

        $settings = app(PropertySettings::class);
        $termsData = $settings->raw($property, 'terms_and_conditions_while_booking');
        $termsAndConditions = $settings->enabled($property, 'terms_and_conditions_while_booking') || $termsData ? $termsData : null;

        return [
            'property' => [
                'ulid' => $property->ulid,
                'name' => $property->name,
                'contact_number' => $property->property_contact_number,
                'branding' => app(PropertyBranding::class)->forDisplay($property),
                'currency' => $property->currency->code ?? 'INR',
                'address' => collect([
                    $property->address_line_1,
                    $property->address_line_2,
                    $property->city?->name,
                    $property->state?->name,
                    $property->country?->name,
                    $property->postal_code,
                ])->filter()->implode(', '),
            ],
            'search' => [
                'check_in' => $checkIn->toDateString(),
                'check_out' => $checkOut->toDateString(),
                'nights' => max(1, $checkIn->diffInDays($checkOut)),
                'rooms' => $rooms,
            ],
            'roomCategories' => $roomCategoryRows,
            'cancellationPolicy' => $cancellationPolicy,
            'cancellationRuleSummary' => $cancellationRuleSummary,
            'termsAndConditions' => $termsAndConditions,
            // Settings → Privacy & Terms: accepted at checkout, marketing asked separately.
            'legal' => [
                'privacy_url' => route('legal.privacy', $property),
                'terms_url' => route('legal.terms', $property),
                'marketing_opt_in' => app(LegalSettings::class)->get($property)['marketing_opt_in'],
            ],
            'taxComponents' => $taxComponents,
            'dealsPromotion' => $dealsPromotion,
            'bookingEngineSettings' => [
                'allow_partial_payment' => (bool) ($bookingEngineSettings['allow_partial_payment'] ?? false),
                'partial_payment_percentage' => isset($bookingEngineSettings['partial_payment_percentage']) ? (float) $bookingEngineSettings['partial_payment_percentage'] : 50.0,
                'allowed_payment_gateways' => $bookingEngineSettings['allowed_payment_gateways'] ?? [],
                'accepted_card_types' => $bookingEngineSettings['accepted_card_types'] ?? [],
            ],
            'testGatewayEnabled' => (bool) config('services.test_gateway.enabled', false),
            // Add-on services the guest can add at checkout (Property → Add-ons, shown on the booking engine).
            'addons' => app(BookingAddons::class)->catalogue($property, bookingEngine: true)->map(fn (Addon $addon): array => $addon->toOption())->values()->all(),
        ];
    }

    /**
     * Compute calendar availability and lowest rack rate per night across all available rooms.
     *
     * @return array<string, mixed>
     */
    public function getCalendarData(Property $property, string $fromString, string $toString): array
    {
        $from = CarbonImmutable::parse($fromString)->startOfDay();
        $to = CarbonImmutable::parse($toString)->startOfDay();

        abort_if($from->diffInDays($to) > 120, 422, 'Date range cannot exceed 120 days.');

        $plannerData = $this->metaFieldData($property, 'rate_settings_rate_planner');
        $plans = collect($this->metaFieldArray($property, 'rate_settings_rate_plan', 'plans'))->keyBy('id');
        $defaultPlan = $plans->firstWhere('mapping_status', 'default') ?? $plans->firstWhere('active', true);
        $assignments = $plannerData['assignments'] ?? [];

        $roomCategories = RoomCategory::query()
            ->where('property_id', $property->id)
            ->get(['id', 'meal_plans']);
        // Rooms, or beds for a dormitory (a blocked dorm takes all its beds).
        $capacity = $this->roomInventory->capacityByCategory($property->id);
        $bedsByRoom = $this->roomInventory->activeBedsByRoom(null, $property->id);

        $bookedRanges = $this->roomInventory
            ->occupiedStays($property->id, $from->toDateString(), $to->toDateString())
            ->get(['sub_bookings.room_category_id', 'sub_bookings.check_in_date', 'sub_bookings.check_out_date']);

        $blockedRanges = RoomBlock::query()
            ->where('room_blocks.property_id', $property->id)
            ->overlapping($from->toDateString(), $to->toDateString())
            ->join('rooms', 'rooms.id', '=', 'room_blocks.room_id')
            ->get(['rooms.room_category_id', 'room_blocks.room_id', 'room_blocks.start_date', 'room_blocks.end_date']);

        $days = [];
        $restricted = app(StayRestrictions::class)->effective($property->id, array_values($roomCategories->map(fn (RoomCategory $category): int => $category->id)->all()), $from, $to);

        for ($date = $from; $date->lt($to); $date = $date->addDay()) {
            $dayKey = self::DAY_KEY_BY_ISO_WEEKDAY[(int) $date->format('w')];
            $planId = $assignments[$date->format('Y-m-d')] ?? $defaultPlan['id'] ?? null;
            $nextDate = $date->addDay();

            $cheapest = null;

            foreach ($roomCategories as $category) {
                $mealPlan = $category->meal_plans[0] ?? null;

                $categoryCapacity = (int) ($capacity[$category->id] ?? 0);

                if (! $mealPlan || $planId === null || $categoryCapacity === 0 || ($restricted[$date->toDateString()][$category->id]['stop_sell'] ?? false)) {
                    continue;
                }

                $bookedCount = $bookedRanges
                    ->where('room_category_id', $category->id)
                    ->filter(fn (SubBooking $stay) => $stay->check_in_date->lt($nextDate) && $stay->check_out_date->gt($date))
                    ->count();

                $blockedCount = $blockedRanges
                    ->where('room_category_id', $category->id)
                    ->filter(fn (RoomBlock $block) => $block->start_date->lt($nextDate) && $block->end_date->gt($date))
                    ->sum(fn (RoomBlock $block): int => $bedsByRoom[$block->room_id] ?? 1);

                if ($bookedCount + $blockedCount >= $categoryCapacity) {
                    continue;
                }

                $rowKey = "{$category->id}:{$mealPlan}:Single";
                $rate = $plans->get($planId)['rates']['direct'][$rowKey][$dayKey] ?? null;
                $rate = is_numeric($rate) ? app(RateRounding::class)->apply($property, (float) $rate) : null;

                if ($rate !== null && ($cheapest === null || $rate < $cheapest)) {
                    $cheapest = $rate;
                }
            }

            $days[] = [
                'date' => $date->toDateString(),
                'available' => $cheapest !== null,
                'price' => $cheapest,
            ];
        }

        return ['days' => $days];
    }

    /**
     * The booking engine's price for a stay, worked out here from Rate Setup, the promo
     * code, the tax rules, the add-on catalogue and the partial-payment setting. Nothing
     * the page sends is a price: only what was picked (rooms, meal plans, guests, add-ons,
     * code, full or partial payment).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException when the stay can't be booked as asked
     */
    public function quote(Property $property, array $data): array
    {
        $settings = $this->metaFieldData($property, 'booking_engine_settings') ?? [];

        if (! ($settings['enable_booking_engine'] ?? true)) {
            throw ValidationException::withMessages(['rooms' => 'Online booking is closed for this property.']);
        }

        $checkIn = CarbonImmutable::parse((string) $data['check_in'])->startOfDay();
        $checkOut = CarbonImmutable::parse((string) $data['check_out'])->startOfDay();
        $nights = BookingPricing::nights($checkIn->toDateString(), $checkOut->toDateString());

        $requestedRooms = array_values($data['rooms']);

        $categories = RoomCategory::query()
            ->where('property_id', $property->id)
            ->whereIn('id', collect($requestedRooms)->pluck('category_id')->filter()->map(fn ($id): int => (int) $id)->all())
            ->get()
            ->keyBy('id');
        $available = $this->roomInventory->availabilityByCategory(
            $property->id,
            $this->roomInventory->capacityByCategory($property->id),
            $checkIn->toDateString(),
            $checkOut->toDateString(),
        );

        $rooms = [];
        $picked = [];
        $allowZeroRate = $this->allowsZeroRateBookings($property);

        foreach ($requestedRooms as $index => $room) {
            $category = $categories->get((int) ($room['category_id'] ?? 0));

            if ($category === null) {
                throw ValidationException::withMessages(["rooms.{$index}.category_id" => 'This room is not available.']);
            }

            $mealPlans = ! empty($category->meal_plans) ? $category->meal_plans : ['EP'];
            $mealPlan = (string) ($room['meal_plan'] ?? $mealPlans[0]);
            $adults = max(1, (int) ($room['adults'] ?? 1));
            $children = max(0, (int) ($room['children'] ?? 0));

            if (! in_array($mealPlan, $mealPlans, true)) {
                throw ValidationException::withMessages(["rooms.{$index}.meal_plan" => "{$category->name} is not sold with this meal plan."]);
            }

            if ($adults > max(1, (int) $category->max_adults) || $children > (int) $category->max_children) {
                throw ValidationException::withMessages(["rooms.{$index}.adults" => "Too many guests for {$category->name}."]);
            }

            if (($restriction = app(StayRestrictions::class)->violation($property->id, $category->id, $checkIn->toDateString(), $checkOut->toDateString())) !== null) {
                throw ValidationException::withMessages(["rooms.{$index}.category_id" => "{$category->name}: {$restriction}"]);
            }

            $picked[$category->id] = ($picked[$category->id] ?? 0) + 1;

            if ($picked[$category->id] > (int) ($available[$category->id] ?? 0)) {
                throw ValidationException::withMessages(["rooms.{$index}.category_id" => "Not enough {$category->name} rooms are free for these dates."]);
            }

            $stayAmount = 0.0;
            $package = null;

            if (filled($room['package_id'] ?? null)) {
                // A package: its own rates and conditions, checked here whatever the page showed.
                $packages = app(RatePackages::class);
                $definition = $packages->find($property, (int) $room['package_id']);
                $rejection = $definition === null || ! ($definition['show_at_booking_engine'] ?? true)
                    ? 'This package is not available.'
                    : $packages->rejection($property, $definition, $category, $mealPlan, $checkIn->toDateString(), $checkOut->toDateString());
                $package = $rejection === null ? $packages->price($property, $definition, $category, $mealPlan, $adults, $checkIn->toDateString(), $checkOut->toDateString()) : null;

                if ($package === null || ((float) $package['amount'] <= 0 && ! $allowZeroRate)) {
                    throw ValidationException::withMessages(["rooms.{$index}.package_id" => $rejection ?? "{$definition['name']} has no rate for these dates."]);
                }

                $stayAmount = $package['amount'];
            } else {
                for ($night = $checkIn; $night->lt($checkOut); $night = $night->addDay()) {
                    $rate = $this->nightlyRate($property, $category, $mealPlan, $adults, $night);

                    // No rate, or a zero rate while zero-rate bookings are off: not for sale online.
                    if ($rate === null || ($rate <= 0 && ! $allowZeroRate)) {
                        throw ValidationException::withMessages(["rooms.{$index}.category_id" => "{$category->name} has no rate for ".$night->format('d M Y').'.']);
                    }

                    $stayAmount += $rate;
                }
            }

            $ratePerNight = round($stayAmount / $nights, 2);

            $rooms[] = [
                'category_id' => $category->id,
                'category_name' => $category->name,
                'meal_plan' => $mealPlan,
                'package_id' => $package['id'] ?? null,
                'package_name' => $package['name'] ?? null,
                'adults' => $adults,
                'children' => $children,
                'infants' => max(0, (int) ($room['infants'] ?? 0)),
                'rate_per_night' => $ratePerNight,
                'nights' => $nights,
                'amount' => round($ratePerNight * $nights, 2),
            ];
        }

        $roomTotal = round(collect($rooms)->sum('amount'), 2);
        $partial = ($data['payment_type'] ?? 'full') === 'partial';

        if ($partial && ! ($settings['allow_partial_payment'] ?? false)) {
            throw ValidationException::withMessages(['payment_type' => 'Part payment is not available.']);
        }

        // The promo code, checked against this stay and priced on these room charges.
        $promoCode = null;
        $discount = 0.0;

        if (filled($data['promo_code'] ?? null)) {
            $promos = app(BookingEnginePromos::class);
            $promo = $promos->find($property, (string) $data['promo_code']);
            $rejection = $promo === null
                ? 'Invalid promo code.'
                : $promos->rejection($property, $promo, $checkIn->toDateString(), $checkOut->toDateString(), $roomTotal, count($rooms), $partial);

            if ($rejection !== null) {
                throw ValidationException::withMessages(['promo_code' => $rejection]);
            }

            $promoCode = (string) $promo['code'];
            $discount = $promos->discount($promo, $roomTotal);
        }

        $pricing = app(BookingPricing::class)->totals($property, $rooms, $discount);

        // Add-ons from the catalogue shown on the booking engine, priced for the stay.
        $addonService = app(BookingAddons::class);
        $catalogue = $addonService->catalogue($property, bookingEngine: true)->keyBy('id');
        $guests = (int) collect($rooms)->sum(fn (array $room): int => $room['adults'] + $room['children']);
        $addons = [];

        foreach (array_values($data['addons'] ?? []) as $index => $line) {
            $addon = $catalogue->get((int) ($line['addon_id'] ?? 0));

            if ($addon === null) {
                throw ValidationException::withMessages(["addons.{$index}.addon_id" => 'This add-on is not available.']);
            }

            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $addons[$addon->id] = ['addon_id' => $addon->id, 'name' => $addon->name, 'quantity' => $quantity, 'total' => $addonService->price($addon, $quantity, $nights, $guests)['total']];
        }

        $addonsTotal = round(collect($addons)->sum('total'), 2);
        $grandTotal = round($pricing['total'] + $addonsTotal, 2);
        $payNow = $partial
            ? round($grandTotal * (float) ($settings['partial_payment_percentage'] ?? 50) / 100, 2)
            : $grandTotal;

        return [
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'nights' => $nights,
            'rooms' => $rooms,
            'room_total' => $pricing['room_total'],
            'promo_code' => $promoCode,
            'discount' => $pricing['discount'],
            'tax_lines' => $pricing['tax_lines'],
            'taxes' => $pricing['taxes'],
            'total' => $pricing['total'],
            'addons' => array_values($addons),
            'addons_total' => $addonsTotal,
            'grand_total' => $grandTotal,
            'payment_type' => $partial ? 'partial' : 'full',
            'pay_now' => $payNow,
            'pay_later' => round($grandTotal - $payNow, 2),
        ];
    }

    /**
     * Prices the stay and opens a gateway order for the amount due now. The quote waits on
     * the server (by booking reference) for the payment to be confirmed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function initiateCheckout(Property $property, array $data): array
    {
        $quote = $this->quote($property, $data);
        $gatewayName = $data['payment_gateway'];
        $bookingRefId = 'BK-'.strtoupper(Str::random(8));

        $order = $this->resolveGatewayForProperty($property, $gatewayName)->createOrder(
            null,
            $quote['pay_now'],
            $property->currency?->code ?: 'INR',
            [
                'booking_ref_id' => $bookingRefId,
                'property_id' => $property->id,
                'guest' => $data['guest'] ?? [],
            ]
        );

        if (! ($order['success'] ?? true)) {
            return $order;
        }

        // A gateway without keys only simulates payment: never take a real booking through it.
        if (($order['is_test_mode'] ?? false) && $gatewayName !== 'test' && ! config('services.test_gateway.enabled')) {
            return ['success' => false, 'message' => 'Online payment is not set up for this property yet.'];
        }

        Cache::put(self::checkoutCacheKey($property, $bookingRefId), [
            'gateway' => $gatewayName,
            'order_id' => $order['order_id'] ?? null,
            'is_test_mode' => (bool) ($order['is_test_mode'] ?? false),
            'guest' => $data['guest'],
            'consent' => $data['consent'] ?? null,
            'quote' => $quote,
        ], now()->addHours(3));

        return [...$order, 'booking_ref_id' => $bookingRefId, 'quote' => $quote];
    }

    /**
     * Verifies the payment of a checkout this server priced, and books it exactly as quoted.
     * A second confirmation of the same booking returns it again.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException when the checkout is unknown or the payment isn't verified
     */
    public function confirmCheckout(Property $property, array $data): array
    {
        $cacheKey = self::checkoutCacheKey($property, (string) $data['booking_ref_id']);

        return Cache::lock($cacheKey.':lock', 30)->block(10, function () use ($property, $data, $cacheKey): array {
            $existing = Booking::query()
                ->where('property_id', $property->id)
                ->where('booking_ref_id', $data['booking_ref_id'])
                ->where('source', 'booking_engine')
                ->first();

            if ($existing !== null) {
                return $this->confirmedBookingSummary($existing, (string) $data['payment_gateway']);
            }

            $checkout = Cache::get($cacheKey);

            if ($checkout === null || $checkout['gateway'] !== $data['payment_gateway']
                || ($checkout['order_id'] !== null && ($data['order_id'] ?? null) !== $checkout['order_id'])) {
                throw ValidationException::withMessages(['booking_ref_id' => 'This checkout has expired or does not match the payment. Please contact the property.']);
            }

            // A live Razorpay payment is only proven by its signature.
            if ($checkout['gateway'] === 'razorpay' && ! $checkout['is_test_mode'] && blank($data['signature'] ?? null)) {
                throw ValidationException::withMessages(['signature' => 'The payment could not be verified.']);
            }

            if (app()->isProduction() && $checkout['is_test_mode']) {
                throw ValidationException::withMessages(['payment_gateway' => 'Test payments are not allowed in production.']);
            }

            $verification = $this->resolveGatewayForProperty($property, $checkout['gateway'])
                ->verifyPayment([...$data, 'order_id' => $checkout['order_id']]);

            if (! ($verification['success'] ?? false)) {
                throw ValidationException::withMessages(['payment_gateway' => $verification['message'] ?? 'The payment could not be verified.']);
            }

            // When the gateway reports what was paid, it must cover what this server asked for.
            if (is_numeric($verification['amount'] ?? null) && (float) $verification['amount'] < (float) $checkout['quote']['pay_now'] - 0.05) {
                throw ValidationException::withMessages(['payment_gateway' => 'The paid amount does not match the booking total.']);
            }

            $booking = $this->bookQuote($property, (string) $data['booking_ref_id'], $checkout, [...$verification, 'transaction_id' => $verification['transaction_id'] ?? $data['transaction_id'] ?? null]);
            Cache::forget($cacheKey);

            return $this->confirmedBookingSummary($booking, $checkout['gateway']);
        });
    }

    /**
     * Books a paid checkout exactly as it was quoted.
     *
     * @param  array{gateway: string, guest: array<string, mixed>, consent: array{marketing: bool, ip: string|null, user_agent: string|null}|null, quote: array<string, mixed>}  $checkout
     * @param  array<string, mixed>  $verification  the gateway's verifyPayment() result
     */
    private function bookQuote(Property $property, string $bookingRefId, array $checkout, array $verification): Booking
    {
        $transactionId = $verification['transaction_id'] ?? null;

        $quote = $checkout['quote'];
        $guestData = $checkout['guest'];

        return DB::transaction(function () use ($property, $bookingRefId, $checkout, $quote, $guestData, $transactionId, $verification): Booking {
            // The booking total stays room-only; add-ons are kept in addons_total.
            $booking = Booking::create([
                'property_id' => $property->id,
                'booking_ref_id' => $bookingRefId,
                'source' => 'booking_engine',
                'type' => 'online',
                'booking_type' => 'online',
                'check_in' => $quote['check_in'],
                'check_out' => $quote['check_out'],
                'same_plan_for_all_rooms' => true,
                'pay_at_hotel' => $quote['pay_later'] > 0,
                'status' => BookingStatus::Confirmed,
                'room_total' => $quote['room_total'],
                'discount' => $quote['discount'],
                'promo_code' => $quote['promo_code'],
                'taxes' => $quote['taxes'],
                'total' => $quote['total'],
                'outstanding' => $quote['pay_later'],
                'special_instructions' => $guestData['special_requests'] ?? null,
            ]);

            $nameParts = explode(' ', trim((string) $guestData['name']), 2);

            $guest = Guest::firstOrCreate(
                ['property_id' => $property->id, 'phone' => $guestData['phone']],
                ['first_name' => $nameParts[0], 'last_name' => $nameParts[1] ?? '', 'emails' => [$guestData['email']]],
            );

            $booking->guests()->syncWithoutDetaching([$guest->id]);

            // What the guest agreed to at checkout, as evidence (marketing only when asked).
            if (($consent = $checkout['consent'] ?? null) !== null) {
                app(GuestPrivacy::class)->recordConsents($property, $guest, $booking, [
                    GuestConsent::TERMS => true,
                    GuestConsent::PRIVACY => true,
                    ...(app(LegalSettings::class)->get($property)['marketing_opt_in'] ? [GuestConsent::MARKETING => (bool) $consent['marketing']] : []),
                ], 'booking_engine', $consent['ip'] ?? null, $consent['user_agent'] ?? null);
            }

            // Payment is already verified here, so never fail: take a free room, or leave it unassigned.
            $takenRoomIds = $this->roomInventory->unavailableRoomIds($property->id, $quote['check_in'], $quote['check_out']);
            $freeBeds = $this->roomInventory->freeBedsByRoom($property->id, $quote['check_in'], $quote['check_out']);

            foreach ($quote['rooms'] as $room) {
                $freeRoomId = $this->roomInventory->firstFreeRoomId($room['category_id'], $takenRoomIds, $freeBeds);

                if ($freeRoomId !== null) {
                    $takenRoomIds = $takenRoomIds->push($freeRoomId);
                }

                $booking->rooms()->create([
                    'room_category_id' => $room['category_id'],
                    'room_id' => $freeRoomId,
                    'meal_plan' => $room['meal_plan'],
                    'package_id' => $room['package_id'] ?? null,
                    'package_name' => $room['package_name'] ?? null,
                    'adults' => $room['adults'],
                    'children' => $room['children'],
                    'infants' => $room['infants'],
                    'rate_per_night' => $room['rate_per_night'],
                ]);
            }

            if (! empty($quote['addons'])) {
                app(BookingAddons::class)->sync($booking, $quote['addons'], 'booking_engine', bookingEngine: true);
            }

            $transactionId ??= 'TXN_'.strtoupper($checkout['gateway']).'_'.Str::random(8);

            Payment::logForBooking($booking, [
                'payment_mode' => PaymentMode::Online,
                'pg_txn_id' => $transactionId,
                'payment_ref_id' => $transactionId,
                'guest_name' => $guestData['name'],
                'pay_now' => $quote['pay_now'],
                'booking_source' => 'Booking Engine',
                'collected_by_staff' => 'System',
                'pg_commission_amount' => app(GatewayCommissions::class)->for($property, $checkout['gateway'], $quote['pay_now'], $verification),
            ]);

            return $booking;
        });
    }

    /**
     * What the guest's confirmation screen shows.
     *
     * @return array<string, mixed>
     */
    private function confirmedBookingSummary(Booking $booking, string $gateway): array
    {
        // The guest was attached after the booking was created, so the relation is re-read, not reused.
        $booking->load('guests');
        $guest = $booking->guests->first();
        $payment = Payment::query()->where('booking_id', $booking->id)->where('type', 'receipt')->oldest('id')->first();

        return [
            'id' => $booking->id,
            'booking_ref_id' => $booking->booking_ref_id,
            'status' => $booking->status->value,
            'check_in' => $booking->check_in->format('d M Y'),
            'check_out' => $booking->check_out->format('d M Y'),
            'guest_name' => $guest ? trim("{$guest->first_name} {$guest->last_name}") : null,
            'guest_email' => $guest?->emails[0] ?? null,
            'guest_phone' => $guest?->phone,
            'total' => round((float) $booking->total + (float) $booking->addons_total, 2),
            'paid_now' => round((float) ($payment->total_txn_amount ?? 0), 2),
            'outstanding' => (float) $booking->outstanding,
            'gateway' => $gateway,
            'transaction_id' => $payment?->pg_txn_id,
            // "Check in online" on the confirmation page, when the property offers web check-in.
            'web_checkin_url' => $this->webCheckinUrl($booking),
        ];
    }

    /**
     * The guest's web check-in link, when the property has web check-in on.
     */
    private function webCheckinUrl(Booking $booking): ?string
    {
        $checkins = app(WebCheckins::class);

        return $checkins->isEnabled($booking->property) ? $checkins->url($checkins->for($booking)) : null;
    }

    private static function checkoutCacheKey(Property $property, string $bookingRefId): string
    {
        return "booking-engine:checkout:{$property->id}:{$bookingRefId}";
    }

    /**
     * Gateways a guest can pay through (id and name only — never keys or secrets): the
     * property's enabled gateways from Booking Engine settings, or Razorpay and Cashfree
     * when none are set, plus the test gateway when it is switched on.
     *
     * @return list<array{id: string, name: string}>
     */
    public function payableGateways(Property $property): array
    {
        $configured = collect($this->metaFieldArray($property, 'booking_engine_settings', 'allowed_payment_gateways'))
            ->filter(fn ($gateway): bool => is_array($gateway) && ($gateway['enabled'] ?? false) && PaymentGatewayManager::isAllowed((string) ($gateway['id'] ?? '')))
            ->map(fn (array $gateway): array => ['id' => (string) $gateway['id'], 'name' => (string) ($gateway['name'] ?? PaymentGatewayManager::resolve($gateway['id'])->getDisplayName())]);

        if ($configured->isEmpty()) {
            $configured = collect(['razorpay', 'cashfree'])->map(fn (string $id): array => ['id' => $id, 'name' => PaymentGatewayManager::resolve($id)->getDisplayName()]);
        }

        if (config('services.test_gateway.enabled') && ! $configured->contains('id', 'test')) {
            $configured->push(['id' => 'test', 'name' => 'Test Gateway']);
        }

        return array_values($configured->all());
    }

    /**
     * Resolve payment gateway instance, using property-configured credentials if set.
     */
    public function resolveGatewayForProperty(Property $property, string $gatewayName): PaymentGatewayInterface
    {
        $gatewayConfig = collect($this->metaFieldArray($property, 'booking_engine_settings', 'allowed_payment_gateways'))->firstWhere('id', $gatewayName);

        $key = ! empty($gatewayConfig['key']) ? (string) $gatewayConfig['key'] : null;
        $secret = ! empty($gatewayConfig['secret']) ? (string) $gatewayConfig['secret'] : null;

        $mode = ! empty($gatewayConfig['mode']) ? (string) $gatewayConfig['mode'] : (config('services.cashfree.mode', 'sandbox') ?: 'sandbox');

        if ($gatewayName === 'razorpay') {
            return new Razorpay($key, $secret);
        }

        if ($gatewayName === 'cashfree') {
            return new Cashfree($key, $secret, $mode);
        }

        return PaymentGatewayManager::resolve($gatewayName);
    }

    /**
     * Resolve the active cancellation rule for the property.
     */
    public function resolveCancellationRule(Property $property, ?CarbonImmutable $checkIn = null): ?PropertyCancellationRule
    {
        if ($checkIn) {
            $checkInDate = $checkIn->startOfDay();
            $rule = PropertyCancellationRule::query()
                ->where('property_id', $property->id)
                ->where('start_time_stamp', '<=', $checkInDate)
                ->where('end_time_stamp', '>=', $checkInDate)
                ->first();

            if ($rule) {
                return $rule;
            }
        }

        $now = now();
        $rule = PropertyCancellationRule::query()
            ->where('property_id', $property->id)
            ->where('start_time_stamp', '<=', $now)
            ->where('end_time_stamp', '>=', $now)
            ->first();

        if ($rule) {
            return $rule;
        }

        return PropertyCancellationRule::query()
            ->where('property_id', $property->id)
            ->latest('id')
            ->first();
    }

    /**
     * Plain-text cancellation tiers ("N+ days before check-in: P% penalty").
     *
     * @return array<int, string>
     */
    public function cancellationPolicyLines(Property $property, ?CarbonImmutable $checkIn = null): array
    {
        $rule = $this->resolveCancellationRule($property, $checkIn);

        if (! $rule || empty($rule->rules)) {
            return [];
        }

        $sortedAsc = collect($rule->rules)->sortBy('day_from_checkin')->values();
        $count = $sortedAsc->count();

        return collect($rule->rules)
            ->sortByDesc('day_from_checkin')
            ->map(function (array $tier) use ($sortedAsc, $count) {
                $days = (int) $tier['day_from_checkin'];
                $penalty = $tier['penalty_percent'];

                if ($days >= 999999) {
                    if ($count === 1) {
                        return "Any time before check-in: {$penalty}% penalty";
                    }
                    $prevDays = (int) ($sortedAsc[$count - 2]['day_from_checkin'] ?? 0);

                    return "More than {$prevDays} day(s) before check-in: {$penalty}% penalty";
                }

                return "{$days}+ day(s) before check-in: {$penalty}% penalty";
            })
            ->values()
            ->all();
    }

    /**
     * Human-readable cancellation & refund schedule summary.
     *
     * @return array<int, array{window: string, penalty: string, refund: string, description: string}>
     */
    public function cancellationRuleSummary(Property $property, ?CarbonImmutable $checkIn = null): array
    {
        $rule = $this->resolveCancellationRule($property, $checkIn);

        if (! $rule || empty($rule->rules)) {
            return [];
        }

        $tiers = collect($rule->rules)
            ->sortBy('day_from_checkin')
            ->values()
            ->all();

        $count = count($tiers);
        $summary = [];

        if ($count === 1) {
            $tier = $tiers[0];
            $penalty = (float) $tier['penalty_percent'];
            $refund = max(0, 100 - $penalty);
            $penaltyText = $penalty == 0 ? '0% (Free Cancellation)' : "{$penalty}%";
            $refundText = $refund == 100 ? '100% (Full Refund)' : ($refund == 0 ? '0% (No Refund)' : "{$refund}%");
            $desc = $penalty == 0
                ? 'Free cancellation at any time prior to check-in.'
                : ($penalty == 100
                    ? 'Non-refundable: 100% cancellation charge applies at any time.'
                    : "Cancellation at any time before check-in incurs a {$penalty}% cancellation fee.");

            return [[
                'window' => 'Any time prior to check-in',
                'penalty' => $penaltyText,
                'refund' => $refundText,
                'description' => $desc,
            ]];
        }

        for ($i = 0; $i < $count; $i++) {
            $tier = $tiers[$i];
            $penalty = (float) $tier['penalty_percent'];
            $refund = max(0, 100 - $penalty);
            $penaltyText = $penalty == 0 ? '0% (Free Cancellation)' : "{$penalty}%";
            $refundText = $refund == 100 ? '100% (Full Refund)' : ($refund == 0 ? '0% (No Refund)' : "{$refund}%");
            $days = (int) $tier['day_from_checkin'];

            if ($i === 0) {
                if ($days === 0) {
                    $window = 'On check-in day';
                    $desc = "Cancellation on the day of check-in: {$penaltyText} cancellation charge.";
                } else {
                    $window = "0 to {$days} day(s) before check-in";
                    $desc = "Cancellation within {$days} day(s) of check-in (0 to {$days} days): {$penaltyText} cancellation charge.";
                }
            } elseif ($i === $count - 1 && $days >= 999999) {
                $prevDays = (int) $tiers[$i - 1]['day_from_checkin'];
                $window = "More than {$prevDays} day(s) before check-in";
                $desc = $penalty == 0
                    ? "Cancellation more than {$prevDays} day(s) before check-in: Free cancellation (100% refund)."
                    : "Cancellation more than {$prevDays} day(s) before check-in: {$penaltyText} cancellation charge.";
            } else {
                $prevDays = (int) $tiers[$i - 1]['day_from_checkin'];
                $startDays = $prevDays + 1;
                if ($startDays === $days) {
                    $window = "{$days} day(s) before check-in";
                } else {
                    $window = "{$startDays} to {$days} day(s) before check-in";
                }
                $desc = "Cancellation between {$startDays} and {$days} day(s) before check-in: {$penaltyText} cancellation charge.";
            }

            $summary[] = [
                'window' => $window,
                'penalty' => $penaltyText,
                'refund' => $refundText,
                'description' => $desc,
            ];
        }

        return $summary;
    }

    /**
     * Average per-night rack rate across the stay.
     */
    public function averageNightlyRate(Property $property, RoomCategory $category, string $mealPlan, int $adults, CarbonImmutable $checkIn, CarbonImmutable $checkOut): float
    {
        $sum = 0.0;
        $count = 0;

        for ($date = $checkIn; $date->lt($checkOut); $date = $date->addDay()) {
            $rate = $this->nightlyRate($property, $category, $mealPlan, $adults, $date);

            if ($rate === null) {
                continue;
            }

            $sum += $rate;
            $count++;
        }

        return $count > 0 ? round($sum / $count, 2) : 0.0;
    }

    /**
     * One night's rate from Rate Setup: the plan the rate planner assigns to the date (or
     * the default plan), for the category × meal plan × occupancy row, on a rate channel
     * ("direct", "ota", …). A channel without its own rate falls back to direct. Null when
     * no rate is set.
     */
    public function nightlyRate(Property $property, RoomCategory $category, string $mealPlan, int $adults, CarbonImmutable $date, string $channel = 'direct'): ?float
    {
        $plans = collect($this->metaFieldArray($property, 'rate_settings_rate_plan', 'plans'))->keyBy('id');
        $assignments = $this->metaFieldData($property, 'rate_settings_rate_planner')['assignments'] ?? [];
        $defaultPlan = $plans->firstWhere('mapping_status', 'default') ?? $plans->firstWhere('active', true);
        $planId = $assignments[$date->format('Y-m-d')] ?? $defaultPlan['id'] ?? null;

        if ($planId === null) {
            return null;
        }

        $rowKey = "{$category->id}:{$mealPlan}:{$this->occupancyLabel($category, $adults)}";
        $dayKey = self::DAY_KEY_BY_ISO_WEEKDAY[(int) $date->format('w')];
        $rates = $plans->get($planId)['rates'] ?? [];
        $rate = $rates[$channel][$rowKey][$dayKey] ?? $rates['direct'][$rowKey][$dayKey] ?? null;

        // Rate Settings → Occupancy Pricing (how full the night already is), then → Rounding.
        $rate = app(OccupancyPricing::class)->apply($property, $category, $date, is_numeric($rate) ? (float) $rate : null, $channel);

        return app(RateRounding::class)->apply($property, $rate, $channel);
    }

    /**
     * Booking Settings → Reservation → "Allow Zero-Rate Bookings": whether a room with no
     * rate (0.00) may be sold.
     */
    public function allowsZeroRateBookings(Property $property): bool
    {
        return (bool) ($this->metaFieldData($property, 'booking_settings_reservation')['allow_zero_rate_bookings'] ?? false);
    }

    public function occupancyLabel(RoomCategory $category, int $adults): string
    {
        $tiers = array_slice(['Single', 'Double', 'Triple', 'Quad'], 0, min(4, max(1, $category->max_adults)));
        $index = min(max($adults, 1), count($tiers)) - 1;

        return $tiers[$index] ?? 'Single';
    }
}
