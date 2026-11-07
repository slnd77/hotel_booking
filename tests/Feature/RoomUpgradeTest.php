<?php

use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\RoomUpgrade;
use App\Models\SubBooking;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->property = Property::factory()->create(['timezone' => 'UTC']);
    RoomCategory::query()->where('property_id', $this->property->id)->delete();

    $this->standard = RoomCategory::factory()->create(['property_id' => $this->property->id, 'name' => 'Standard', 'meal_plans' => ['EP'], 'display_order' => 1]);
    $this->suite = RoomCategory::factory()->create(['property_id' => $this->property->id, 'name' => 'Suite', 'meal_plans' => ['EP'], 'display_order' => 2]);
    $this->villa = RoomCategory::factory()->create(['property_id' => $this->property->id, 'name' => 'Villa', 'meal_plans' => ['EP'], 'display_order' => 3, 'exclude_from_upgrade' => true]);
    Room::factory()->create(['room_category_id' => $this->standard->id, 'name' => '101']);
    $this->suiteRoom = Room::factory()->create(['room_category_id' => $this->suite->id, 'name' => 'S1']);
    Room::factory()->create(['room_category_id' => $this->villa->id, 'name' => 'V1']);
    sellOnBookingEngine($this->property, $this->suite, 1500);
});

/**
 * A one-room Standard booking at 1000 a night between the given day offsets.
 */
function upgradeBooking(object $test, string $ref, int $fromDays, int $toDays): SubBooking
{
    $test->actingAs($test->admin)->post(route('admin.bookings.store', $test->property), [
        'booking_ref_id' => $ref, 'source' => 'direct', 'type' => 'walk_in', 'status' => 'confirmed', 'discount' => 0,
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        'check_in' => now()->addDays($fromDays)->toDateString(), 'check_out' => now()->addDays($toDays)->toDateString(),
        'rooms' => [['room_category_id' => $test->standard->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
    ])->assertSessionHasNoErrors();

    return Booking::query()->where('booking_ref_id', $ref)->sole()->subBookings()->sole();
}

function upgradeUrl(SubBooking $room, string $name = 'admin.bookings.rooms.upgrade'): string
{
    return route($name, [$room->booking->property_id, $room->booking_id, $room]);
}

test('the options list free categories with a suggested supplement, never excluded ones', function () {
    $room = upgradeBooking($this, 'UPG-1', 1, 3);

    $options = $this->actingAs($this->admin)->getJson(upgradeUrl($room, 'admin.bookings.rooms.upgradeOptions'))->assertOk()->json();

    expect($options['nights'])->toBe(2)
        ->and(collect($options['options'])->pluck('name')->all())->toBe(['Suite'])
        ->and($options['options'][0]['suggested_supplement'])->toEqual(500)
        ->and($options['options'][0]['free_rooms'])->toBe([['id' => $this->suiteRoom->id, 'name' => 'S1']]);
});

test('a complimentary upgrade moves the room and keeps the rate and total', function () {
    $room = upgradeBooking($this, 'UPG-2', 1, 3);
    $total = (float) $room->booking->total;

    $this->actingAs($this->admin)->post(upgradeUrl($room), ['room_category_id' => $this->suite->id, 'room_id' => $this->suiteRoom->id, 'type' => 'complimentary', 'reason' => 'Anniversary'])
        ->assertSessionHas('success', 'Room upgraded to Suite (complimentary).');

    $room->refresh();
    $upgrade = RoomUpgrade::query()->sole();

    expect($room->room_category_id)->toBe($this->suite->id)
        ->and($room->room_id)->toBe($this->suiteRoom->id)
        ->and((float) $room->rate_per_night)->toEqual(1000)
        ->and((float) $room->booking->fresh()->total)->toEqual($total)
        ->and($upgrade->type)->toBe('complimentary')
        ->and($upgrade->from_room_category_id)->toBe($this->standard->id)
        ->and($upgrade->upgraded_by)->toBe($this->admin->id)
        ->and(ActivityLog::query()->where('action', 'like', 'Complimentary upgrade: Standard%Suite (S1). Reason: Anniversary')->exists())->toBeTrue();
});

test('a paid upgrade adds the supplement for every night to the booking total', function () {
    $room = upgradeBooking($this, 'UPG-3', 1, 3);
    $before = $room->booking->fresh();

    $this->actingAs($this->admin)->post(upgradeUrl($room), ['room_category_id' => $this->suite->id, 'type' => 'paid', 'supplement_per_night' => 500, 'reason' => 'Guest request'])
        ->assertSessionHasNoErrors();

    $after = $before->fresh();

    expect((float) $room->fresh()->rate_per_night)->toEqual(1500)
        ->and((float) $after->room_total - (float) $before->room_total)->toEqual(1000)
        ->and((float) $after->total)->toBeGreaterThanOrEqual((float) $before->total + 1000)
        ->and($room->fresh()->room_id)->toBe($this->suiteRoom->id)
        ->and($this->actingAs($this->admin)->get(route('admin.bookings.show', [$this->property, $before]))->inertiaProps('upgrades.0'))
        ->toMatchArray(['from' => 'Standard (101)', 'to' => 'Suite (S1)', 'type' => 'paid', 'nights' => 2, 'reason' => 'Guest request']);
});

test('a paid upgrade of a guest in house charges only the nights left', function () {
    $room = upgradeBooking($this, 'UPG-4', -1, 2);
    $this->actingAs($this->admin)->patch(route('admin.bookings.rooms.stay', [$this->property, $room->booking_id, $room]), ['status' => 'checked_in'])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)->post(upgradeUrl($room->fresh()), ['room_category_id' => $this->suite->id, 'type' => 'paid', 'supplement_per_night' => 300, 'reason' => 'AC fault'])
        ->assertSessionHasNoErrors();

    // 300 for each of the 2 nights left, spread over the 3-night stay.
    expect((float) $room->fresh()->rate_per_night)->toEqual(1200)
        ->and(RoomUpgrade::query()->sole()->nights)->toBe(2);
});

test('an upgrade is refused to an excluded, full or unknown category and without a supplement when paid', function () {
    Room::factory()->create(['room_category_id' => $this->standard->id, 'name' => '102']);
    $room = upgradeBooking($this, 'UPG-5', 1, 3);
    upgradeBooking($this, 'UPG-6', 2, 4)->update(['room_category_id' => $this->suite->id, 'room_id' => $this->suiteRoom->id]);
    $post = fn (array $data) => $this->actingAs($this->admin)->post(upgradeUrl($room), [...$data, 'reason' => 'Test']);

    $post(['room_category_id' => $this->villa->id, 'type' => 'complimentary'])->assertSessionHasErrors(['room_category_id' => "The room can't be upgraded to Villa."]);
    $post(['room_category_id' => $this->suite->id, 'type' => 'complimentary'])->assertSessionHasErrors(['room_category_id' => 'No Suite room is free for these nights.']);
    $post(['room_category_id' => RoomCategory::factory()->create()->id, 'type' => 'complimentary'])->assertSessionHasErrors('room_category_id');
    $post(['room_category_id' => $this->suite->id, 'type' => 'paid'])->assertSessionHasErrors('supplement_per_night');

    expect(RoomUpgrade::query()->count())->toBe(0)
        ->and($room->fresh()->room_category_id)->toBe($this->standard->id);
});

test('upgrading a room needs the upgrade permission', function () {
    $room = upgradeBooking($this, 'UPG-7', 1, 3);
    $clerk = User::factory()->create();
    $clerk->assignRole(Role::findOrCreate('Clerk', 'web'));

    $this->actingAs($clerk)->post(upgradeUrl($room), ['room_category_id' => $this->suite->id, 'type' => 'complimentary', 'reason' => 'Test'])->assertForbidden();

    $clerk->givePermissionTo(Permission::findOrCreate('reservation.upgrade_room', 'web'));

    $this->actingAs($clerk)->post(upgradeUrl($room), ['room_category_id' => $this->suite->id, 'type' => 'complimentary', 'reason' => 'Test'])->assertSessionHasNoErrors();
    expect($room->fresh()->room_category_id)->toBe($this->suite->id);
});
