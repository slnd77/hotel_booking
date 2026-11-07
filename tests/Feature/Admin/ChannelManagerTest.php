<?php

use App\Enums\BookingStatus;
use App\Jobs\PullChannexBookings;
use App\Jobs\PushChannexAri;
use App\Models\Booking;
use App\Models\BookingChannel;
use App\Models\ChannexConnection;
use App\Models\Currency;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\Channex\ChannexAri;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

const CHANNEX = 'https://staging.channex.io/api/v1';

beforeEach(function () {
    config(['services.channex.api_key' => 'test-key', 'services.channex.base_url' => CHANNEX]);

    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create(['timezone' => 'UTC']);
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id, 'name' => 'Deluxe', 'max_adults' => 2, 'meal_plans' => ['EP', 'CP']]);
    Room::factory()->count(2)->create(['room_category_id' => $this->category->id]);
    // Only this category: the property factory may add its own.
    RoomCategory::query()->where('property_id', $this->property->id)->whereKeyNot($this->category->id)->delete();

    $this->today = CarbonImmutable::parse($this->property->today());
});

function connectedProperty(object $test): ChannexConnection
{
    $connection = ChannexConnection::factory()->create(['property_id' => $test->property->id, 'channex_property_id' => 'aaaaaaaa-0000-0000-0000-000000000001']);
    $connection->roomMappings()->create(['room_category_id' => $test->category->id, 'channex_room_type_id' => 'bbbbbbbb-0000-0000-0000-000000000001']);
    $connection->rateMappings()->create(['room_category_id' => $test->category->id, 'meal_plan' => 'CP', 'adults' => 2, 'channex_rate_plan_id' => 'cccccccc-0000-0000-0000-000000000001']);

    return $connection;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function channexRevision(object $test, array $overrides = []): array
{
    $arrival = $test->today->addDays(3)->toDateString();
    $departure = $test->today->addDays(5)->toDateString();

    return [
        'id' => $overrides['revision_id'] ?? 'dddddddd-0000-0000-0000-000000000001',
        'type' => 'booking_revision',
        'attributes' => array_replace([
            'booking_id' => 'eeeeeeee-0000-0000-0000-000000000001',
            'status' => 'new',
            'ota_name' => 'Booking.com',
            'ota_reservation_code' => 'BDC-123456',
            'arrival_date' => $arrival,
            'departure_date' => $departure,
            'currency' => 'USD',
            'amount' => '250.00',
            'payment_collect' => 'ota',
            'notes' => 'Late arrival',
            'customer' => ['name' => 'Asha', 'surname' => 'Rao', 'mail' => 'asha@example.com', 'phone' => '+919800000001'],
            'rooms' => [[
                'room_type_id' => 'bbbbbbbb-0000-0000-0000-000000000001',
                'rate_plan_id' => 'cccccccc-0000-0000-0000-000000000001',
                'checkin_date' => $arrival,
                'checkout_date' => $departure,
                'amount' => '250.00',
                'occupancy' => ['adults' => 2, 'children' => 0, 'infants' => 0],
            ]],
        ], array_diff_key($overrides, ['revision_id' => true])),
    ];
}

/**
 * @param  list<array<string, mixed>>  $feed
 */
function fakeChannexFeed(array $feed): void
{
    // Stubs stack (the first match wins), so every stub reads the latest feed.
    app()->instance('test.channex.feed', $feed);

    Http::fake([
        CHANNEX.'/booking_revisions/feed*' => fn () => Http::response(['data' => app('test.channex.feed')]),
        CHANNEX.'/booking_revisions/*/ack' => Http::response(['meta' => ['message' => 'Success']]),
        '*' => Http::response([]),
    ]);
}

test('a property can be connected to an existing Channex property', function () {
    Http::fake([CHANNEX.'/properties*' => Http::response(['data' => [['id' => 'aaaaaaaa-0000-0000-0000-000000000009', 'attributes' => ['title' => 'Demo', 'currency' => 'USD']]]])]);

    $this->actingAs($this->admin)
        ->get(route('admin.channelManager.edit', $this->property))
        ->assertInertia(fn ($page) => $page->component('admin/channel-manager/Edit')->where('connection', null)->where('remote.properties.0.title', 'Demo'));

    $this->actingAs($this->admin)
        ->post(route('admin.channelManager.connect', $this->property), ['mode' => 'existing', 'channex_property_id' => 'aaaaaaaa-0000-0000-0000-000000000009'])
        ->assertSessionHas('success');

    $connection = ChannexConnection::query()->where('property_id', $this->property->id)->sole();

    // A local (.test) site gets no webhook: bookings are pulled on schedule instead.
    expect($connection->channex_property_id)->toBe('aaaaaaaa-0000-0000-0000-000000000009')
        ->and($connection->channex_webhook_id)->toBeNull();
    Http::assertSent(fn (Request $request) => $request->hasHeader('user-api-key', 'test-key'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/webhooks'));
});

test('missing room types and rate plans are created in Channex and mapped', function () {
    Queue::fake();
    $connection = ChannexConnection::factory()->create(['property_id' => $this->property->id]);
    Http::fake([
        CHANNEX.'/room_types' => Http::response(['data' => ['id' => 'bbbbbbbb-0000-0000-0000-000000000002']], 201),
        CHANNEX.'/rate_plans' => Http::sequence()
            ->push(['data' => ['id' => 'cccccccc-0000-0000-0000-000000000002']], 201)
            ->push(['data' => ['id' => 'cccccccc-0000-0000-0000-000000000003']], 201),
    ]);

    $this->actingAs($this->admin)
        ->post(route('admin.channelManager.createRoomsAndRates', $this->property))
        ->assertSessionHas('success', 'Created 1 room type(s) and 2 rate plan(s) in Channex, and mapped them.');

    expect($connection->roomMappings()->value('channex_room_type_id'))->toBe('bbbbbbbb-0000-0000-0000-000000000002')
        ->and($connection->rateMappings()->orderBy('id')->pluck('meal_plan')->all())->toBe(['EP', 'CP']);
    Http::assertSent(fn (Request $request) => $request->url() === CHANNEX.'/room_types' && $request['room_type']['count_of_rooms'] === 2 && $request['room_type']['title'] === 'Deluxe');
    Queue::assertPushed(PushChannexAri::class);
});

test('availability is pushed night by night as date ranges, less booked and blocked rooms', function () {
    $connection = connectedProperty($this);
    $booking = $this->property->bookings()->create([
        'booking_ref_id' => 'LOCAL-1', 'source' => 'direct', 'type' => 'walk_in', 'status' => BookingStatus::Confirmed,
        'check_in' => $this->today->addDay()->toDateString(), 'check_out' => $this->today->addDays(3)->toDateString(),
        'room_total' => 0, 'discount' => 0, 'taxes' => 0, 'total' => 0, 'outstanding' => 0, 'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
    ]);
    $booking->rooms()->create(['room_category_id' => $this->category->id, 'meal_plan' => 'EP', 'adults' => 1, 'rate_per_night' => 0]);

    $values = app(ChannexAri::class)->availabilityValues($connection, $this->today, $this->today->addDays(4));

    expect(collect($values)->map(fn (array $value): array => [$value['date_from'], $value['date_to'], $value['availability']])->all())->toBe([
        [$this->today->toDateString(), $this->today->toDateString(), 2],
        [$this->today->addDay()->toDateString(), $this->today->addDays(2)->toDateString(), 1],
        [$this->today->addDays(3)->toDateString(), $this->today->addDays(4)->toDateString(), 2],
    ])->and($values[0]['room_type_id'])->toBe('bbbbbbbb-0000-0000-0000-000000000001');
});

test('OTA rates from Rate Setup are pushed per mapped rate plan, falling back to direct', function () {
    $connection = connectedProperty($this);
    $ratePlans = MetaField::query()->firstOrCreate(['key' => 'rate_settings_rate_plan'], ['key_label' => 'Rate plan', 'status' => true]);
    $direct = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 100);
    $ota = array_fill_keys(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 120);
    $this->property->metaFieldValues()->updateOrCreate(['meta_fields_key_id' => $ratePlans->id], ['enabled' => true, 'data' => json_encode([
        'plans' => [['id' => 'bar', 'mapping_status' => 'default', 'active' => true, 'rates' => [
            'direct' => ["{$this->category->id}:CP:Double" => $direct, "{$this->category->id}:EP:Double" => $direct],
            'ota' => ["{$this->category->id}:CP:Double" => $ota],
        ]]],
    ])]);

    $values = app(ChannexAri::class)->rateValues($connection, $this->today, $this->today->addDays(6));

    expect($values)->toHaveCount(1)
        ->and($values[0])->toMatchArray(['rate_plan_id' => 'cccccccc-0000-0000-0000-000000000001', 'date_from' => $this->today->toDateString(), 'date_to' => $this->today->addDays(6)->toDateString(), 'rate' => '120.00']);
});

test('a new OTA booking is created from the Channex feed and acknowledged', function () {
    $connection = connectedProperty($this);
    fakeChannexFeed([channexRevision($this)]);

    $this->actingAs($this->admin)
        ->post(route('admin.channelManager.pull', $this->property))
        ->assertSessionHas('success', '1 booking revision(s) applied.');

    $booking = Booking::query()->where('booking_ref_id', 'BDC-123456')->sole();
    $room = $booking->rooms()->sole();

    expect($booking)
        ->source->toBe(Booking::SOURCE_OTA)
        ->status->toBe(BookingStatus::Confirmed)
        ->and((float) $booking->total)->toEqual(250.0)
        ->and($booking->check_in->toDateString())->toBe($this->today->addDays(3)->toDateString())
        ->and($booking->booking_channel_id)->toBe(BookingChannel::query()->where('name', 'Booking.com')->value('id'))
        ->and($booking->guests()->sole()->first_name)->toBe('Asha')
        ->and($room)->room_category_id->toBe($this->category->id)->meal_plan->toBe('CP')->adults->toBe(2)
        ->and((float) $room->rate_per_night)->toEqual(125.0)
        ->and($room->room_id)->not->toBeNull()
        ->and($connection->revisions()->sole()->processed_at)->not->toBeNull();

    Http::assertSent(fn (Request $request) => $request->url() === CHANNEX.'/booking_revisions/dddddddd-0000-0000-0000-000000000001/ack');
});

test('modified and cancelled revisions update and cancel the same booking', function () {
    $connection = connectedProperty($this);
    fakeChannexFeed([channexRevision($this)]);
    $this->actingAs($this->admin)->post(route('admin.channelManager.pull', $this->property));
    $booking = Booking::query()->where('booking_ref_id', 'BDC-123456')->sole();

    $newDeparture = $this->today->addDays(6)->toDateString();
    $modified = channexRevision($this, ['revision_id' => 'dddddddd-0000-0000-0000-000000000002', 'status' => 'modified', 'departure_date' => $newDeparture, 'amount' => '375.00']);
    $modified['attributes']['rooms'][0]['checkout_date'] = $newDeparture;
    $modified['attributes']['rooms'][0]['amount'] = '375.00';
    fakeChannexFeed([$modified]);
    $this->actingAs($this->admin)->post(route('admin.channelManager.pull', $this->property));

    expect(Booking::query()->count())->toBe(1)
        ->and($booking->fresh()->check_out->toDateString())->toBe($newDeparture)
        ->and((float) $booking->fresh()->total)->toEqual(375.0)
        ->and($booking->rooms()->sole()->check_out_date->toDateString())->toBe($newDeparture);

    fakeChannexFeed([channexRevision($this, ['revision_id' => 'dddddddd-0000-0000-0000-000000000003', 'status' => 'cancelled'])]);
    $this->actingAs($this->admin)->post(route('admin.channelManager.pull', $this->property));

    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($connection->revisions()->whereNotNull('processed_at')->count())->toBe(3);
});

test('a revision for an unmapped room type is kept unacknowledged with the reason', function () {
    $connection = connectedProperty($this);
    $revision = channexRevision($this);
    $revision['attributes']['rooms'][0]['room_type_id'] = 'ffffffff-0000-0000-0000-000000000001';
    fakeChannexFeed([$revision]);

    $this->actingAs($this->admin)
        ->post(route('admin.channelManager.pull', $this->property))
        ->assertSessionHas('error');

    expect(Booking::query()->count())->toBe(0)
        ->and($connection->revisions()->sole())->processed_at->toBeNull()->error->toBe('Room type ffffffff-0000-0000-0000-000000000001 is not mapped to a room category.');
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/ack'));
});

test('the webhook queues a booking pull for its connection only', function () {
    Queue::fake();
    $connection = connectedProperty($this);

    $this->postJson(route('api.channex.webhook', ['token' => $connection->webhook_token]))->assertOk();
    $this->postJson(route('api.channex.webhook', ['token' => 'wrong']))->assertNotFound();

    Queue::assertPushed(PullChannexBookings::class, 1);
    Queue::assertPushed(PullChannexBookings::class, fn (PullChannexBookings $job): bool => $job->connectionId === $connection->id);
});

test('a booking change queues an availability push for the nights it touches', function () {
    $connection = connectedProperty($this);
    Queue::fake();

    $this->property->bookings()->create([
        'booking_ref_id' => 'LOCAL-2', 'source' => 'direct', 'type' => 'walk_in', 'status' => BookingStatus::Confirmed,
        'check_in' => $this->today->addDays(2)->toDateString(), 'check_out' => $this->today->addDays(4)->toDateString(),
        'room_total' => 0, 'discount' => 0, 'taxes' => 0, 'total' => 0, 'outstanding' => 0, 'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
    ]);

    Queue::assertPushed(PushChannexAri::class, fn (PushChannexAri $job): bool => $job->connectionId === $connection->id
        && $job->from === $this->today->addDays(2)->toDateString()
        && $job->to === $this->today->addDays(3)->toDateString()
        && $job->availability && ! $job->rates);
});

test('the channel manager needs its permission', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.channelManager.edit', $this->property))
        ->assertForbidden();
});
