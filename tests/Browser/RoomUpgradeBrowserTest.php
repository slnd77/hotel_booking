<?php

use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->property = Property::factory()->create(['timezone' => 'UTC']);
    RoomCategory::query()->where('property_id', $this->property->id)->delete();
    $this->standard = RoomCategory::factory()->create(['property_id' => $this->property->id, 'name' => 'Standard', 'meal_plans' => ['EP']]);
    $this->suite = RoomCategory::factory()->create(['property_id' => $this->property->id, 'name' => 'Suite', 'meal_plans' => ['EP']]);
    Room::factory()->create(['room_category_id' => $this->standard->id, 'name' => '101']);
    Room::factory()->create(['room_category_id' => $this->suite->id, 'name' => 'S1']);
    sellOnBookingEngine($this->property, $this->suite, 1500);

    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), [
        'booking_ref_id' => 'UPG-B', 'source' => 'direct', 'type' => 'walk_in', 'status' => 'confirmed', 'discount' => 0,
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        'check_in' => now()->addDay()->toDateString(), 'check_out' => now()->addDays(3)->toDateString(),
        'rooms' => [['room_category_id' => $this->standard->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
    ])->assertSessionHasNoErrors();
    $this->booking = Booking::query()->where('booking_ref_id', 'UPG-B')->sole();
});

test('a room is upgraded from the booking page as a paid upgrade', function () {
    $room = $this->booking->subBookings()->sole();

    visit(route('admin.bookings.show', [$this->property, $this->booking]))
        ->click("@upgrade-room-{$room->id}")
        ->assertSee('From Standard for 2 night(s)')
        ->click("@upgrade-to-{$this->suite->id}")
        ->assertSee('+500.00/night')
        ->click('@upgrade-paid')
        ->assertValue('@upgrade-supplement', '500')
        ->type('@upgrade-reason', 'Guest asked for a view')
        ->click('@upgrade-submit')
        ->assertSee('Room upgraded to Suite (paid).')
        ->assertSee('Paid upgrade Standard (101) → Suite (S1)')
        ->assertNoJavaScriptErrors();

    expect((float) $room->fresh()->rate_per_night)->toEqual(1500);
});
