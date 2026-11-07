<?php

use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\BookingEngineService;
use App\Services\OccupancyPricing;
use App\Services\PropertySettings;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->property = Property::factory()->create(['timezone' => 'UTC']);
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id, 'name' => 'Deluxe', 'max_adults' => 2, 'meal_plans' => ['EP']]);
    RoomCategory::query()->where('property_id', $this->property->id)->whereKeyNot($this->category->id)->delete();
    sellOnBookingEngine($this->property, $this->category, 2000);

    // Four rooms to sell.
    while ($this->category->rooms()->count() < 4) {
        Room::factory()->create(['room_category_id' => $this->category->id]);
    }

    $this->night = CarbonImmutable::parse($this->property->today())->addDays(10);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function occupancyPricing(Property $property, array $overrides = []): void
{
    $field = MetaField::query()->firstOrCreate(['key' => OccupancyPricing::KEY], ['key_label' => 'Occupancy pricing', 'status' => true]);
    $property->metaFieldValues()->updateOrCreate(['meta_fields_key_id' => $field->id], ['enabled' => true, 'data' => json_encode([
        'enabled' => true,
        'basis' => 'property',
        'channels' => ['direct', 'ota'],
        'tiers' => [
            ['id' => 1, 'from' => 0, 'to' => 40, 'adjustment_type' => 'percent', 'adjustment' => -10],
            ['id' => 2, 'from' => 75, 'to' => 100, 'adjustment_type' => 'amount', 'adjustment' => 500],
        ],
        ...$overrides,
    ])]);
    app(PropertySettings::class)->forgetMetaFields();
    app(PropertySettings::class)->forget($property);
    app(OccupancyPricing::class)->forget();
}

/**
 * Sells $rooms of the category for the test night.
 */
function occupyRooms(object $test, int $rooms): void
{
    $booking = Booking::query()->create([
        'property_id' => $test->property->id,
        'booking_ref_id' => 'OCC-'.$rooms,
        'status' => 'confirmed',
        'check_in' => $test->night->toDateString(),
        'check_out' => $test->night->addDay()->toDateString(),
    ]);

    foreach ($test->category->rooms()->limit($rooms)->get() as $room) {
        $booking->rooms()->create(['room_category_id' => $test->category->id, 'room_id' => $room->id, 'adults' => 2, 'children' => 0, 'rate_per_night' => 2000]);
    }

    app(OccupancyPricing::class)->forget();
}

it('discounts a quiet night and marks up a busy one', function () {
    occupancyPricing($this->property);
    $engine = app(BookingEngineService::class);

    // Nobody booked: 0% occupancy → −10%.
    expect($engine->nightlyRate($this->property, $this->category, 'EP', 2, $this->night))->toBe(1800.0);

    // Three of four rooms sold: 75% → +500.
    occupyRooms($this, 3);
    expect($engine->nightlyRate($this->property, $this->category, 'EP', 2, $this->night))->toBe(2500.0)
        ->and(app(OccupancyPricing::class)->occupancyPercent($this->property, null, $this->night))->toBe(75.0);
});

it('keeps the rate plan price when occupancy falls outside every tier', function () {
    occupancyPricing($this->property);
    occupyRooms($this, 2);

    // 50% sits between the 0–40 and 75–100 tiers.
    expect(app(BookingEngineService::class)->nightlyRate($this->property, $this->category, 'EP', 2, $this->night))->toBe(2000.0);
});

it('leaves rates alone when switched off or on a channel it does not apply to', function () {
    occupancyPricing($this->property, ['channels' => ['ota']]);
    $engine = app(BookingEngineService::class);

    expect($engine->nightlyRate($this->property, $this->category, 'EP', 2, $this->night))->toBe(2000.0)
        ->and($engine->nightlyRate($this->property, $this->category, 'EP', 2, $this->night, 'ota'))->toBe(1800.0);

    occupancyPricing($this->property, ['enabled' => false]);
    expect($engine->nightlyRate($this->property, $this->category, 'EP', 2, $this->night, 'ota'))->toBe(2000.0);
});

it('measures occupancy per category when asked to', function () {
    $other = RoomCategory::factory()->create(['property_id' => $this->property->id, 'name' => 'Suite', 'max_adults' => 2, 'meal_plans' => ['EP']]);
    sellOnBookingEngine($this->property, $other, 5000);
    occupancyPricing($this->property, ['basis' => 'category']);
    occupyRooms($this, 4);

    $engine = app(BookingEngineService::class);

    // Deluxe is full (+500); the empty Suite is discounted (−10%).
    expect($engine->nightlyRate($this->property, $this->category, 'EP', 2, $this->night))->toBe(2500.0)
        ->and($engine->nightlyRate($this->property, $other, 'EP', 2, $this->night))->toBe(4500.0);
});

it('saves the settings from Rate Settings and never prices below zero', function () {
    $field = MetaField::query()->firstOrCreate(['key' => OccupancyPricing::KEY], ['key_label' => 'Occupancy pricing', 'status' => true]);

    $this->actingAs($this->admin)
        ->put(route('admin.rateSettings.update', $this->property), ['meta_fields' => [$field->id => ['enabled' => true, 'data' => json_encode([
            'enabled' => true, 'basis' => 'category', 'channels' => ['direct'],
            'tiers' => [['id' => 1, 'from' => 90, 'to' => 100, 'adjustment_type' => 'amount', 'adjustment' => -5000]],
        ])]]])
        ->assertSessionHasNoErrors();

    app(PropertySettings::class)->forgetMetaFields();
    app(PropertySettings::class)->forget($this->property);

    expect(app(OccupancyPricing::class)->get($this->property))->toMatchArray(['enabled' => true, 'basis' => 'category', 'channels' => ['direct']])
        ->and(OccupancyPricing::adjust(2000, 'amount', -5000))->toBe(0.0);
});
