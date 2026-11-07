<?php

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Admin\DashboardController;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Guest;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

test('admin can fetch smart booking chart data for default 10 days starting today', function () {
    $property = Property::factory()->create(['name' => 'Grand Palace']);
    $category = RoomCategory::factory()->create([
        'property_id' => $property->id,
        'name' => 'Standard Deluxe',
        'abbreviation' => 'SDTA',
    ]);
    $room1 = Room::factory()->create([
        'room_category_id' => $category->id,
        'name' => 'SDTA 1',
    ]);
    $room2 = Room::factory()->create([
        'room_category_id' => $category->id,
        'name' => 'SDTA 2',
    ]);

    // Create a booking for today
    $booking = Booking::create([
        'property_id' => $property->id,
        'booking_ref_id' => 'TESTREF1',
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => now()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
        'status' => BookingStatus::Confirmed,
        'room_total' => 2000,
        'taxes' => 200,
        'total' => 2200,
        'outstanding' => 0,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
    ]);

    $guest = Guest::create([
        'property_id' => $property->id,
        'first_name' => 'Joy',
        'last_name' => 'Biswas',
    ]);
    $booking->guests()->attach($guest->id);

    $booking->rooms()->create([
        'room_category_id' => $category->id,
        'room_id' => $room1->id,
        'meal_plan' => 'EP',
        'adults' => 2,
        'rate_per_night' => 1000,
    ]);

    $response = $this->actingAs($this->admin)->getJson(route('admin.smartBookingChartData', [
        'property_id' => $property->id,
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['dates'])->toHaveCount(10);
    expect($data['dates'][0]['date'])->toBe(now()->toDateString());
    expect($data['can_go_prev'])->toBeFalse();
    $categoryData = collect($data['categories'])->firstWhere('id', $category->id);
    expect($categoryData)->not->toBeNull();
    expect($categoryData['rooms'])->toHaveCount(2);

    // Verify room 1 has the booking with Joy Biswas
    $room1Data = collect($categoryData['rooms'])->firstWhere('id', $room1->id);
    expect($room1Data['bookings'])->toHaveCount(1);
    expect($room1Data['bookings'][0]['guest_name'])->toBe('Joy Biswas');
    expect($room1Data['bookings'][0]['booking_ref_id'])->toBe('TESTREF1');
});

test('smart booking chart can show past dates, marking them as past', function () {
    $property = Property::factory()->create();

    $pastDate = now()->subDays(5)->toDateString();
    $response = $this->actingAs($this->admin)->getJson(route('admin.smartBookingChartData', [
        'property_id' => $property->id,
        'start_date' => $pastDate,
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['dates'][0]['date'])->toBe($pastDate)
        ->and($data['dates'][0]['is_past'])->toBeTrue()
        ->and($data['dates'][5]['date'])->toBe(now()->toDateString())
        ->and($data['dates'][5]['is_past'])->toBeFalse()
        ->and($data['can_go_prev'])->toBeFalse();
});

test('smart booking chart can fetch next 10 days and allows going prev back to today', function () {
    $property = Property::factory()->create();

    // Request next 10 days (from today + 10)
    $nextDate = now()->addDays(10)->toDateString();
    $response = $this->actingAs($this->admin)->getJson(route('admin.smartBookingChartData', [
        'property_id' => $property->id,
        'start_date' => $nextDate,
    ]));

    $response->assertOk();
    $data = $response->json();

    expect($data['dates'])->toHaveCount(10);
    expect($data['dates'][0]['date'])->toBe($nextDate);
    expect($data['can_go_prev'])->toBeTrue();
});

test('clicking a booking on the chart loads its summary card data', function () {
    $property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $property->id, 'name' => 'Suite Room']);
    $room = Room::factory()->create(['room_category_id' => $category->id]);

    $booking = Booking::create([
        'property_id' => $property->id,
        'booking_ref_id' => 'SUMMARY1',
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(3)->toDateString(),
        'status' => BookingStatus::OnHold,
        'room_total' => 4000,
        'taxes' => 200,
        'total' => 4200,
        'outstanding' => 1200,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
    ]);
    $booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $room->id, 'meal_plan' => 'EP', 'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => 2000]);
    $booking->guests()->attach(Guest::create(['property_id' => $property->id, 'first_name' => 'Pratima', 'last_name' => 'Mandal', 'phone_country_code' => '+91', 'phone' => '9007114231'])->id);

    // 3,000 already received, so 1,200 is left (what's owed comes from the ledger).
    Payment::create(['property_id' => $property->id, 'booking_id' => $booking->id, 'type' => 'receipt', 'status' => PaymentStatus::Received, 'total_txn_amount' => 3000, 'tagged_amount' => 3000]);

    $this->actingAs($this->admin)
        ->getJson(route('admin.bookings.summary', [$property, $booking]))
        ->assertOk()
        ->assertJson([
            'booking_ref_id' => 'SUMMARY1',
            'status' => 'on_hold',
            'status_label' => 'On Hold',
            'nights' => 2,
            'room_lines' => ['1 - Suite Room - EP X 2'],
            'guest' => ['name' => 'Pratima Mandal', 'phone' => '+91 9007114231'],
            'total' => 4200,
            'outstanding' => 1200,
            'editable' => true,
        ]);

    $otherProperty = Property::factory()->create();
    $this->actingAs($this->admin)->getJson(route('admin.bookings.summary', [$otherProperty, $booking]))->assertNotFound();
});

test('the chart popover can check in, undo check-in and cancel a booking, getting JSON back', function () {
    $property = Property::factory()->create();
    $booking = Booking::create([
        'property_id' => $property->id,
        'booking_ref_id' => 'POPOVER1',
        'check_in' => now()->toDateString(),
        'check_out' => now()->addDay()->toDateString(),
        'status' => BookingStatus::Confirmed,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
    ]);
    $url = route('admin.bookings.updateStatus', [$property, $booking]);

    $this->actingAs($this->admin)->patchJson($url, ['status' => 'checked_in'])->assertOk()->assertJson(['message' => 'Booking status updated to Checked In.']);
    expect($booking->fresh()->status)->toBe(BookingStatus::CheckedIn);

    $this->actingAs($this->admin)->patchJson($url, ['status' => 'confirmed'])->assertOk();
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);

    $this->actingAs($this->admin)->patchJson($url, ['status' => 'cancelled'])->assertOk()->assertJson(['message' => 'Booking status updated to Cancelled.']);
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

test('chart days carry the weekend days and holidays set under Policies', function () {
    $property = Property::factory()->create();
    $field = MetaField::query()->firstOrCreate(['key' => 'weekend_holidays'], ['key_label' => 'Weekends', 'status' => true]);
    $today = CarbonImmutable::parse($property->today());
    $property->metaFieldValues()->create(['meta_fields_key_id' => $field->id, 'enabled' => true, 'data' => json_encode([
        'weekend_days' => [$today->dayOfWeekIso - 1],
        'holidays_enabled' => true,
        'holidays' => [
            // Saved before types and descriptions existed.
            ['name' => 'Diwali', 'type' => 'holiday', 'from' => $today->addDay()->toDateString(), 'to' => $today->addDays(2)->toDateString()],
            ['name' => 'Fest', 'type' => 'festival', 'description' => 'Town fair', 'from' => $today->addDays(4)->toDateString(), 'to' => $today->addDays(5)->toDateString(), 'day_wise' => true, 'days' => [
                ['date' => $today->addDays(4)->toDateString(), 'name' => 'Fest day 1', 'type' => 'festival', 'description' => 'Opening'],
                ['date' => $today->addDays(5)->toDateString(), 'name' => 'Fest finale', 'type' => 'event', 'description' => 'Fireworks'],
            ]],
        ],
    ])]);

    $dates = app(DashboardController::class)->getSmartChartData($property)['dates'];

    expect($dates[0])->is_weekend->toBeTrue()->holiday->toBeNull()
        ->and($dates[1])->is_weekend->toBeFalse()->holiday->toBe(['date' => $today->addDay()->toDateString(), 'name' => 'Diwali', 'type' => 'national_holiday', 'description' => ''])
        ->and($dates[2]['holiday']['name'])->toBe('Diwali')
        ->and($dates[3]['holiday'])->toBeNull()
        ->and($dates[4]['holiday'])->toMatchArray(['name' => 'Fest day 1', 'type' => 'festival', 'description' => 'Opening'])
        ->and($dates[5]['holiday'])->toMatchArray(['name' => 'Fest finale', 'type' => 'event', 'description' => 'Fireworks'])
        ->and($dates[7]['is_weekend'])->toBeTrue();
});

test('the booking form gets the holidays for its date picker', function () {
    $property = Property::factory()->create();
    $field = MetaField::query()->firstOrCreate(['key' => 'weekend_holidays'], ['key_label' => 'Weekends', 'status' => true]);
    $property->metaFieldValues()->create(['meta_fields_key_id' => $field->id, 'enabled' => true, 'data' => json_encode([
        'weekend_days' => [5, 6],
        'holidays_enabled' => true,
        'holidays' => [['name' => 'Holi', 'type' => 'festival', 'description' => 'Colours', 'from' => '2026-03-04', 'to' => '2026-03-04']],
    ])]);

    $this->actingAs($this->admin)->get(route('admin.bookings.create', $property))
        ->assertInertia(fn ($page) => $page
            ->has('holidays', 1)
            ->where('holidays.0.name', 'Holi')
            ->where('holidays.0.description', 'Colours')
            ->where('holidays.0.day_wise', false));
});

test('a holiday added in Holiday Details is saved at once and shows on the chart', function () {
    $property = Property::factory()->create();
    $today = CarbonImmutable::parse($property->today());
    $holiday = ['name' => 'Rath Yatra', 'type' => 'festival', 'description' => 'Chariot festival', 'from' => $today->addDay()->toDateString(), 'to' => $today->addDays(2)->toDateString(), 'day_wise' => false, 'days' => []];

    $this->actingAs($this->admin)
        ->put(route('admin.properties.holidays.update', $property), ['holidays' => [$holiday]])
        ->assertRedirect(route('admin.properties.edit', [$property, 'tab' => 'policies']));

    $dates = app(DashboardController::class)->getSmartChartData($property)['dates'];
    expect($dates[1]['holiday'])->toMatchArray(['name' => 'Rath Yatra', 'type' => 'festival', 'description' => 'Chariot festival'])
        ->and($dates[0]['is_weekend'])->toBe(in_array($today->dayOfWeekIso, [6, 7], true));

    $this->actingAs($this->admin)
        ->put(route('admin.properties.holidays.update', $property), ['holidays' => [$holiday, [...$holiday, 'name' => 'Clash', 'from' => $today->addDays(2)->toDateString()]]])
        ->assertSessionHasErrors('holidays');
    $this->actingAs($this->admin)
        ->put(route('admin.properties.holidays.update', $property), ['holidays' => [[...$holiday, 'type' => 'party']]])
        ->assertSessionHasErrors('holidays.0.type');
});
