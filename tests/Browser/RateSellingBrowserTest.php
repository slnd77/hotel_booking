<?php

use App\Models\Addon;
use App\Models\Currency;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\StayRestriction;
use App\Models\User;
use App\Services\PropertySettings;
use App\Services\RateRounding;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->property = Property::factory()->create(['timezone' => 'UTC']);
    $this->category = RoomCategory::factory()->create([
        'property_id' => $this->property->id, 'name' => 'Deluxe', 'max_adults' => 2, 'max_children' => 1,
        'ideal_occupancy' => 2, 'default_number_of_children' => 0, 'meal_plans' => ['EP'],
    ]);
    RoomCategory::query()->where('property_id', $this->property->id)->whereKeyNot($this->category->id)->delete();
    sellOnBookingEngine($this->property, $this->category, 2040);
    $this->today = CarbonImmutable::parse($this->property->today());
});

/**
 * @param  array<string, mixed>  $data
 */
function browserRateSetting(Property $property, string $key, array $data): void
{
    $field = MetaField::query()->firstOrCreate(['key' => $key], ['key_label' => $key, 'status' => true]);
    $property->metaFieldValues()->updateOrCreate(['meta_fields_key_id' => $field->id], ['enabled' => true, 'data' => json_encode($data)]);
    app(PropertySettings::class)->forgetMetaFields();
    app(PropertySettings::class)->forget($property);
}

/**
 * The booking engine for one room of two adults over the nights.
 */
function bookingEngineUrl(object $test, int $fromDays, int $toDays): string
{
    return route('booking-engine.web', [
        $test->property->ulid,
        'check_in' => $test->today->addDays($fromDays)->toDateString(),
        'check_out' => $test->today->addDays($toDays)->toDateString(),
    ]);
}

test('rounding is set on the Rounding tab, previewed, and charged on the booking engine', function () {
    $this->actingAs($this->admin);

    visit(route('admin.rateSettings.edit', [$this->property, 'tab' => 'rounding']))
        ->assertSee('Round rates')
        ->check('@rounding-enabled')
        ->select('@rounding-method', 'up')
        ->select('@rounding-multiple', '100')
        ->type('@rounding-ending', '99')
        ->assertSee('2040.00 → 2099.00')
        ->click('@rounding-save')
        ->assertSee('Rate settings updated.')
        ->assertNoJavaScriptErrors();

    expect(app(RateRounding::class)->get($this->property->fresh()))->toMatchArray(['enabled' => true, 'method' => 'up', 'multiple' => 100.0, 'ending' => 99.0]);

    // 2 040 a night sells at 2 099.
    visit(bookingEngineUrl($this, 3, 4))
        ->assertSee('Deluxe')
        ->assertSee('2,099.00')
        ->assertNoJavaScriptErrors();
});

test('a restriction set on the Restrictions page stops the booking engine selling the stay', function () {
    $this->actingAs($this->admin);
    $arrival = $this->today->addDays(3)->toDateString();

    visit(route('admin.rateRestrictions.edit', $this->property))
        ->assertSee('Stay Restrictions')
        ->type('@restriction-from', $arrival)
        ->type('@restriction-to', $arrival)
        ->check('@restriction-change-min')
        ->type('@restriction-min', '2')
        ->click('@restriction-apply')
        ->assertSee('Restrictions saved.')
        ->assertSee('min 2')
        ->assertNoJavaScriptErrors();

    expect(StayRestriction::query()->where('property_id', $this->property->id)->sole()->min_stay)->toBe(2);

    visit(bookingEngineUrl($this, 3, 4))
        ->assertSee("Deluxe: Stays arriving on {$this->today->addDays(3)->format('d M Y')} are at least 2 nights.");

    visit(bookingEngineUrl($this, 3, 5))
        ->assertDontSee('can\'t be booked for these dates')
        ->assertSee('Deluxe');
});

test('a package is sold on the booking engine with add-ons, priced by the server', function () {
    $days = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 3000);
    browserRateSetting($this->property, 'rate_settings_packages', ['packages' => [[
        'id' => 4, 'name' => 'Honeymoon', 'show_at_booking_engine' => true, 'override_inclusions' => true, 'inclusions' => 'Candle-lit dinner',
        'rooms' => [(string) $this->category->id => ['EP']],
        'rates' => ['direct' => ["{$this->category->id}:EP:Double" => $days, "{$this->category->id}:EP:Single" => $days]],
    ]]]);
    browserRateSetting($this->property, 'rate_settings_packages_planner', ['assignments' => [$this->today->addDays(3)->toDateString() => [4]]]);
    $pickup = Addon::query()->create(['property_id' => $this->property->id, 'name' => 'Airport pickup', 'pricing' => 'per_booking', 'price' => 800, 'tax_rate' => 0, 'show_on_booking_engine' => true, 'status' => true]);

    visit(bookingEngineUrl($this, 3, 4))
        ->assertSee('Honeymoon (EP)')
        ->assertSee('PACKAGE')
        ->assertSee('Candle-lit dinner')
        ->click("[data-test=\"add-{$this->category->id}-package:4:EP\"]")
        ->assertSee('Room added to cart')
        ->click('PROCEED')
        ->assertSee('Add to your stay')
        ->check("@addon-{$pickup->id}")
        ->assertSee('Airport pickup × 1')
        ->assertSee('3,800.00')
        ->assertNoJavaScriptErrors();
});

test('the booking form offers the room\'s packages and takes the package rate', function () {
    $days = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 3000);
    browserRateSetting($this->property, 'rate_settings_packages', ['packages' => [[
        'id' => 4, 'name' => 'Honeymoon', 'show_at_booking_engine' => true,
        'rooms' => [(string) $this->category->id => ['EP']],
        'rates' => ['direct' => ["{$this->category->id}:EP:Double" => $days, "{$this->category->id}:EP:Single" => $days]],
    ]]]);
    browserRateSetting($this->property, 'rate_settings_packages_planner', ['assignments' => [$this->today->addDays(3)->toDateString() => [4]]]);
    StayRestriction::factory()->create(['property_id' => $this->property->id, 'date' => $this->today->addDays(3)->toDateString(), 'closed_to_departure' => false, 'min_stay' => null, 'closed_to_arrival' => true]);
    $this->actingAs($this->admin);

    visit(route('admin.bookings.create', [$this->property, 'check_in' => $this->today->addDays(3)->toDateString(), 'check_out' => $this->today->addDays(4)->toDateString()]))
        ->assertSee('Override restrictions')
        ->assertSee('Restricted: Arrivals are closed on '.$this->today->addDays(3)->format('d M Y').'.')
        ->assertValue('@room-rate-0', '2040')
        ->select('@room-package-0', 'Honeymoon — 3000/night')
        ->assertValue('@room-rate-0', '3000')
        ->assertNoJavaScriptErrors();
});
