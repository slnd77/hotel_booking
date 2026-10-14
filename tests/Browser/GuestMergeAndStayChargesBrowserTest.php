<?php

use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\Currency;
use App\Models\Guest;
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
    $this->actingAs($this->admin);
});

test('a duplicate guest is merged from the guest list', function () {
    $keep = Guest::factory()->create(['property_id' => $this->property->id, 'first_name' => 'Asha', 'last_name' => 'Rao', 'phone' => '9000000001']);
    $duplicate = Guest::factory()->create(['property_id' => $this->property->id, 'first_name' => 'Asha', 'last_name' => 'R', 'phone' => '9000000001']);

    visit(route('admin.databases.guests.index', ['property' => $this->property->id]))
        ->click("@guest-merge-open-{$keep->id}")
        ->assertSee('Possible duplicates: same mobile, e-mail or name')
        ->click("@guest-merge-{$duplicate->id}")
        ->assertSee('will be deleted after its 0 bookings move to')
        ->click('@guest-merge-confirm')
        ->assertSee("Guest #{$duplicate->id} was merged into guest #{$keep->id}.")
        ->assertNoJavaScriptErrors();

    expect(Guest::query()->find($duplicate->id))->toBeNull();
});

test('the property sets an early check-in tier and the check-in dialog charges it', function () {
    $category = RoomCategory::factory()->create(['property_id' => $this->property->id, 'meal_plans' => ['EP']]);
    Room::factory()->create(['room_category_id' => $category->id, 'name' => '101']);

    visit(route('admin.properties.edit', [$this->property, 'tab' => 'early-late-charges']))
        ->click('@early_check_in-enabled')
        ->click('@early_check_in-add-tier')
        ->type('@early_check_in-time-0', '06:00')
        ->type('@early_check_in-value-0', '100')
        ->click('@stay-time-charges-save')
        ->assertSee('Early check-in / late check-out charges saved.')
        ->assertNoJavaScriptErrors();

    $this->post(route('admin.bookings.store', $this->property), [
        'booking_ref_id' => 'EARLY-B', 'source' => 'direct', 'type' => 'walk_in', 'status' => 'confirmed', 'discount' => 0,
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        'check_in' => now()->toDateString(), 'check_out' => now()->addDays(2)->toDateString(),
        'rooms' => [['room_category_id' => $category->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 1, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
    ])->assertSessionHasNoErrors();
    $booking = Booking::query()->where('booking_ref_id', 'EARLY-B')->sole();

    visit(route('admin.bookings.show', [$this->property, $booking]))
        ->click('@booking-check-in')
        ->type('@check-in-time', now()->toDateString().'T00:30')
        // The charge is fetched for each keystroke; wait for the typed time's answer.
        ->waitForText('before 06:00')
        ->assertSee('Early check-in charge')
        ->assertSee('before 06:00')
        ->click('@check-in-submit')
        ->assertSee('Booking status updated to Checked In.')
        ->assertNoJavaScriptErrors();

    expect((float) BookingAddon::query()->sole()->amount)->toBe(1000.0);
});
