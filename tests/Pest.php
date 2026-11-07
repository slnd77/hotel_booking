<?php

use App\Models\MetaField;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Services\ApplicationSettings;
use App\Services\PropertySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Run every feature test at 06:00 UTC on today's date (11:30 in Asia/Kolkata, the factory
    // property's zone), so a test's UTC "today" is also the property's local date at any hour.
    // A test that calls travelTo() itself still sets its own time.
    ->beforeEach(fn () => $this->travelTo(now()->utc()->setTime(6, 0)))
    ->in('Feature', 'Browser');

// Browser tests render in Playwright: a developer's Inertia SSR server (port 13714) would
// otherwise answer the second visit of a test with the first page's body.
pest()->beforeEach(fn () => config(['inertia.ssr.enabled' => false]))->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Switches the e-mail notification channel on with test SMTP settings, so hold
 * and enquiry reminders can use it.
 */
function enableEmailNotifications(): void
{
    $settings = app(ApplicationSettings::class);
    $notification = $settings->get(ApplicationSettings::NOTIFICATION);
    $notification['email']['enabled'] = true;
    $notification['email']['smtp'] = [...$notification['email']['smtp'], 'host' => 'smtp.example.com', 'port' => 587, 'from_address' => 'hotel@example.com'];

    $settings->save(ApplicationSettings::NOTIFICATION, $notification, null, 'Test setup.');
}

/**
 * Puts a room category on sale on the booking engine: the default rate plan prices it at
 * $rate a night, for any occupancy, on every day of the week; and it has a room to sell.
 */
function sellOnBookingEngine(Property $property, RoomCategory $category, float $rate, string $mealPlan = 'EP'): void
{
    $settings = app(PropertySettings::class);
    $field = MetaField::query()->firstOrCreate(['key' => 'rate_settings_rate_plan'], ['key_label' => 'Rate Plan', 'status' => true]);
    $data = $settings->json($property, 'rate_settings_rate_plan') ?? ['plans' => [['id' => 1, 'mapping_status' => 'default', 'active' => true, 'rates' => ['direct' => []]]]];

    foreach (['Single', 'Double', 'Triple', 'Quad'] as $occupancy) {
        $data['plans'][0]['rates']['direct']["{$category->id}:{$mealPlan}:{$occupancy}"] = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], $rate);
    }

    $property->metaFieldValues()->updateOrCreate(['meta_fields_key_id' => $field->id], ['enabled' => true, 'data' => json_encode($data)]);
    $settings->forgetMetaFields();
    $settings->forget($property);

    if ($category->rooms()->doesntExist()) {
        $category->rooms()->create(['name' => "Room {$category->id}", 'display_order' => 1]);
    }
}

/**
 * Books a selection (dates, rooms, add-ons, promo code, payment type) through the booking
 * engine on the test gateway: the checkout, then its payment's confirmation.
 *
 * @param  array<string, mixed>  $selection
 */
function bookThroughEngine(TestCase $test, Property $property, array $selection): TestResponse
{
    config(['services.test_gateway.enabled' => true]);

    $order = $test->postJson(route('booking-engine.checkout.initiate', $property->ulid), [
        'payment_gateway' => 'test',
        'consent' => ['terms' => true], 'guest' => ['name' => 'Asha Rao', 'phone' => '+91 9000000001', 'email' => 'asha@example.com'],
        ...$selection,
    ])->assertOk();

    return $test->postJson(route('booking-engine.checkout.confirm', $property->ulid), [
        'booking_ref_id' => $order->json('booking_ref_id'),
        'payment_gateway' => 'test',
        'transaction_id' => 'TXN_TEST_1',
        'order_id' => $order->json('order_id'),
    ]);
}
