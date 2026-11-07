<?php

use App\Enums\BookingStatus;
use App\Enums\SubBookingStatus;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    // 11:30 on 02/10 in India.
    $this->travelTo(CarbonImmutable::parse('2026-10-02 06:00:00', 'UTC'));

    $this->property = Property::factory()->create(['timezone' => 'Asia/Kolkata']);
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    $this->rooms = collect(['101', '102', '103', '104'])->map(fn (string $name): Room => Room::factory()->create(['room_category_id' => $this->category->id, 'name' => $name]));
});

function frontDeskStay(Property $property, RoomCategory $category, Room $room, string $checkIn, string $checkOut, SubBookingStatus $status, BookingStatus $bookingStatus = BookingStatus::Confirmed): Booking
{
    $nights = CarbonImmutable::parse($checkIn)->diffInDays(CarbonImmutable::parse($checkOut));
    $booking = $property->bookings()->create([
        'booking_ref_id' => 'FD-'.fake()->unique()->numerify('####'), 'source' => 'direct', 'type' => 'walk_in', 'status' => $bookingStatus,
        'check_in' => $checkIn, 'check_out' => $checkOut,
        'room_total' => 1000 * $nights, 'discount' => 0, 'taxes' => 0, 'total' => 1000 * $nights, 'outstanding' => 1000 * $nights,
    ]);
    $booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $room->id, 'meal_plan' => 'EP', 'adults' => 2, 'rate_per_night' => 1000])
        ->update(['status' => $status]);

    return $booking;
}

test('the front desk shows the day\'s arrivals, departures, in-house guests and occupancy', function () {
    [$r101, $r102, $r103, $r104] = $this->rooms->all();
    $expected = frontDeskStay($this->property, $this->category, $r101, '2026-10-02', '2026-10-04', SubBookingStatus::Booked);
    $arrived = frontDeskStay($this->property, $this->category, $r102, '2026-10-02', '2026-10-03', SubBookingStatus::CheckedIn);
    $departed = frontDeskStay($this->property, $this->category, $r103, '2026-09-30', '2026-10-02', SubBookingStatus::CheckedOut);
    $stayover = frontDeskStay($this->property, $this->category, $r104, '2026-10-01', '2026-10-05', SubBookingStatus::CheckedIn);
    frontDeskStay($this->property, $this->category, $r103, '2026-10-02', '2026-10-03', SubBookingStatus::Cancelled, BookingStatus::Cancelled);
    // The factory property comes with rooms of its own.
    $available = Room::query()->whereHas('roomCategory', fn ($query) => $query->where('property_id', $this->property->id))->count();

    $this->actingAs($this->admin)
        ->get(route('admin.frontDesk.show', $this->property))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/front-desk/Show')
            ->where('board.date', '2026-10-02')
            ->where('board.is_today', true)
            ->where('board.kpis.rooms_available', $available)
            ->where('board.kpis.rooms_sold', 3)
            // JSON writes a whole float without its ".0", so compare as numbers.
            ->where('board.kpis.occupancy_percent', fn ($value): bool => (float) $value === round(3 / $available * 100, 2))
            ->where('board.kpis.adr', 1000)
            ->where('board.kpis.revpar', fn ($value): bool => (float) $value === round(3000 / $available, 2))
            ->where('board.kpis.arrivals_expected', 2)
            ->where('board.kpis.arrivals_done', 1)
            ->where('board.kpis.departures_expected', 1)
            ->where('board.kpis.departures_done', 1)
            ->where('board.kpis.in_house_guests', 4)
            ->where('board.arrivals', fn ($rows): bool => collect($rows)->pluck('booking_id')->sort()->values()->all() === [$expected->id, $arrived->id])
            ->where('board.departures.0.booking_id', $departed->id)
            ->where('board.in_house', fn ($rows): bool => collect($rows)->pluck('booking_id')->sort()->values()->all() === [$arrived->id, $stayover->id])
            ->where('board.arrivals.0.room', '101'));

    // Tomorrow: the guest who arrived today leaves, and two rooms are sold for the night.
    $this->actingAs($this->admin)
        ->get(route('admin.frontDesk.show', [$this->property, 'date' => '2026-10-03']))
        ->assertInertia(fn ($page) => $page
            ->where('board.is_today', false)
            ->where('board.kpis.arrivals_expected', 0)
            ->where('board.kpis.departures_expected', 1)
            ->where('board.kpis.departures_done', 0)
            ->where('board.kpis.rooms_sold', 2)
            ->where('board.in_house', fn ($rows): bool => collect($rows)->pluck('booking_id')->sort()->values()->all() === [$expected->id, $stayover->id]));
});

test('on another day, that day\'s arrivals are not counted as staying until they arrive', function () {
    [$r101, $r102] = $this->rooms->all();
    $arriving = frontDeskStay($this->property, $this->category, $r101, '2026-10-05', '2026-10-08', SubBookingStatus::Booked);
    $stayover = frontDeskStay($this->property, $this->category, $r102, '2026-10-03', '2026-10-07', SubBookingStatus::Booked);

    $this->actingAs($this->admin)
        ->get(route('admin.frontDesk.show', [$this->property, 'date' => '2026-10-05']))
        ->assertInertia(fn ($page) => $page
            ->where('board.kpis.arrivals_expected', 1)
            ->where('board.kpis.rooms_sold', 2)
            ->where('board.kpis.in_house_rooms', 1)
            ->where('board.in_house.0.booking_id', $stayover->id)
            ->where('board.arrivals.0.booking_id', $arriving->id));
});

test('the front desk needs front office access', function () {
    $clerk = User::factory()->create();
    $clerk->assignRole(Role::findOrCreate('Clerk', 'web'));
    $clerk->givePermissionTo(Permission::findOrCreate('reservation.check_in', 'web'));

    $this->actingAs($clerk)->get(route('admin.frontDesk.show', $this->property))->assertForbidden();

    $clerk->givePermissionTo(Permission::findOrCreate('front_office.access', 'web'));
    $this->actingAs($clerk)->get(route('admin.frontDesk.show', $this->property))->assertOk();
});
