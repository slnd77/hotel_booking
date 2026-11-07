<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\RoomInventory;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create();
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    $this->rooms = Room::factory()->count(2)->create(['room_category_id' => $this->category->id]);

    enableEmailNotifications();

    $this->checkIn = now()->addDays(3)->toDateString();
    $this->checkOut = now()->addDays(4)->toDateString();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function enquiryTestPayload(RoomCategory $category, string $checkIn, string $checkOut, int $rooms = 1, array $overrides = []): array
{
    return [
        'booking_ref_id' => '',
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => $checkIn,
        'check_out' => $checkOut,
        'status' => 'enquiry',
        'hold_release_at' => now()->addDay()->setTime(12, 0)->format('Y-m-d\TH:i'),
        'hold_reminders' => [['channel' => 'email', 'days' => 0, 'relative_to' => 'hold_release_date']],
        'discount' => 0,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
        'rooms' => array_fill(0, $rooms, ['room_category_id' => $category->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]),
        ...$overrides,
    ];
}

test('log enquiry opens the booking form in enquiry mode', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.bookings.create', ['property' => $this->property, 'enquiry' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('isEnquiry', true)->where('isHold', false));
});

test('an enquiry is saved with its release time and takes no room', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), enquiryTestPayload($this->category, $this->checkIn, $this->checkOut, rooms: 2))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.databases.enquiries.index', ['property' => $this->property->id]));

    $enquiry = Booking::sole();

    expect($enquiry->status)->toBe(BookingStatus::Enquiry)
        ->and($enquiry->hold_release_at)->not->toBeNull()
        ->and($enquiry->rooms->pluck('room_id')->filter())->toBeEmpty();

    $availability = app(RoomInventory::class)->availabilityByCategory($this->property->id, collect([$this->category->id => 2]), $this->checkIn, $this->checkOut);
    expect($availability[$this->category->id])->toBe(2);

    // Both rooms can still be booked for the same night.
    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), enquiryTestPayload($this->category, $this->checkIn, $this->checkOut, rooms: 2, overrides: ['status' => 'confirmed']))
        ->assertSessionHasNoErrors();
});

test('reminders can only use notification channels that are switched on and configured', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.bookings.create', ['property' => $this->property, 'enquiry' => 1]))
        ->assertInertia(fn ($page) => $page->where('reminderChannels', [['value' => 'email', 'label' => 'E-mail']]));

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), enquiryTestPayload($this->category, $this->checkIn, $this->checkOut, overrides: [
            'hold_reminders' => [['channel' => 'sms', 'days' => 0, 'relative_to' => 'hold_release_date']],
        ]))
        ->assertSessionHasErrors('hold_reminders.0.channel');

    $this->actingAs($this->admin)
        ->put(route('admin.applicationSettings.notificationChannels.status', 'email'), ['enabled' => false]);

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.create', ['property' => $this->property, 'enquiry' => 1]))
        ->assertInertia(fn ($page) => $page->where('reminderChannels', []));

    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), enquiryTestPayload($this->category, $this->checkIn, $this->checkOut))
        ->assertSessionHasErrors('hold_reminders.0.channel');
});

test('an enquiry needs a release time in the future', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.bookings.store', $this->property), enquiryTestPayload($this->category, $this->checkIn, $this->checkOut, overrides: ['hold_release_at' => now()->subHour()->format('Y-m-d\TH:i')]))
        ->assertSessionHasErrors('hold_release_at');

    expect(Booking::count())->toBe(0);
});

test('enquiries past their release time become expired quotes', function () {
    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), enquiryTestPayload($this->category, $this->checkIn, $this->checkOut));
    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), enquiryTestPayload($this->category, $this->checkIn, $this->checkOut, overrides: ['hold_release_at' => now()->addDays(5)->format('Y-m-d\TH:i')]));

    [$overdue, $open] = Booking::query()->orderBy('hold_release_at')->get()->all();

    $this->travelTo(now()->addDays(2));
    $this->artisan('bookings:release-expired-holds')->assertSuccessful();

    expect($overdue->fresh()->status)->toBe(BookingStatus::ExpiredQuote)
        ->and($open->fresh()->status)->toBe(BookingStatus::Enquiry);
});

test('enquiries are listed on the enquiries page and not in the bookings table', function () {
    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), enquiryTestPayload($this->category, $this->checkIn, $this->checkOut));
    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), enquiryTestPayload($this->category, $this->checkIn, $this->checkOut, overrides: ['status' => 'confirmed']));

    $enquiry = Booking::query()->where('status', BookingStatus::Enquiry)->sole();
    $confirmed = Booking::query()->where('status', BookingStatus::Confirmed)->sole();

    $this->actingAs($this->admin)
        ->get(route('admin.databases.enquiries.index', ['property' => $this->property->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/databases/Enquiries')
            ->has('enquiries.data', 1)
            ->where('enquiries.data.0.id', $enquiry->id));

    $this->actingAs($this->admin)
        ->get(route('admin.databases.bookings.index', ['property' => $this->property->id]))
        ->assertInertia(fn ($page) => $page
            ->has('bookings.data', 1)
            ->where('bookings.data.0.id', $confirmed->id));
});
