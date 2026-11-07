<?php

use App\Enums\BookingStatus;
use App\Models\ApplicationSettingLog;
use App\Models\BlockReason;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Guest;
use App\Models\Language;
use App\Models\Property;
use App\Models\User;
use App\Services\ApplicationSettings;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->property = Property::factory()->create();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function guestSettingsPayload(array $overrides = []): array
{
    return [
        'enable_tracker_number' => false,
        'tracker_prefix' => '',
        'tracker_start_number' => 1,
        'mandatory' => ['first_name' => true, 'last_name' => false, 'phone' => true, 'email' => false, 'phone_or_email' => false, 'update_with_document' => false],
        'default_nationality' => '',
        ...$overrides,
    ];
}

test('application settings page shows every tab with defaults', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.applicationSettings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/application-settings/Edit')
            ->where('blockReasons.0.name', 'Booking')
            ->where('blockReasons.1.name', 'Maintenance')
            ->where('guest.mandatory.phone', true)
            ->where('dayPosting.transaction_posting_time', '00:00')
            ->has('reservation.subtypes', 4));
});

test('block reasons can be added, renamed and reordered, and are logged', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.applicationSettings.blockReasons.store'), ['name' => 'Renovation'])
        ->assertSessionHasNoErrors();

    $renovation = BlockReason::query()->where('name', 'Renovation')->sole();

    $this->actingAs($this->admin)
        ->post(route('admin.applicationSettings.blockReasons.store'), ['name' => 'booking'])
        ->assertSessionHasErrors('name');

    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.blockReasons.update', $renovation), ['name' => 'Deep Cleaning'])
        ->assertSessionHasNoErrors();

    $ids = BlockReason::query()->ordered()->pluck('id')->reverse()->values()->all();

    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.blockReasons.reorder'), ['ids' => $ids])
        ->assertSessionHasNoErrors();

    expect(BlockReason::query()->ordered()->pluck('name')->all())->toBe(['Deep Cleaning', 'Maintenance', 'Booking'])
        ->and(ApplicationSettingLog::query()->where('section', ApplicationSettings::BLOCK_UNBLOCK)->count())->toBe(3);
});

test('guest mandatory fields are enforced when creating a guest', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.update', 'guest'), guestSettingsPayload([
            'mandatory' => ['first_name' => false, 'last_name' => true, 'phone' => false, 'email' => false, 'phone_or_email' => true, 'update_with_document' => false],
        ]))
        ->assertSessionHasNoErrors();

    expect(app(ApplicationSettings::class)->get(ApplicationSettings::GUEST)['mandatory']['first_name'])->toBeTrue();

    $this->actingAs($this->admin)
        ->postJson(route('admin.guests.store', $this->property), ['first_name' => 'Asha'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['last_name', 'phone']);

    $this->actingAs($this->admin)
        ->postJson(route('admin.guests.store', $this->property), ['first_name' => 'Asha', 'last_name' => 'Rao', 'emails' => ['asha@example.com']])
        ->assertOk();
});

test('new guests get tracker numbers while the tracker is enabled', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.update', 'guest'), guestSettingsPayload([
            'enable_tracker_number' => true,
            'tracker_prefix' => 'GST-',
            'tracker_start_number' => 100,
        ]))
        ->assertSessionHasNoErrors();

    $first = Guest::create(['property_id' => $this->property->id, 'first_name' => 'One']);
    $second = Guest::create(['property_id' => $this->property->id, 'first_name' => 'Two']);

    expect($first->tracker_number)->toBe('GST-100')
        ->and($second->tracker_number)->toBe('GST-101');
});

test('the default nationality is shared with admin pages', function () {
    Country::query()->firstOrCreate(['name' => 'India'], ['iso2' => 'IN', 'iso3' => 'IND', 'phonecode' => '91']);

    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.update', 'guest'), guestSettingsPayload(['default_nationality' => 'India']))
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->get(route('admin.applicationSettings.edit'))
        ->assertInertia(fn ($page) => $page->where('guestSettings.default_nationality', 'India'));
});

test('check-in is refused until the required guest details are filled', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.update', 'reservation'), [
            'check_in' => ['guest_nationality' => true, 'guest_phone' => false, 'guest_email' => false, 'alphanumeric_guest_name' => false, 'guest_document' => false, 'guest_address' => false],
            'check_out' => ['guest_nationality' => false, 'guest_dob' => false, 'guest_purpose_of_visit' => false, 'guest_gender' => false],
            'subtypes' => [['value' => 'walk_in', 'label' => 'Walk-in', 'active' => true]],
        ])
        ->assertSessionHasNoErrors();

    $booking = $this->property->bookings()->create([
        'booking_ref_id' => 'CHECKIN1',
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => now()->toDateString(),
        'check_out' => now()->addDay()->toDateString(),
        'status' => BookingStatus::Confirmed,
        'room_total' => 1000,
        'taxes' => 0,
        'total' => 1000,
        'outstanding' => 0,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
    ]);
    $guest = Guest::create(['property_id' => $this->property->id, 'first_name' => 'Asha', 'last_name' => 'Rao']);
    $booking->guests()->attach($guest);

    $this->actingAs($this->admin)
        ->patch(route('admin.bookings.updateStatus', [$this->property, $booking]), ['status' => 'checked_in'])
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'Asha Rao is missing: Nationality'));

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);

    $guest->update(['nationality' => 'India']);

    $this->actingAs($this->admin)
        ->patch(route('admin.bookings.updateStatus', [$this->property, $booking]), ['status' => 'checked_in'])
        ->assertSessionHasNoErrors();

    expect($booking->fresh()->status)->toBe(BookingStatus::CheckedIn);
});

test('booking type options follow the configurable subtypes', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.update', 'reservation'), [
            'check_in' => ['guest_nationality' => false],
            'check_out' => ['guest_gender' => false],
            'subtypes' => [
                ['value' => 'walk_in', 'label' => 'Walk-in', 'active' => true],
                ['value' => 'phone', 'label' => 'Phone', 'active' => false],
                ['value' => '', 'label' => 'Travel Desk', 'active' => true],
            ],
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.create', $this->property))
        ->assertInertia(fn ($page) => $page->where('typeOptions', [
            ['value' => 'walk_in', 'label' => 'Walk-in'],
            ['value' => 'travel_desk', 'label' => 'Travel Desk'],
        ]));
});

test('day posting and widget settings are saved', function () {
    $second = Property::factory()->create();

    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.update', 'day_posting'), ['transaction_posting_time' => '14:30'])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.update', 'widget'), ['property_order' => [$second->id, $this->property->id]])
        ->assertSessionHasNoErrors();

    $settings = app(ApplicationSettings::class);

    expect($settings->get(ApplicationSettings::DAY_POSTING)['transaction_posting_time'])->toBe('14:30')
        ->and($settings->get(ApplicationSettings::WIDGET)['property_order'])->toBe([$second->id, $this->property->id]);
});

test('default guest nationality offers property country, nil or a country', function () {
    expect(app(ApplicationSettings::class)->get(ApplicationSettings::GUEST)['default_nationality'])->toBe(ApplicationSettings::PROPERTY_COUNTRY);

    foreach ([ApplicationSettings::PROPERTY_COUNTRY, ''] as $choice) {
        $this->actingAs($this->admin)
            ->put(route('admin.applicationSettings.update', 'guest'), guestSettingsPayload(['default_nationality' => $choice]))
            ->assertSessionHasNoErrors();
    }

    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.update', 'guest'), guestSettingsPayload(['default_nationality' => 'Atlantis']))
        ->assertSessionHasErrors('default_nationality');
});

test('the widget order sets the order of the property dropdown and the default property', function () {
    $alpha = Property::factory()->create(['name' => 'Alpha Hotel']);
    $zulu = Property::factory()->create(['name' => 'Zulu Hotel']);
    $unplaced = Property::factory()->create(['name' => 'Beta Hotel']);

    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.update', 'widget'), ['property_order' => [$zulu->id, $alpha->id, $this->property->id]])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->get(route('admin.dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('adminProperties', fn ($properties): bool => collect($properties)->pluck('id')->take(3)->all() === [$zulu->id, $alpha->id, $this->property->id]
                && collect($properties)->last()['id'] === $unplaced->id)
            ->where('selectedPropertyId', $zulu->id));
});

test('the smart booking chart shows the configured number of days, between 5 and 20', function () {
    $chartDays = fn (): int => count($this->actingAs($this->admin)
        ->getJson(route('admin.smartBookingChartData', ['property_id' => $this->property->id]))
        ->json('dates'));

    expect($chartDays())->toBe(10);

    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.update', 'smart_chart'), ['days_to_show' => 14])
        ->assertSessionHasNoErrors();

    expect($chartDays())->toBe(14);

    foreach ([4, 21] as $outOfRange) {
        $this->actingAs($this->admin)
            ->put(route('admin.applicationSettings.update', 'smart_chart'), ['days_to_show' => $outOfRange])
            ->assertSessionHasErrors('days_to_show');
    }

    expect($chartDays())->toBe(14);
});

test('the smart booking chart auto-refreshes every 2 minutes unless another time between 1 and 60 is set', function () {
    $refreshMinutes = fn (): int => $this->actingAs($this->admin)
        ->getJson(route('admin.smartBookingChartData', ['property_id' => $this->property->id]))
        ->json('auto_refresh_minutes');

    expect($refreshMinutes())->toBe(2);

    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.update', 'smart_chart'), ['days_to_show' => 10, 'auto_refresh_minutes' => 5])
        ->assertSessionHasNoErrors();

    expect($refreshMinutes())->toBe(5);

    // Saving only the days keeps the refresh time.
    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.update', 'smart_chart'), ['days_to_show' => 12])
        ->assertSessionHasNoErrors();

    expect($refreshMinutes())->toBe(5);

    foreach ([0, 61] as $outOfRange) {
        $this->actingAs($this->admin)
            ->put(route('admin.applicationSettings.update', 'smart_chart'), ['days_to_show' => 10, 'auto_refresh_minutes' => $outOfRange])
            ->assertSessionHasErrors('auto_refresh_minutes');
    }

    $this->actingAs($this->admin)
        ->get(route('admin.applicationSettings.edit'))
        ->assertInertia(fn ($page) => $page->where('smartChart.auto_refresh_minutes', 5));
});
