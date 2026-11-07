<?php

use App\Enums\PaymentMode;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\SubBooking;
use App\Models\User;
use App\Services\BookingLedgerSync;
use App\Services\Ledger;
use App\Services\PaymentDistributionService;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $this->property->id]);

    // Two rooms for two nights: 3,000 and 2,500, each on its own folio.
    $this->booking = Booking::query()->create([
        'property_id' => $this->property->id,
        'booking_ref_id' => 'XFER-1',
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(3)->toDateString(),
        'room_total' => 5500,
        'total' => 5500,
        'outstanding' => 5500,
    ]);

    foreach ([1500, 1250] as $rate) {
        $room = Room::factory()->create(['room_category_id' => $category->id]);
        $this->booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $room->id, 'adults' => 2, 'rate_per_night' => $rate]);
    }

    $this->windows = $this->booking->subBookings()->orderBy('id')->get()->map(fn (SubBooking $room) => $room->folio->subFolios()->first());
    $this->master = app(Ledger::class)->defaultWindowFor($this->booking);
});

function transferBalance(Booking $booking, int $from, int $to, float $amount)
{
    return test()->actingAs(test()->admin)->post(route('admin.bookings.folioTransfers.store', [$booking->property_id, $booking]), [
        'from_sub_folio_id' => $from,
        'to_sub_folio_id' => $to,
        'amount' => $amount,
    ]);
}

test('an amount due moves from one room folio to another, and the booking total is unchanged', function () {
    transferBalance($this->booking, $this->windows[0]->id, $this->windows[1]->id, 1000)->assertSessionHas('success');

    expect($this->windows[0]->balance())->toBe(2000.0)
        ->and($this->windows[1]->balance())->toBe(3500.0)
        ->and(round($this->windows->sum(fn ($window) => $window->balance()) + $this->master->balance(), 2))->toBe(5500.0);

    // A later sync of the booking leaves the transfer alone.
    app(BookingLedgerSync::class)->sync($this->booking->fresh());

    expect($this->windows[0]->balance())->toBe(2000.0)
        ->and($this->windows[1]->balance())->toBe(3500.0);
});

test('a room\'s whole balance can go to the master folio, and a credit can be moved too', function () {
    transferBalance($this->booking, $this->windows[1]->id, $this->master->id, 2500)->assertSessionHas('success');

    expect($this->windows[1]->balance())->toBe(0.0)
        ->and($this->master->balance())->toBe(2500.0);

    // Overpay room 1 by 500, then move that credit onto the master folio's amount due.
    app(PaymentDistributionService::class)->processMasterPayment($this->booking->id, 3500, [['sub_folio_id' => $this->windows[0]->id, 'amount' => 3500]], PaymentMode::Cash);
    expect($this->windows[0]->balance())->toBe(-500.0);

    transferBalance($this->booking, $this->windows[0]->id, $this->master->id, 500)->assertSessionHas('success');

    expect($this->windows[0]->balance())->toBe(0.0)
        ->and($this->master->balance())->toBe(2000.0);
});

test('a transfer can\'t exceed the balance, go to the same folio, or reach another booking\'s folio', function () {
    transferBalance($this->booking, $this->windows[0]->id, $this->windows[1]->id, 3000.01)->assertSessionHas('error');
    transferBalance($this->booking, $this->windows[0]->id, $this->windows[0]->id, 100)->assertSessionHas('error');

    $other = Booking::query()->create([
        'property_id' => $this->property->id, 'booking_ref_id' => 'XFER-2',
        'check_in' => now()->addDay()->toDateString(), 'check_out' => now()->addDays(2)->toDateString(),
        'room_total' => 100, 'total' => 100, 'outstanding' => 100,
    ]);

    transferBalance($this->booking, $this->windows[0]->id, app(Ledger::class)->defaultWindowFor($other)->id, 100)
        ->assertSessionHas('error', 'Both folios must belong to this booking.');

    expect($this->windows[0]->balance())->toBe(3000.0);
});
