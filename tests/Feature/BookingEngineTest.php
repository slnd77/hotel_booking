<?php

use App\Models\Amenity;
use App\Models\AmenityCategory;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\PropertyCancellationRule;
use App\Models\RoomCategory;
use App\Models\WebCheckin;
use App\Services\PropertyBranding;
use App\Services\PropertySettings;
use App\Services\WebCheckins;

beforeEach(function () {
    Currency::create(['code' => 'USD', 'name' => 'USD']);
    Language::create(['code' => 'en', 'name' => 'English']);
});

test('the booking engine page resolves a property by ulid and lists its room categories', function () {
    $property = Property::factory()->create(['name' => 'Test Hotel']);
    $category = RoomCategory::factory()->create(['property_id' => $property->id, 'name' => 'Deluxe Room', 'meal_plans' => []]);

    $response = $this->get("/properties/{$property->ulid}/booking-engine/web");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('BookingEngine/Web')->where('property.name', 'Test Hotel'));

    $props = $response->viewData('page')['props'];
    $row = collect($props['roomCategories'])->firstWhere('id', $category->id);

    expect($row)->not->toBeNull();
    expect($row['name'])->toBe('Deluxe Room');
    expect($row['nightly_price'])->toBe(0.0);
});

test('the booking engine page includes room amenities and the active cancellation policy', function () {
    $property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $property->id, 'meal_plans' => []]);

    $amenityCategory = AmenityCategory::create(['name' => 'General', 'status' => true]);
    $amenity = Amenity::create(['key' => 'wifi', 'name' => 'Free WiFi', 'amenity_category_id' => $amenityCategory->id, 'type' => 'room_category']);
    $category->amenities()->attach($amenity->id, ['property_id' => $property->id]);

    PropertyCancellationRule::create([
        'property_id' => $property->id,
        'start_time_stamp' => now()->subDay(),
        'end_time_stamp' => now()->addDay(),
        'rules' => [
            ['day_from_checkin' => 7, 'penalty_percent' => 0],
            ['day_from_checkin' => 1, 'penalty_percent' => 50],
        ],
    ]);

    $response = $this->get("/properties/{$property->ulid}/booking-engine/web");

    $response->assertOk();

    $props = $response->viewData('page')['props'];
    $row = collect($props['roomCategories'])->firstWhere('id', $category->id);

    expect($row['amenities'])->toBe(['Free WiFi']);
    expect($row['categorized_amenities'])->toBe([
        [
            'category' => 'General',
            'amenities' => ['Free WiFi'],
        ],
    ]);
    expect($props['cancellationPolicy'])->toBe([
        '7+ day(s) before check-in: 0% penalty',
        '1+ day(s) before check-in: 50% penalty',
    ]);
    expect($props['cancellationRuleSummary'])->toBeArray()->toHaveCount(2);
    expect($props['cancellationRuleSummary'][0]['window'])->toBe('0 to 1 day(s) before check-in');
    expect($props['cancellationRuleSummary'][0]['penalty'])->toBe('50%');
});

test('the booking engine page accepts a JSON-encoded rooms search payload', function () {
    $property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $property->id, 'meal_plans' => []]);

    $rooms = json_encode([
        ['room_category_id' => $category->id, 'adults' => 2, 'children' => 1, 'infants' => 0],
        ['room_category_id' => null, 'adults' => 1, 'children' => 0, 'infants' => 1],
    ]);

    $response = $this->get('/properties/'.$property->ulid.'/booking-engine/web?rooms='.urlencode($rooms));

    $response->assertOk();

    $props = $response->viewData('page')['props'];

    expect($props['search']['rooms'])->toHaveCount(2);
    expect($props['search']['rooms'][0]['room_category_id'])->toBe($category->id);
    expect($props['search']['rooms'][0]['adults'])->toBe(2);
    expect($props['search']['rooms'][1]['infants'])->toBe(1);
});

test('the booking engine page 404s for an unknown ulid', function () {
    $response = $this->get('/properties/01ARZ3NDEKTSV4RRFFQ69G5FAV/booking-engine/web');

    $response->assertNotFound();
});

test('the booking engine page excludes rooms already booked for the requested dates from availability', function () {
    $property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $property->id, 'meal_plans' => []]);
    $room = $category->rooms()->create(['name' => 'Room 101', 'display_order' => 1]);

    $booking = $property->bookings()->create([
        'booking_ref_id' => 'REF-1',
        'check_in' => '2027-01-10',
        'check_out' => '2027-01-12',
    ]);
    $booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $room->id, 'adults' => 1]);

    $response = $this->get("/properties/{$property->ulid}/booking-engine/web?check_in=2027-01-10&check_out=2027-01-12");

    $response->assertOk();

    $props = $response->viewData('page')['props'];
    $row = collect($props['roomCategories'])->firstWhere('id', $category->id);

    expect($row['available'])->toBe(0);
});

test('the booking engine page shows a disabled notice when the property has turned it off', function () {
    $property = Property::factory()->create(['name' => 'Test Hotel']);

    $metaField = MetaField::create(['key' => 'booking_engine_settings', 'key_label' => 'Booking Engine', 'status' => true]);
    $property->metaFieldValues()->create([
        'meta_fields_key_id' => $metaField->id,
        'enabled' => true,
        'data' => json_encode(['enable_booking_engine' => false]),
    ]);

    $response = $this->get("/properties/{$property->ulid}/booking-engine/web");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('BookingEngine/Disabled')
        ->where('property.name', 'Test Hotel'));
});

function seedDefaultRatePlan(Property $property, RoomCategory $category, string $mealPlan, int $rate): void
{
    $field = MetaField::query()->firstOrCreate(
        ['key' => 'rate_settings_rate_plan'],
        ['key_label' => 'Rate Plan', 'status' => true],
    );

    $property->metaFieldValues()->updateOrCreate(
        ['meta_fields_key_id' => $field->id],
        [
            'enabled' => true,
            'data' => json_encode([
                'plans' => [[
                    'id' => 1,
                    'mapping_status' => 'default',
                    'active' => true,
                    'rates' => [
                        'direct' => [
                            "{$category->id}:{$mealPlan}:Single" => array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], $rate),
                        ],
                    ],
                ]],
            ]),
        ],
    );
}

test('the booking engine calendar returns the cheapest per-day rate', function () {
    $property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $property->id, 'meal_plans' => ['EP']]);
    $category->rooms()->create(['name' => 'Room 1', 'display_order' => 1]);

    seedDefaultRatePlan($property, $category, 'EP', 5000);

    $response = $this->getJson("/properties/{$property->ulid}/booking-engine/calendar?from=2027-01-01&to=2027-01-05");

    $response->assertOk();
    $days = $response->json('days');

    expect($days)->toHaveCount(4);
    expect($days[0]['date'])->toBe('2027-01-01');
    expect($days[0]['available'])->toBeTrue();
    expect($days[0]['price'])->toBe(5000);
});

test('the booking engine calendar marks a fully booked night as unavailable', function () {
    $property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $property->id, 'meal_plans' => ['EP']]);
    $room = $category->rooms()->create(['name' => 'Room 1', 'display_order' => 1]);

    seedDefaultRatePlan($property, $category, 'EP', 5000);

    $booking = $property->bookings()->create([
        'booking_ref_id' => 'REF-2',
        'check_in' => '2027-01-01',
        'check_out' => '2027-01-02',
    ]);
    $booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $room->id, 'adults' => 1]);

    $response = $this->getJson("/properties/{$property->ulid}/booking-engine/calendar?from=2027-01-01&to=2027-01-03");

    $response->assertOk();
    $days = collect($response->json('days'))->keyBy('date');

    expect($days['2027-01-01']['available'])->toBeFalse();
    expect($days['2027-01-01']['price'])->toBeNull();
    expect($days['2027-01-02']['available'])->toBeTrue();
});

test('the booking engine page passes booking engine settings with partial payment and allowed gateways', function () {
    $property = Property::factory()->create();
    $field = MetaField::query()->firstOrCreate(
        ['key' => 'booking_engine_settings'],
        ['key_label' => 'Booking Engine Settings', 'status' => true],
    );

    $property->metaFieldValues()->create([
        'meta_fields_key_id' => $field->id,
        'enabled' => true,
        'data' => json_encode([
            'enable_booking_engine' => true,
            'allow_partial_payment' => true,
            'partial_payment_percentage' => 40,
            'allowed_payment_gateways' => [
                ['id' => 'razorpay', 'name' => 'Razorpay', 'enabled' => true],
                ['id' => 'stripe', 'name' => 'Stripe', 'enabled' => false],
            ],
        ]),
    ]);

    $response = $this->get("/properties/{$property->ulid}/booking-engine/web");

    $response->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['bookingEngineSettings']['allow_partial_payment'])->toBeTrue();
    expect($props['bookingEngineSettings']['partial_payment_percentage'])->toBe(40.0);
    expect($props['bookingEngineSettings']['allowed_payment_gateways'])->toHaveCount(2);
    expect($props['bookingEngineSettings']['allowed_payment_gateways'][0]['name'])->toBe('Razorpay');
});

test('the booking engine page passes formatted terms and conditions from property terms_and_conditions_while_booking', function () {
    $property = Property::factory()->create();
    $field = MetaField::query()->firstOrCreate(
        ['key' => 'terms_and_conditions_while_booking'],
        ['key_label' => 'Add Standard/Common Terms & Conditions while booking a room at your property.', 'status' => true],
    );

    $formattedTerms = '<p><strong>Check-in Policy:</strong> Guests must present a government photo ID.</p><ul><li>No smoking</li><li>Pets allowed on request</li></ul>';

    $property->metaFieldValues()->create([
        'meta_fields_key_id' => $field->id,
        'enabled' => true,
        'data' => $formattedTerms,
    ]);

    $response = $this->get("/properties/{$property->ulid}/booking-engine/web");

    $response->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['termsAndConditions'])->toBe($formattedTerms);
});

test('checkout initiation returns valid order and session for razorpay and cashfree', function () {
    $property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $property->id]);
    $room = $category->rooms()->create(['name' => 'Room 101', 'display_order' => 1]);
    sellOnBookingEngine($property, $category, 250);
    // The gateways have no keys here, so they only simulate payment.
    config(['services.test_gateway.enabled' => true]);

    $payload = [
        'payment_gateway' => 'razorpay',
        'payment_type' => 'full',
        'pay_now_amount' => 500.0,
        'total_amount' => 500.0,
        'consent' => ['terms' => true], 'guest' => [
            'name' => 'John Doe',
            'phone' => '+91 9876543210',
            'email' => 'john@example.com',
            'special_requests' => 'Quiet room please',
        ],
        'check_in' => '2027-02-10',
        'check_out' => '2027-02-12',
        'rooms' => [
            [
                'category_id' => $category->id,
                'room_id' => $category->id,
                'meal_plan' => 'EP',
                'nightly_price' => 250.0,
                'adults' => 2,
                'children' => 0,
                'infants' => 0,
            ],
        ],
    ];

    // Razorpay initiation
    $responseRzp = $this->postJson("/properties/{$property->ulid}/booking-engine/checkout/initiate", $payload);
    $responseRzp->assertOk();
    expect($responseRzp->json('success'))->toBeTrue();
    expect($responseRzp->json('gateway'))->toBe('razorpay');
    expect($responseRzp->json('booking_ref_id'))->toStartWith('BK-');
    expect($responseRzp->json('order_id'))->not()->toBeEmpty();
    // Two nights at 250, in paise: the server's price, not the page's.
    expect($responseRzp->json('amount'))->toBe(50000);

    // Cashfree initiation
    $payload['payment_gateway'] = 'cashfree';
    $responseCf = $this->postJson("/properties/{$property->ulid}/booking-engine/checkout/initiate", $payload);
    $responseCf->assertOk();
    expect($responseCf->json('success'))->toBeTrue();
    expect($responseCf->json('gateway'))->toBe('cashfree');
    expect($responseCf->json('payment_session_id'))->not()->toBeEmpty();
});

test('checkout confirmation books the stay as the server priced it, with the guest and rooms', function () {
    $property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $property->id, 'meal_plans' => ['EP', 'CP']]);
    sellOnBookingEngine($property, $category, 250, 'CP');
    $field = MetaField::query()->firstOrCreate(['key' => 'booking_engine_settings'], ['key_label' => 'Booking Engine', 'status' => true]);
    $property->metaFieldValues()->create(['meta_fields_key_id' => $field->id, 'enabled' => true, 'data' => json_encode(['allow_partial_payment' => true, 'partial_payment_percentage' => 40])]);

    $response = bookThroughEngine($this, $property, [
        'payment_type' => 'partial',
        'check_in' => '2027-03-01',
        'check_out' => '2027-03-03',
        // Prices from the page are ignored.
        'pay_now_amount' => 1,
        'total_amount' => 1,
        'rooms' => [['category_id' => $category->id, 'meal_plan' => 'CP', 'nightly_price' => 1, 'adults' => 1]],
        'consent' => ['terms' => true], 'guest' => ['name' => 'Jane Smith', 'phone' => '+91 9123456780', 'email' => 'jane@example.com', 'special_requests' => 'Late check-in'],
    ])->assertOk();

    expect($response->json('booking.status'))->toBe('confirmed')
        ->and($response->json('booking.total'))->toEqual(500)
        ->and($response->json('booking.paid_now'))->toEqual(200)
        ->and($response->json('booking.outstanding'))->toEqual(300);

    $this->assertDatabaseHas('bookings', [
        'property_id' => $property->id,
        'booking_ref_id' => $response->json('booking.booking_ref_id'),
        'status' => 'confirmed',
        'pay_at_hotel' => true,
        'room_total' => 500.0,
        'total' => 500.0,
        'outstanding' => 300.0,
        'special_instructions' => 'Late check-in',
    ]);
    $this->assertDatabaseHas('sub_bookings', ['room_category_id' => $category->id, 'meal_plan' => 'CP', 'rate_per_night' => 250.0]);
    $this->assertDatabaseHas('guests', ['property_id' => $property->id, 'first_name' => 'Jane', 'last_name' => 'Smith', 'phone' => '+91 9123456780']);
});

test('checkout confirmation books only a checkout the server priced, after its payment is verified', function () {
    $property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $property->id, 'meal_plans' => ['EP']]);
    sellOnBookingEngine($property, $category, 250);
    config(['services.test_gateway.enabled' => true]);
    $confirmUrl = "/properties/{$property->ulid}/booking-engine/checkout/confirm";

    $this->postJson($confirmUrl, ['booking_ref_id' => 'BK-MADEUP', 'payment_gateway' => 'test', 'order_id' => 'test_order_x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('booking_ref_id');

    $order = $this->postJson("/properties/{$property->ulid}/booking-engine/checkout/initiate", [
        'payment_gateway' => 'test',
        'consent' => ['terms' => true], 'guest' => ['name' => 'Jane Smith', 'phone' => '+91 9123456780', 'email' => 'jane@example.com'],
        'check_in' => '2027-03-01',
        'check_out' => '2027-03-03',
        'rooms' => [['category_id' => $category->id, 'meal_plan' => 'EP', 'adults' => 1]],
    ])->assertOk();

    // Another order's payment doesn't pay for this checkout.
    $this->postJson($confirmUrl, ['booking_ref_id' => $order->json('booking_ref_id'), 'payment_gateway' => 'test', 'order_id' => 'test_order_other'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('booking_ref_id');

    $confirm = ['booking_ref_id' => $order->json('booking_ref_id'), 'payment_gateway' => 'test', 'order_id' => $order->json('order_id'), 'transaction_id' => 'TXN_1'];
    $this->postJson($confirmUrl, $confirm)->assertOk();
    $this->postJson($confirmUrl, $confirm)->assertOk()->assertJsonPath('booking.booking_ref_id', $order->json('booking_ref_id'));

    expect(Booking::query()->count())->toBe(1);
});

test('the booking engine quote validates the selection and prices it with taxes', function () {
    $property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $property->id, 'meal_plans' => ['EP'], 'max_adults' => 2, 'max_children' => 0]);
    $unpriced = RoomCategory::factory()->create(['property_id' => $property->id, 'meal_plans' => ['EP']]);
    $unpriced->rooms()->create(['name' => 'Room 9', 'display_order' => 1]);
    sellOnBookingEngine($property, $category, 1000);

    $categoryField = MetaField::query()->firstOrCreate(['key' => 'tax_settings_tax_category'], ['key_label' => 'Tax Category', 'status' => true]);
    $ruleField = MetaField::query()->firstOrCreate(['key' => 'tax_settings_tax_rule'], ['key_label' => 'Tax Rule', 'status' => true]);
    $property->metaFieldValues()->create(['meta_fields_key_id' => $categoryField->id, 'enabled' => true, 'data' => json_encode(['categories' => [['id' => 1, 'name' => 'Room']]])]);
    $property->metaFieldValues()->create(['meta_fields_key_id' => $ruleField->id, 'enabled' => true, 'data' => json_encode(['rules' => [[
        'deleted' => false, 'tax_category_id' => 1, 'start_date' => null, 'end_date' => null,
        'components' => [['component_choice' => 'GST', 'applied_on' => 'rack_rate', 'define_tax_slab' => false, 'tax_value_type' => 'percentage', 'tax_value' => 12]],
    ]]])]);
    app(PropertySettings::class)->forgetMetaFields();

    $url = "/properties/{$property->ulid}/booking-engine/quote";
    $stay = ['check_in' => now()->addDays(3)->toDateString(), 'check_out' => now()->addDays(5)->toDateString()];

    $this->postJson($url, [...$stay, 'rooms' => [['category_id' => $category->id, 'meal_plan' => 'EP', 'adults' => 1]]])
        ->assertOk()
        ->assertJsonPath('quote.room_total', 2000)
        ->assertJsonPath('quote.tax_lines.0.label', 'GST')
        ->assertJsonPath('quote.taxes', 240)
        ->assertJsonPath('quote.grand_total', 2240)
        ->assertJsonPath('quote.pay_now', 2240);

    $this->postJson($url, [...$stay, 'rooms' => [['category_id' => $category->id, 'meal_plan' => 'MAP', 'adults' => 1]]])
        ->assertJsonValidationErrors('rooms.0.meal_plan');
    $this->postJson($url, [...$stay, 'rooms' => [['category_id' => $category->id, 'adults' => 3]]])
        ->assertJsonValidationErrors('rooms.0.adults');
    $this->postJson($url, [...$stay, 'rooms' => [['category_id' => $category->id], ['category_id' => $category->id]]])
        ->assertJsonValidationErrors('rooms.1.category_id');
    $this->postJson($url, [...$stay, 'rooms' => [['category_id' => $unpriced->id]]])
        ->assertJsonValidationErrors('rooms.0.category_id');
    $this->postJson($url, [...$stay, 'payment_type' => 'partial', 'rooms' => [['category_id' => $category->id]]])
        ->assertJsonValidationErrors('payment_type');
});

test('test payment gateway is enabled only when configured via env', function () {
    $property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $property->id]);
    $room = $category->rooms()->create(['name' => 'Room 103', 'display_order' => 1]);
    sellOnBookingEngine($property, $category, 150);

    $payload = [
        'payment_gateway' => 'test',
        'payment_type' => 'full',
        'pay_now_amount' => 300.0,
        'total_amount' => 300.0,
        'consent' => ['terms' => true], 'guest' => [
            'name' => 'Test User',
            'phone' => '+91 9999999999',
            'email' => 'test@example.com',
        ],
        'check_in' => '2027-04-01',
        'check_out' => '2027-04-03',
        'rooms' => [
            [
                'category_id' => $category->id,
                'room_id' => $category->id,
                'meal_plan' => 'EP',
                'nightly_price' => 150.0,
                'adults' => 1,
                'children' => 0,
                'infants' => 0,
            ],
        ],
    ];

    // Case 1: When disabled
    config(['services.test_gateway.enabled' => false]);

    $respPageDisabled = $this->get("/properties/{$property->ulid}/booking-engine/web");
    $respPageDisabled->assertOk();
    expect($respPageDisabled->viewData('page')['props']['testGatewayEnabled'])->toBeFalse();

    $respDisabled = $this->postJson("/properties/{$property->ulid}/booking-engine/checkout/initiate", $payload);
    $respDisabled->assertUnprocessable();
    $respDisabled->assertJsonValidationErrors(['payment_gateway']);

    // Case 2: When enabled
    config(['services.test_gateway.enabled' => true]);

    $respPageEnabled = $this->get("/properties/{$property->ulid}/booking-engine/web");
    $respPageEnabled->assertOk();
    expect($respPageEnabled->viewData('page')['props']['testGatewayEnabled'])->toBeTrue();

    $respEnabled = $this->postJson("/properties/{$property->ulid}/booking-engine/checkout/initiate", $payload);
    $respEnabled->assertOk();
    expect($respEnabled->json('success'))->toBeTrue();
    expect($respEnabled->json('gateway'))->toBe('test');
    expect($respEnabled->json('is_test_mode'))->toBeTrue();

    // Confirm with test gateway
    $confirmPayload = [
        'booking_ref_id' => $respEnabled->json('booking_ref_id'),
        'payment_gateway' => 'test',
        'transaction_id' => 'TXN_TEST_999',
        'order_id' => $respEnabled->json('order_id'),
        'payment_type' => 'full',
        'pay_now_amount' => 300.0,
        'total_amount' => 300.0,
        'consent' => ['terms' => true], 'guest' => [
            'name' => 'Test User',
            'phone' => '+91 9999999999',
            'email' => 'test@example.com',
        ],
        'check_in' => '2027-04-01',
        'check_out' => '2027-04-03',
        'rooms' => [
            [
                'category_id' => $category->id,
                'room_id' => $category->id,
                'meal_plan' => 'EP',
                'nightly_price' => 150.0,
                'adults' => 1,
                'children' => 0,
                'infants' => 0,
            ],
        ],
    ];

    $confirmResp = $this->postJson("/properties/{$property->ulid}/booking-engine/checkout/confirm", $confirmPayload);
    $confirmResp->assertOk();
    expect($confirmResp->json('success'))->toBeTrue();
    expect($confirmResp->json('booking.gateway'))->toBe('test');
    expect($confirmResp->json('booking.transaction_id'))->toBe('TXN_TEST_999');

    $this->assertDatabaseHas('bookings', [
        'booking_ref_id' => $respEnabled->json('booking_ref_id'),
        'status' => 'confirmed',
        'total' => 300.0,
    ]);
});

test('the booking engine rejects a check-in date in the past at checkout', function () {
    $property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $property->id, 'meal_plans' => ['EP']]);

    $payload = [
        'payment_gateway' => 'razorpay',
        'payment_type' => 'full',
        'pay_now_amount' => 100,
        'total_amount' => 100,
        'consent' => ['terms' => true], 'guest' => ['name' => 'Jane Smith', 'phone' => '+91 9123456780', 'email' => 'jane@example.com'],
        'check_in' => now()->subDay()->toDateString(),
        'check_out' => now()->addDay()->toDateString(),
        'rooms' => [['category_id' => $category->id, 'meal_plan' => 'EP', 'nightly_price' => 50, 'adults' => 1]],
    ];

    $this->postJson("/properties/{$property->ulid}/booking-engine/checkout/initiate", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['check_in' => 'The check-in date cannot be in the past.']);

    $this->postJson("/properties/{$property->ulid}/booking-engine/quote", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('check_in');
});

test('the booking engine page ignores past dates in the link and falls back to the default search', function () {
    $property = Property::factory()->create();

    $this->get("/properties/{$property->ulid}/booking-engine/web?check_in=".now()->subDays(3)->toDateString().'&check_out='.now()->subDay()->toDateString())
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('search.check_in', fn ($checkIn): bool => $checkIn >= now()->toDateString()));
});

test('the booking engine page carries the property branding', function () {
    $property = Property::factory()->create();
    RoomCategory::factory()->create(['property_id' => $property->id, 'meal_plans' => []]);
    app(PropertyBranding::class)->save($property, [
        'accommodation_nomenclature' => 'Cottage',
        'show_property_name' => false,
        'theme_color' => '#8b7355',
        'header_color' => '#2f4f4f',
        'text_color' => '#ffffff',
    ]);

    $this->get("/properties/{$property->ulid}/booking-engine/web")
        ->assertInertia(fn ($page) => $page
            ->where('property.branding.show_property_name', false)
            ->where('property.branding.theme_color', '#8b7355')
            ->where('property.branding.logo_url', null));
});

test('the confirmation names the guest who booked, with their phone and e-mail', function () {
    $property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $property->id, 'meal_plans' => ['EP']]);
    sellOnBookingEngine($property, $category, 250);

    $response = bookThroughEngine($this, $property, [
        'check_in' => '2027-03-01',
        'check_out' => '2027-03-03',
        'rooms' => [['category_id' => $category->id, 'meal_plan' => 'EP', 'adults' => 1]],
    ])->assertOk();

    expect($response->json('booking.guest_name'))->toBe('Asha Rao')
        ->and($response->json('booking.guest_phone'))->toBe('+91 9000000001')
        ->and($response->json('booking.guest_email'))->toBe('asha@example.com');

    // The booking's receipt is in the guest's name too.
    $this->assertDatabaseHas('payments', ['voucher_number' => $response->json('booking.booking_ref_id'), 'guest_name' => 'Asha Rao']);
});

test('a category with no rate is not sold online unless zero-rate bookings are allowed', function () {
    $property = Property::factory()->create();
    $priced = RoomCategory::factory()->create(['property_id' => $property->id, 'name' => 'Deluxe', 'meal_plans' => ['EP']]);
    $dorm = RoomCategory::factory()->create(['property_id' => $property->id, 'name' => 'Dormitory', 'meal_plans' => ['EP']]);
    sellOnBookingEngine($property, $priced, 1000);
    sellOnBookingEngine($property, $dorm, 0);

    $stay = ['check_in' => now()->addDays(3)->toDateString(), 'check_out' => now()->addDays(5)->toDateString()];
    $page = $this->get("/properties/{$property->ulid}/booking-engine/web?".http_build_query($stay))->assertOk();
    $rows = collect($page->viewData('page')['props']['roomCategories']);

    expect($rows->firstWhere('id', $dorm->id)['no_rate'])->toBeTrue()
        ->and($rows->firstWhere('id', $dorm->id)['available'])->toBe(0)
        ->and($rows->firstWhere('id', $dorm->id)['meal_plan_options'])->toBe([])
        ->and($rows->firstWhere('id', $priced->id)['no_rate'])->toBeFalse()
        ->and($rows->firstWhere('id', $priced->id)['available'])->toBe(1);

    $url = "/properties/{$property->ulid}/booking-engine/quote";
    $this->postJson($url, [...$stay, 'rooms' => [['category_id' => $dorm->id, 'meal_plan' => 'EP', 'adults' => 1]]])
        ->assertJsonValidationErrors('rooms.0.category_id');

    // Booking Settings → Reservation → "Allow Zero-Rate Bookings".
    $field = MetaField::query()->firstOrCreate(['key' => 'booking_settings_reservation'], ['key_label' => 'Reservation', 'status' => true]);
    $property->metaFieldValues()->create(['meta_fields_key_id' => $field->id, 'enabled' => true, 'data' => json_encode(['allow_zero_rate_bookings' => true])]);
    app(PropertySettings::class)->forgetMetaFields();
    app(PropertySettings::class)->forget($property);

    $this->postJson($url, [...$stay, 'rooms' => [['category_id' => $dorm->id, 'meal_plan' => 'EP', 'adults' => 1]]])
        ->assertOk()
        ->assertJsonPath('quote.room_total', 0);

    $rows = collect($this->get("/properties/{$property->ulid}/booking-engine/web?".http_build_query($stay))->viewData('page')['props']['roomCategories']);
    expect($rows->firstWhere('id', $dorm->id)['no_rate'])->toBeFalse()
        ->and($rows->firstWhere('id', $dorm->id)['available'])->toBe(1);
});

test('the confirmation carries the web check-in link once the property offers web check-in', function () {
    $property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $property->id, 'meal_plans' => ['EP']]);
    sellOnBookingEngine($property, $category, 250);
    // Each booking takes the only room, so they stay on different dates.
    $book = fn (string $checkIn, string $checkOut) => bookThroughEngine($this, $property, [
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'rooms' => [['category_id' => $category->id, 'meal_plan' => 'EP', 'adults' => 1]],
    ])->assertOk();

    expect($book('2027-03-01', '2027-03-03')->json('booking.web_checkin_url'))->toBeNull();

    app(WebCheckins::class)->saveSettings($property, ['enabled' => true]);
    $response = $book('2027-03-10', '2027-03-12');
    $checkin = WebCheckin::query()->where('booking_id', $response->json('booking.id'))->sole();

    expect($response->json('booking.web_checkin_url'))->toBe(route('web-checkin.show', $checkin->token));
});
