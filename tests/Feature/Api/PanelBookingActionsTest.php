<?php

use App\Enums\SubBookingStatus;
use App\Models\Addon;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\ApiScopes;
use Spatie\Permission\Models\Role;

/*
 * The staff app's booking page: the bodies it sends for each action, through /api/v1/panel.
 */
beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->property = Property::factory()->create();
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id, 'meal_plans' => ['EP']]);
    Room::factory()->count(3)->create(['room_category_id' => $this->category->id]);
    $this->upgradeTo = RoomCategory::factory()->create(['property_id' => $this->property->id, 'meal_plans' => ['EP']]);
    Room::factory()->create(['room_category_id' => $this->upgradeTo->id]);

    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), [
        'booking_ref_id' => 'APP-1', 'source' => 'direct', 'type' => 'walk_in',
        'check_in' => now()->toDateString(), 'check_out' => now()->addDays(2)->toDateString(),
        'status' => 'confirmed', 'discount' => 0, 'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        'rooms' => array_fill(0, 2, ['room_category_id' => $this->category->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]),
    ])->assertSessionHasNoErrors();
    $this->booking = Booking::query()->where('booking_ref_id', 'APP-1')->sole();
    [$this->first, $this->second] = $this->booking->subBookings()->orderBy('id')->get()->all();

    app('auth')->forgetGuards();
    $this->withToken($this->admin->createToken(ApiScopes::DEVICE_PREFIX.'Pixel', ['*'])->plainTextToken);
    $this->base = "/api/v1/panel/properties/{$this->property->id}/bookings/{$this->booking->id}";
});

test('the booking page loads as JSON for the app', function () {
    $this->getJson($this->base)->assertOk()
        ->assertJsonPath('component', 'admin/bookings/Show')
        ->assertJsonPath('props.booking.booking_ref_id', 'APP-1')
        ->assertJsonStructure(['props' => ['folios', 'folioPayers', 'addonCatalogue', 'webCheckin', 'guestLedger', 'housekeepingCheckOutDefault']]);
});

test('rooms check in one by one and together', function () {
    $this->patchJson("{$this->base}/rooms/{$this->first->id}/stay", ['status' => 'checked_in'])->assertOk();
    expect($this->first->fresh()->status)->toBe(SubBookingStatus::CheckedIn);

    $this->postJson("{$this->base}/rooms/check-in", ['sub_booking_ids' => [$this->second->id]])->assertOk();
    expect($this->second->fresh()->status)->toBe(SubBookingStatus::CheckedIn);
});

test('a split booking changes one room\'s dates after a preview', function () {
    $this->postJson("{$this->base}/split")->assertOk();
    $dates = ['check_in' => now()->toDateString(), 'check_out' => now()->addDays(3)->toDateString()];

    $this->postJson("{$this->base}/rooms/{$this->first->id}/dates/preview", $dates)->assertOk()->assertJsonStructure(['current_total', 'new_total']);
    $this->patchJson("{$this->base}/rooms/{$this->first->id}/dates", $dates)->assertOk();

    expect($this->first->fresh()->check_out_date->toDateString())->toBe($dates['check_out']);
});

test('a room is upgraded from the options the app lists', function () {
    $options = $this->getJson("{$this->base}/rooms/{$this->first->id}/upgrade")->assertOk()->json('options');

    $this->postJson("{$this->base}/rooms/{$this->first->id}/upgrade", [
        'room_category_id' => $options[0]['id'], 'room_id' => null, 'type' => 'complimentary', 'supplement_per_night' => null, 'reason' => 'Loyal guest',
    ])->assertOk();

    expect($this->first->fresh()->room_category_id)->toBe($options[0]['id']);
});

test('add-ons are added and removed', function () {
    $addon = Addon::factory()->create(['property_id' => $this->property->id]);

    $this->postJson("{$this->base}/addons", ['addon_id' => $addon->id, 'quantity' => 2])->assertOk();
    $line = $this->booking->addons()->sole();

    $this->deleteJson("{$this->base}/addons/{$line->id}")->assertOk();
    expect($this->booking->addons()->count())->toBe(0);
});

test('folios: a new folio with a window, a transfer between windows and a split payment', function () {
    $folios = $this->getJson($this->base)->json('props.folios');
    $windows = collect($folios)->flatMap(fn (array $folio): array => $folio['windows'])->pluck('id')->all();

    $this->postJson("{$this->base}/folios", ['window_name' => 'Company', 'payer_type' => null, 'payer_id' => null, 'company_name' => null])->assertOk();

    $this->postJson("{$this->base}/folio-transfers", ['from_sub_folio_id' => $windows[0], 'to_sub_folio_id' => $windows[1], 'amount' => 100])->assertOk();

    $this->postJson("{$this->base}/split-payments", [
        'payment_mode' => 'Cash', 'gateway_reference' => null,
        'distributions' => [['sub_folio_id' => $windows[0], 'amount' => 50], ['sub_folio_id' => $windows[1], 'amount' => 50]],
    ])->assertOk();

    expect($this->booking->payments()->count())->toBeGreaterThan(0);
});

test('the app edits the booking with the body Quick Book sends', function () {
    $page = $this->getJson($this->base)->json('props.booking');

    $this->putJson("/api/v1/properties/{$this->property->id}/bookings/{$this->booking->id}", [
        'booking_type' => $page['booking_type'], 'rate_plan' => $page['rate_plan'], 'pay_at_hotel' => $page['pay_at_hotel'],
        'internal_notes' => $page['internal_notes'], 'check_in_time' => $page['check_in_time'], 'check_out_time' => $page['check_out_time'],
        'booking_channel_id' => $page['booking_channel_id'], 'same_plan_for_all_rooms' => $page['same_plan_for_all_rooms'],
        'bill_room_to_guest_company' => $page['bill_room_to_guest_company'], 'bill_extras_to_guest_company' => $page['bill_extras_to_guest_company'], 'no_post' => $page['no_post'],
        'source' => $page['source'], 'type' => $page['type'], 'check_in' => $page['check_in'], 'check_out' => $page['check_out'], 'status' => $page['status'],
        'room_bill_to' => $page['room_bill_to'], 'extras_bill_to' => $page['extras_bill_to'], 'special_instructions' => 'Late arrival', 'guest_ids' => [],
        'rooms' => collect($page['rooms'])->map(fn (array $room): array => [
            'room_category_id' => $room['room_category_id'], 'room_id' => $room['room_id'], 'meal_plan' => $room['meal_plan'],
            'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => $room['rate_per_night'],
        ])->all(),
    ])->assertOk();

    expect($this->booking->fresh()->special_instructions)->toBe('Late arrival')
        ->and($this->first->fresh()->adults)->toBe(2);
});
