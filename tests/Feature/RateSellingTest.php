<?php

use App\Models\Booking;
use App\Models\ChannexConnection;
use App\Models\Currency;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\StayRestriction;
use App\Models\User;
use App\Services\Channex\ChannexAri;
use App\Services\PropertySettings;
use App\Services\RateRounding;
use App\Services\StayRestrictions;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->property = Property::factory()->create(['timezone' => 'UTC']);
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id, 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1, 'meal_plans' => ['EP']]);
    RoomCategory::query()->where('property_id', $this->property->id)->whereKeyNot($this->category->id)->delete();
    sellOnBookingEngine($this->property, $this->category, 1042.5);
    $this->today = CarbonImmutable::parse($this->property->today());
});

/**
 * Saves one of the property's Rate Settings JSON blobs.
 *
 * @param  array<string, mixed>  $data
 */
function rateSetting(Property $property, string $key, array $data): void
{
    $field = MetaField::query()->firstOrCreate(['key' => $key], ['key_label' => $key, 'status' => true]);
    $property->metaFieldValues()->updateOrCreate(['meta_fields_key_id' => $field->id], ['enabled' => true, 'data' => json_encode($data)]);
    app(PropertySettings::class)->forgetMetaFields();
    app(PropertySettings::class)->forget($property);
}

/**
 * A "Romance" package for the category's EP rate at $rate a night, on sale on the given nights.
 *
 * @param  list<string>  $nights
 * @param  array<string, mixed>  $overrides
 */
function romancePackage(object $test, float $rate, array $nights, array $overrides = []): void
{
    $days = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], $rate);

    rateSetting($test->property, 'rate_settings_packages', ['packages' => [[
        'id' => 7, 'name' => 'Romance', 'color' => '#e11d48', 'show_at_booking_engine' => true,
        'override_inclusions' => true, 'inclusions' => 'Dinner for two', 'length_of_stay_linked' => false,
        'rooms' => [(string) $test->category->id => ['EP']],
        'rates' => ['direct' => ["{$test->category->id}:EP:Single" => $days, "{$test->category->id}:EP:Double" => $days]],
        ...$overrides,
    ]]]);
    rateSetting($test->property, 'rate_settings_packages_planner', ['assignments' => collect($nights)->mapWithKeys(fn (string $night): array => [$night => [7]])->all()]);
}

/**
 * The booking engine's selection for one room of the category.
 *
 * @param  array<string, mixed>  $room
 * @return array<string, mixed>
 */
function engineStay(object $test, int $fromDays, int $toDays, array $room = []): array
{
    return [
        'check_in' => $test->today->addDays($fromDays)->toDateString(),
        'check_out' => $test->today->addDays($toDays)->toDateString(),
        'rooms' => [['category_id' => $test->category->id, 'meal_plan' => 'EP', 'adults' => 1, ...$room]],
    ];
}

test('rounding works to a multiple, up or down, and to set ending digits', function (string $method, float $multiple, ?float $ending, float $rate, float $expected) {
    expect(RateRounding::round($rate, $method, $multiple, $ending))->toBe($expected);
})->with([
    'nearest 10' => ['nearest', 10, null, 1042.5, 1040.0],
    'up 50' => ['up', 50, null, 1001, 1050.0],
    'down 100' => ['down', 100, null, 1099.99, 1000.0],
    'nearest 100 ending 99' => ['nearest', 100, 99, 2040, 1999.0],
    'exact stays' => ['up', 10, null, 1040, 1040.0],
]);

test('rounded rates are what the booking engine charges and the booking form suggests', function () {
    rateSetting($this->property, RateRounding::KEY, ['enabled' => true, 'method' => 'up', 'multiple' => 50, 'ending' => null, 'channels' => ['direct']]);

    $quote = $this->postJson(route('booking-engine.quote', $this->property->ulid), engineStay($this, 3, 5))->assertOk()->json('quote');

    expect($quote['rooms'][0]['rate_per_night'])->toEqual(1050)
        ->and($quote['room_total'])->toEqual(2100);

    $suggested = $this->actingAs($this->admin)
        ->getJson(route('admin.bookings.rateSuggestions', [$this->property, 'check_in' => $this->today->addDays(3)->toDateString(), 'check_out' => $this->today->addDays(4)->toDateString()]))
        ->json('rates');

    expect(collect($suggested)->first()["{$this->category->id}:EP:Single"]['mon'])->toEqual(1050);
});

test('rounding settings are saved from the Rounding tab', function () {
    $field = MetaField::query()->firstOrCreate(['key' => RateRounding::KEY], ['key_label' => 'Rounding', 'status' => true]);

    $this->actingAs($this->admin)
        ->put(route('admin.rateSettings.update', $this->property), ['meta_fields' => [$field->id => ['enabled' => true, 'data' => json_encode(['enabled' => true, 'method' => 'down', 'multiple' => 100, 'ending' => 99, 'channels' => ['direct', 'ota']])]]])
        ->assertSessionHasNoErrors();

    expect(app(RateRounding::class)->apply($this->property->fresh(), 2550.0))->toBe(2499.0);
});

test('stay restrictions are set by date and room category, and the stricter value counts', function () {
    $from = $this->today->addDays(10);

    $this->actingAs($this->admin)
        ->put(route('admin.rateRestrictions.update', $this->property), ['action' => 'set', 'from' => $from->toDateString(), 'to' => $from->addDays(6)->toDateString(), 'values' => ['min_stay' => 2]])
        ->assertSessionHasNoErrors();
    $this->put(route('admin.rateRestrictions.update', $this->property), [
        'action' => 'set', 'from' => $from->toDateString(), 'to' => $from->toDateString(), 'room_category_ids' => [$this->category->id], 'values' => ['min_stay' => 3, 'closed_to_arrival' => true],
    ])->assertSessionHasNoErrors();

    $restrictions = app(StayRestrictions::class);
    $effective = $restrictions->effective($this->property->id, [$this->category->id], $from, $from->addDay());

    expect(StayRestriction::query()->count())->toBe(8)
        ->and($effective[$from->toDateString()][$this->category->id])->toMatchArray(['min_stay' => 3, 'closed_to_arrival' => true])
        ->and($effective[$from->addDay()->toDateString()][$this->category->id]['min_stay'])->toBe(2)
        ->and($restrictions->violation($this->property->id, $this->category->id, $from->addDay()->toDateString(), $from->addDays(2)->toDateString()))->toContain('at least 2 nights')
        ->and($restrictions->violation($this->property->id, $this->category->id, $from->addDay()->toDateString(), $from->addDays(3)->toDateString()))->toBeNull();

    // Clearing the category's own values removes its row; the property-wide one stays.
    $this->put(route('admin.rateRestrictions.update', $this->property), [
        'action' => 'set', 'from' => $from->toDateString(), 'to' => $from->toDateString(), 'room_category_ids' => [$this->category->id], 'values' => ['min_stay' => null, 'closed_to_arrival' => false],
    ])->assertSessionHasNoErrors();

    expect(StayRestriction::query()->whereNotNull('room_category_id')->count())->toBe(0)
        ->and(StayRestriction::query()->count())->toBe(7);

    $this->get(route('admin.rateRestrictions.edit', [$this->property, 'from' => $from->toDateString()]))
        ->assertInertia(fn ($page) => $page->component('admin/rate-restrictions/Edit')->where("effective.{$from->toDateString()}.{$this->category->id}.min_stay", 2));

    // Clearing with no category ticked clears everything on the dates, a category's own too.
    StayRestriction::factory()->create(['property_id' => $this->property->id, 'room_category_id' => $this->category->id, 'date' => $from->toDateString()]);
    $this->put(route('admin.rateRestrictions.update', $this->property), ['action' => 'clear', 'from' => $from->toDateString(), 'to' => $from->addDays(6)->toDateString()])->assertSessionHasNoErrors();

    expect(StayRestriction::query()->count())->toBe(0);
});

test('the booking engine refuses a stay the restrictions rule out', function () {
    StayRestriction::factory()->create(['property_id' => $this->property->id, 'date' => $this->today->addDays(3)->toDateString(), 'min_stay' => 2]);
    StayRestriction::factory()->stopSell()->create(['property_id' => $this->property->id, 'room_category_id' => $this->category->id, 'date' => $this->today->addDays(8)->toDateString()]);

    $this->postJson(route('booking-engine.quote', $this->property->ulid), engineStay($this, 3, 4))
        ->assertJsonValidationErrors(['rooms.0.category_id' => 'at least 2 nights']);
    $this->postJson(route('booking-engine.quote', $this->property->ulid), engineStay($this, 3, 5))->assertOk();
    $this->postJson(route('booking-engine.quote', $this->property->ulid), engineStay($this, 7, 9))
        ->assertJsonValidationErrors(['rooms.0.category_id' => 'Not on sale']);

    $this->get(route('booking-engine.web', [$this->property->ulid, 'check_in' => $this->today->addDays(3)->toDateString(), 'check_out' => $this->today->addDays(4)->toDateString()]))
        ->assertInertia(fn ($page) => $page->where('roomCategories.0.available', 0)->where('roomCategories.0.restriction', fn (string $reason): bool => str_contains($reason, 'at least 2 nights')));
});

test('the booking form refuses a restricted stay unless the user may override and does', function () {
    StayRestriction::factory()->create(['property_id' => $this->property->id, 'date' => $this->today->addDays(3)->toDateString(), 'closed_to_arrival' => true, 'min_stay' => null]);
    $clerk = User::factory()->create();
    $clerk->assignRole(Role::findOrCreate('Clerk', 'web'));
    $clerk->givePermissionTo(Permission::findOrCreate('reservations.confirm.create_future', 'web'));

    $booking = fn (array $extra = []): array => [
        'source' => 'direct', 'type' => 'walk_in', 'status' => 'confirmed', 'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        'check_in' => $this->today->addDays(3)->toDateString(), 'check_out' => $this->today->addDays(5)->toDateString(),
        'rooms' => [['room_category_id' => $this->category->id, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
        ...$extra,
    ];

    $this->actingAs($clerk)->post(route('admin.bookings.store', $this->property), $booking(['override_restrictions' => true]))
        ->assertSessionHasErrors(['rooms.0.room_category_id' => 'Room 1 (Deluxe): Arrivals are closed on '.$this->today->addDays(3)->format('d M Y').'.']);

    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), $booking())
        ->assertSessionHasErrors(['rooms.0.room_category_id']);
    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), $booking(['override_restrictions' => true]))
        ->assertSessionHasNoErrors();

    expect(Booking::query()->where('property_id', $this->property->id)->count())->toBe(1);
});

test('a package is offered on its calendar nights, priced from its own rates, and recorded on the room', function () {
    romancePackage($this, 1500, [$this->today->addDays(3)->toDateString(), $this->today->addDays(4)->toDateString()]);

    $this->get(route('booking-engine.web', [$this->property->ulid, 'check_in' => $this->today->addDays(3)->toDateString(), 'check_out' => $this->today->addDays(5)->toDateString()]))
        ->assertInertia(fn ($page) => $page
            ->where('roomCategories.0.package_options.0.package_id', 7)
            ->where('roomCategories.0.package_options.0.inclusions', 'Dinner for two')
            ->where('roomCategories.0.package_options.0.rack_rate', 1500));

    // A night the calendar doesn't sell it on.
    $this->postJson(route('booking-engine.quote', $this->property->ulid), engineStay($this, 3, 6, ['package_id' => 7]))
        ->assertJsonValidationErrors(['rooms.0.package_id' => "Romance isn't on sale for the night of"]);

    bookThroughEngine($this, $this->property, engineStay($this, 3, 5, ['package_id' => 7]))->assertOk();

    $room = Booking::query()->where('property_id', $this->property->id)->sole()->rooms()->sole();

    expect($room->package_id)->toBe(7)
        ->and($room->package_name)->toBe('Romance')
        ->and((float) $room->rate_per_night)->toEqual(1500.0);
});

test('a package\'s conditions are checked: usage limit and length of stay', function () {
    romancePackage($this, 1500, [$this->today->addDays(3)->toDateString(), $this->today->addDays(4)->toDateString()], [
        'length_of_stay_linked' => true, 'min_nights' => 2, 'max_nights' => 3, 'usage_limit' => ['enabled' => true, 'value' => 1],
    ]);
    // A second room, so the package (not availability) is what runs out.
    $this->category->rooms()->create(['name' => 'Room 2', 'display_order' => 2]);

    $this->postJson(route('booking-engine.quote', $this->property->ulid), engineStay($this, 3, 4, ['package_id' => 7]))
        ->assertJsonValidationErrors(['rooms.0.package_id' => 'for stays of 2–3 nights']);

    bookThroughEngine($this, $this->property, engineStay($this, 3, 5, ['package_id' => 7]))->assertOk();

    $this->postJson(route('booking-engine.quote', $this->property->ulid), engineStay($this, 3, 5, ['package_id' => 7]))
        ->assertJsonValidationErrors(['rooms.0.package_id' => 'sold out']);
});

test('the booking form lists the packages for a room and records the one chosen', function () {
    romancePackage($this, 1500, [$this->today->addDays(3)->toDateString()]);

    $this->actingAs($this->admin)
        ->getJson(route('admin.bookings.packageOffers', [$this->property, 'check_in' => $this->today->addDays(3)->toDateString(), 'check_out' => $this->today->addDays(4)->toDateString(), 'room_category_id' => $this->category->id, 'meal_plan' => 'EP', 'adults' => 2]))
        ->assertOk()
        ->assertJsonPath('packages.0.name', 'Romance')
        ->assertJsonPath('packages.0.rate_per_night', 1500)
        ->assertJsonPath('restriction', null);

    $this->post(route('admin.bookings.store', $this->property), [
        'source' => 'direct', 'type' => 'walk_in', 'status' => 'confirmed', 'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        'check_in' => $this->today->addDays(3)->toDateString(), 'check_out' => $this->today->addDays(4)->toDateString(),
        'rooms' => [['room_category_id' => $this->category->id, 'meal_plan' => 'EP', 'package_id' => 7, 'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1500]],
    ])->assertSessionHasNoErrors();

    expect(Booking::query()->where('property_id', $this->property->id)->sole()->rooms()->sole()->package_name)->toBe('Romance');
});

test('the channel manager gets restrictions with the rates, and package rate plans their package rates', function () {
    $connection = ChannexConnection::factory()->create(['property_id' => $this->property->id, 'channex_property_id' => 'aaaaaaaa-0000-0000-0000-000000000001']);
    $connection->rateMappings()->create(['room_category_id' => $this->category->id, 'meal_plan' => 'EP', 'adults' => 2, 'channex_rate_plan_id' => 'plain-plan']);
    $connection->rateMappings()->create(['room_category_id' => $this->category->id, 'meal_plan' => 'EP', 'adults' => 2, 'package_id' => 7, 'channex_rate_plan_id' => 'package-plan']);
    romancePackage($this, 1500, [$this->today->addDay()->toDateString()]);
    StayRestriction::factory()->create(['property_id' => $this->property->id, 'date' => $this->today->addDay()->toDateString(), 'min_stay' => 2, 'closed_to_departure' => true]);

    $values = collect(app(ChannexAri::class)->rateValues($connection, $this->today, $this->today->addDays(2)));
    $plain = $values->where('rate_plan_id', 'plain-plan')->values();
    $package = $values->where('rate_plan_id', 'package-plan')->values();

    expect($plain->pluck('date_from')->all())->toBe([$this->today->toDateString(), $this->today->addDay()->toDateString(), $this->today->addDays(2)->toDateString()])
        ->and($plain[1])->toMatchArray(['rate' => '1042.50', 'min_stay_arrival' => 2, 'closed_to_departure' => true, 'stop_sell' => false])
        ->and($plain[0])->toMatchArray(['min_stay_arrival' => 1, 'max_stay' => 0, 'closed_to_departure' => false])
        // The package sells only on its calendar night.
        ->and($package->firstWhere('date_from', $this->today->addDay()->toDateString()))->toMatchArray(['rate' => '1500.00', 'stop_sell' => false])
        ->and($package->firstWhere('date_from', $this->today->toDateString()))->toMatchArray(['stop_sell' => true])
        ->and($package->firstWhere('date_from', $this->today->toDateString()))->not->toHaveKey('rate');
});
