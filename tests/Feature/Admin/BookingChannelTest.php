<?php

use App\Models\Booking;
use App\Models\BookingChannel;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\SupportedMetasearchPlatform;
use App\Models\User;
use Database\Seeders\BookingChannelSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);

    $this->property = Property::factory()->create();
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['room_category_id' => $this->category->id]);

    $this->seed(BookingChannelSeeder::class);
    $this->ota = BookingChannel::query()->where('name', '123booking')->sole();

    $this->tripAdvisor = SupportedMetasearchPlatform::query()->create(['name' => 'TripAdvisor', 'slug' => 'tripadvisor']);
    $this->kayak = SupportedMetasearchPlatform::query()->create(['name' => 'Kayak', 'slug' => 'kayak']);
    $this->trivago = SupportedMetasearchPlatform::query()->create(['name' => 'Trivago', 'slug' => 'trivago']);
});

function channelBookingPayload(RoomCategory $category, Room $room, array $overrides = []): array
{
    return [
        'booking_ref_id' => 'CH-1', 'source' => 'ota', 'type' => 'walk_in',
        'check_in' => now()->addDays(3)->toDateString(), 'check_out' => now()->addDays(4)->toDateString(),
        'status' => 'confirmed', 'discount' => 0, 'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        'rooms' => [['room_category_id' => $category->id, 'room_id' => $room->id, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
        ...$overrides,
    ];
}

/** Meta listings on, listed on TripAdvisor and Kayak (Trivago left blank). */
function listOnMetasearch(Property $property, SupportedMetasearchPlatform ...$platforms): void
{
    $property->update(['metasearch_engines_listings_enabled' => true]);
    $property->metasearchPlatforms()->sync(collect($platforms)->mapWithKeys(fn ($platform) => [$platform->id => ['listing_id' => 'L-'.$platform->id]])->all());
}

it('seeds the OTAs without duplicating them', function () {
    $count = BookingChannel::query()->count();
    $this->seed(BookingChannelSeeder::class);

    expect(BookingChannel::query()->count())->toBe($count)->and($count)->toBeGreaterThan(30);
});

it('offers Meta only for the engines the property is listed on', function () {
    $this->get(route('admin.bookings.create', $this->property))
        ->assertInertia(fn (Assert $page) => $page->where('otaChannels.0.name', '123booking')->has('metaPlatforms', 0));

    listOnMetasearch($this->property, $this->tripAdvisor, $this->kayak);

    $this->get(route('admin.bookings.create', $this->property))
        ->assertInertia(fn (Assert $page) => $page->where('metaPlatforms', [['id' => $this->kayak->id, 'name' => 'Kayak'], ['id' => $this->tripAdvisor->id, 'name' => 'TripAdvisor']]));

    // Listing IDs are kept, but switching listings off hides Meta.
    $this->property->update(['metasearch_engines_listings_enabled' => false]);

    $this->get(route('admin.bookings.create', $this->property))
        ->assertInertia(fn (Assert $page) => $page->has('metaPlatforms', 0));
});

it('requires and saves the OTA on an OTA booking', function () {
    $this->post(route('admin.bookings.store', $this->property), channelBookingPayload($this->category, $this->room))
        ->assertSessionHasErrors('booking_channel_id');

    $this->post(route('admin.bookings.store', $this->property), channelBookingPayload($this->category, $this->room, ['booking_channel_id' => $this->ota->id]))
        ->assertSessionHasNoErrors();

    expect(Booking::query()->where('booking_ref_id', 'CH-1')->sole()->booking_channel_id)->toBe($this->ota->id);
});

it('saves the Meta type only for a listed engine', function () {
    listOnMetasearch($this->property, $this->tripAdvisor);

    $this->post(route('admin.bookings.store', $this->property), channelBookingPayload($this->category, $this->room, ['source' => 'meta', 'metasearch_platform_id' => $this->trivago->id]))
        ->assertSessionHasErrors('metasearch_platform_id');

    $this->post(route('admin.bookings.store', $this->property), channelBookingPayload($this->category, $this->room, ['source' => 'meta', 'metasearch_platform_id' => $this->tripAdvisor->id]))
        ->assertSessionHasNoErrors();

    $booking = Booking::query()->where('booking_ref_id', 'CH-1')->sole();
    expect($booking->metasearch_platform_id)->toBe($this->tripAdvisor->id)->and($booking->booking_channel_id)->toBeNull();
});

it('refuses Meta when the property has no metasearch listings', function () {
    $this->post(route('admin.bookings.store', $this->property), channelBookingPayload($this->category, $this->room, ['source' => 'meta', 'metasearch_platform_id' => $this->tripAdvisor->id]))
        ->assertSessionHasErrors('metasearch_platform_id');
});

it('drops OTA and Meta picks on other sources', function () {
    listOnMetasearch($this->property, $this->tripAdvisor);

    $this->post(route('admin.bookings.store', $this->property), channelBookingPayload($this->category, $this->room, [
        'source' => 'booking_engine', 'booking_channel_id' => $this->ota->id, 'metasearch_platform_id' => $this->tripAdvisor->id,
    ]))->assertSessionHasNoErrors();

    $booking = Booking::query()->where('booking_ref_id', 'CH-1')->sole();
    expect($booking->booking_channel_id)->toBeNull()->and($booking->metasearch_platform_id)->toBeNull();
});
