<?php

namespace App\Services\Channex;

use App\Models\ChannexConnection;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Services\RatePackages;
use App\Services\RoomInventory;
use Illuminate\Support\Str;

/**
 * Links a property to Channex: to an existing Channex property or a new one made from the
 * property's details, registers the booking webhook, and can create Channex room types and
 * rate plans from the room categories and their meal plans.
 */
class ChannexSetup
{
    public function __construct(private RoomInventory $inventory) {}

    public function connect(Property $property, ?string $channexPropertyId, ?string $apiKey = null): ChannexConnection
    {
        $client = new ChannexClient($apiKey);
        $channexPropertyId ??= $client->createProperty($this->propertyAttributes($property));

        $connection = ChannexConnection::query()->create([
            'property_id' => $property->id,
            'channex_property_id' => $channexPropertyId,
            'api_key' => $apiKey,
            'webhook_token' => Str::random(48),
        ]);

        $this->registerWebhook($connection);

        return $connection;
    }

    public function disconnect(ChannexConnection $connection): void
    {
        if ($connection->channex_webhook_id) {
            try {
                ChannexClient::for($connection)->deleteWebhook($connection->channex_webhook_id);
            } catch (ChannexException) {
                // Already gone in Channex; nothing to undo.
            }
        }

        $connection->delete();
    }

    /**
     * Channex calls the webhook on every booking change, so bookings arrive at once. It
     * needs a public https address: on a local site the scheduled pull fetches them instead.
     */
    public function registerWebhook(ChannexConnection $connection): void
    {
        $url = route('api.channex.webhook', ['token' => $connection->webhook_token]);

        if (! self::isPublicUrl($url) || $connection->channex_webhook_id) {
            return;
        }

        $connection->forceFill(['channex_webhook_id' => ChannexClient::for($connection)->createWebhook($connection->channex_property_id, $url)])->save();
    }

    public static function isPublicUrl(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return str_starts_with($url, 'https://')
            && ! in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)
            && ! Str::endsWith($host, ['.test', '.localhost', '.local']);
    }

    /**
     * Creates a Channex room type for every unmapped room category, and a rate plan for each
     * of its meal plans and for each package (Rate Settings → Package) sold with it, and maps
     * them. Returns how many of each were made.
     *
     * @return array{room_types: int, rate_plans: int}
     */
    public function createRoomsAndRates(ChannexConnection $connection): array
    {
        $client = ChannexClient::for($connection);
        $connection->loadMissing('property.currency');
        $capacity = $this->inventory->capacityByCategory($connection->property_id);
        $mapped = $connection->roomMappings()->pluck('channex_room_type_id', 'room_category_id');
        $currency = $connection->property->currency->code ?? 'INR';
        $made = ['room_types' => 0, 'rate_plans' => 0];

        $categories = RoomCategory::query()->where('property_id', $connection->property_id)->orderBy('display_order')->get();

        foreach ($categories as $category) {
            $roomTypeId = $mapped[$category->id] ?? null;
            $adults = max(1, (int) $category->max_adults);

            if ($roomTypeId === null) {
                $roomTypeId = $client->createRoomType([
                    'property_id' => $connection->channex_property_id,
                    'title' => $category->name,
                    'count_of_rooms' => max(1, (int) ($capacity[$category->id] ?? 1)),
                    'occ_adults' => $adults,
                    'occ_children' => (int) $category->max_children,
                    'occ_infants' => 0,
                    'default_occupancy' => min($adults, max(1, (int) ($category->base_occupancy ?: $adults))),
                ]);
                $connection->roomMappings()->create(['room_category_id' => $category->id, 'channex_room_type_id' => $roomTypeId]);
                $made['room_types']++;
            }

            $ratedPlans = $connection->rateMappings()->where('room_category_id', $category->id)->whereNull('package_id')->pluck('meal_plan')->all();

            foreach ($this->mealPlans($category) as $mealPlan) {
                if (in_array($mealPlan, $ratedPlans, true)) {
                    continue;
                }

                $ratePlanId = $client->createRatePlan([
                    'property_id' => $connection->channex_property_id,
                    'room_type_id' => $roomTypeId,
                    'title' => "{$category->name} {$mealPlan}",
                    'currency' => $currency,
                    'sell_mode' => 'per_room',
                    'rate_mode' => 'manual',
                    'options' => [['occupancy' => $adults, 'is_primary' => true, 'rate' => 0]],
                ]);
                $connection->rateMappings()->create([
                    'room_category_id' => $category->id,
                    'meal_plan' => $mealPlan,
                    'adults' => min($adults, 2),
                    'channex_rate_plan_id' => $ratePlanId,
                ]);
                $made['rate_plans']++;
            }

            // Packages sold for the category: one rate plan per package and meal plan.
            foreach (app(RatePackages::class)->all($connection->property) as $package) {
                foreach ((array) ($package['rooms'][(string) $category->id] ?? []) as $mealPlan) {
                    $mapped = $connection->rateMappings()->where('room_category_id', $category->id)->where('package_id', $package['id'])->where('meal_plan', $mealPlan)->exists();

                    if ($mapped) {
                        continue;
                    }

                    $ratePlanId = $client->createRatePlan([
                        'property_id' => $connection->channex_property_id,
                        'room_type_id' => $roomTypeId,
                        'title' => "{$category->name} {$package['name']} {$mealPlan}",
                        'currency' => $currency,
                        'sell_mode' => 'per_room',
                        'rate_mode' => 'manual',
                        'options' => [['occupancy' => $adults, 'is_primary' => true, 'rate' => 0]],
                    ]);
                    $connection->rateMappings()->create([
                        'room_category_id' => $category->id,
                        'meal_plan' => $mealPlan,
                        'adults' => min($adults, 2),
                        'package_id' => (int) $package['id'],
                        'channex_rate_plan_id' => $ratePlanId,
                    ]);
                    $made['rate_plans']++;
                }
            }
        }

        return $made;
    }

    /**
     * @return list<string>
     */
    public function mealPlans(RoomCategory $category): array
    {
        $plans = array_values(collect($category->meal_plans ?? [])
            ->map(fn ($plan): ?string => is_array($plan) ? ($plan['code'] ?? $plan['name'] ?? null) : $plan)
            ->filter()
            ->map(fn ($plan): string => (string) $plan)
            ->unique()
            ->all());

        return $plans === [] ? ['EP'] : $plans;
    }

    /**
     * @return array<string, mixed>
     */
    private function propertyAttributes(Property $property): array
    {
        $property->loadMissing(['currency', 'country']);

        return array_filter([
            'title' => $property->name,
            'currency' => $property->currency->code ?? 'INR',
            'country' => $property->country?->iso2,
            'timezone' => $property->timezone,
            'email' => $property->getAttribute('email'),
            'phone' => $property->getAttribute('phone'),
            'address' => trim(($property->getAttribute('address_line_1') ?? '').' '.($property->getAttribute('address_line_2') ?? '')) ?: null,
        ], fn ($value): bool => filled($value));
    }
}
