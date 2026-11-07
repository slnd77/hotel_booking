<?php

use App\Classes\PaymentGateway\Cashfree;
use App\Classes\PaymentGateway\Razorpay;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\ApplicationSettings;
use App\Services\PropertySettings;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

test('a room cannot be changed through another property', function () {
    $mine = Property::factory()->create();
    $myCategory = RoomCategory::factory()->create(['property_id' => $mine->id]);
    $theirs = RoomCategory::factory()->create(['property_id' => Property::factory()->create()->id]);
    $theirRoom = $theirs->rooms()->create(['name' => '101', 'display_order' => 1]);

    $this->actingAs($this->admin)
        ->delete(route('admin.properties.room-categories.rooms.destroy', [$mine, $myCategory, $theirRoom]))
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->delete(route('admin.properties.room-categories.destroy', [$mine, $theirs]))
        ->assertNotFound();

    expect(Room::query()->whereKey($theirRoom->id)->exists())->toBeTrue()
        ->and(RoomCategory::query()->whereKey($theirs->id)->exists())->toBeTrue();
});

test('gateways without keys never accept a payment in production', function () {
    $this->app['env'] = 'production';

    expect((new Razorpay('rzp_test_placeholder', ''))->verifyPayment(['order_id' => 'order_1'])['success'])->toBeFalse()
        ->and((new Cashfree('cf_test_placeholder', ''))->verifyPayment(['order_id' => 'order_1'])['success'])->toBeFalse();
});

test('a payment smaller than the quote does not confirm the booking', function () {
    $property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $property->id, 'meal_plans' => ['EP']]);
    sellOnBookingEngine($property, $category, 1000);

    $field = MetaField::query()->firstOrCreate(['key' => 'booking_engine_settings'], ['key_label' => 'Booking Engine', 'status' => true]);
    $property->metaFieldValues()->create(['meta_fields_key_id' => $field->id, 'enabled' => true, 'data' => json_encode(['allowed_payment_gateways' => [
        ['id' => 'cashfree', 'name' => 'Cashfree', 'enabled' => true, 'key' => 'cf_live_app', 'secret' => 'cf_secret', 'mode' => 'sandbox'],
    ]])]);
    app(PropertySettings::class)->forgetMetaFields();

    Http::fake([
        'sandbox.cashfree.com/pg/orders/order_cheap/settlements' => Http::response([], 404),
        'sandbox.cashfree.com/pg/orders/order_cheap' => Http::response(['order_status' => 'PAID', 'order_amount' => 1]),
        'sandbox.cashfree.com/pg/orders' => Http::response(['order_id' => 'order_cheap', 'payment_session_id' => 'session_1']),
    ]);

    $order = $this->postJson(route('booking-engine.checkout.initiate', $property->ulid), [
        'payment_gateway' => 'cashfree',
        'check_in' => now()->addDays(3)->toDateString(),
        'check_out' => now()->addDays(5)->toDateString(),
        'rooms' => [['category_id' => $category->id, 'meal_plan' => 'EP']],
        'consent' => ['terms' => true], 'guest' => ['name' => 'Asha Rao', 'phone' => '+91 9000000001', 'email' => 'asha@example.com'],
    ])->assertOk();

    $this->postJson(route('booking-engine.checkout.confirm', $property->ulid), [
        'booking_ref_id' => $order->json('booking_ref_id'),
        'payment_gateway' => 'cashfree',
        'order_id' => 'order_cheap',
    ])->assertUnprocessable()->assertJsonValidationErrors(['payment_gateway' => 'does not match']);

    expect(Booking::query()->where('property_id', $property->id)->exists())->toBeFalse();
});

test('checkout is rate limited per property, apart from quotes', function () {
    config(['services.booking_engine.checkout_per_minute' => 3]);
    $property = Property::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->postJson(route('booking-engine.quote', $property->ulid), [])->assertUnprocessable();
    }

    foreach (range(1, 3) as $attempt) {
        $this->postJson(route('booking-engine.checkout.confirm', $property->ulid), [])->assertUnprocessable();
    }

    $this->postJson(route('booking-engine.checkout.initiate', $property->ulid), [])->assertTooManyRequests();
    $this->postJson(route('booking-engine.quote', $property->ulid), [])->assertUnprocessable();
    $this->postJson(route('booking-engine.checkout.confirm', Property::factory()->create()->ulid), [])->assertUnprocessable();
});

test('the smtp test refuses internal hosts and does not echo connection errors', function () {
    $settings = app(ApplicationSettings::class);
    $notification = $settings->get(ApplicationSettings::NOTIFICATION);
    $notification['email']['smtp'] = [...$notification['email']['smtp'], 'host' => '127.0.0.1', 'port' => 2525, 'from_address' => 'pms@example.com'];
    $settings->save(ApplicationSettings::NOTIFICATION, $notification, null, 'Test setup.');

    $this->actingAs($this->admin)
        ->post(route('admin.applicationSettings.notificationChannels.email.test'), ['to' => 'me@example.com'])
        ->assertSessionHasErrors(['to' => 'The test e-mail could not be sent. Please verify the host, port, and credentials.']);

    // CSRF is only skipped in the testing environment.
    $this->app['env'] = 'production';

    $this->withoutMiddleware(PreventRequestForgery::class)->actingAs($this->admin)
        ->post(route('admin.applicationSettings.notificationChannels.email.test'), ['to' => 'me@example.com'])
        ->assertSessionHasErrors(['to' => 'The SMTP host must not be a private or internal IP address.']);
});
