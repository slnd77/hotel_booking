<?php

use App\Models\Booking;
use App\Models\BookingPaymentMapping;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\SubBooking;
use App\Models\SubFolio;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * Add Payment → Tag Transactions: one receipt tagged to several bookings, each share
 * spread over that booking's folio and sub-folios.
 */
beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create();
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id]);

    // Two families, two nights each: A has rooms of 2,000 + 3,000; B has rooms of 1,000 + 1,500.
    $this->bookingA = tagTestBooking($this->property, $this->category, 'FAM-A', [1000, 1500]);
    $this->bookingB = tagTestBooking($this->property, $this->category, 'FAM-B', [500, 750]);

    $this->roomsA = folioWindowsOf($this->bookingA);
    $this->roomsB = folioWindowsOf($this->bookingB);
    $this->masterA = app(Ledger::class)->defaultWindowFor($this->bookingA);
    $this->masterB = app(Ledger::class)->defaultWindowFor($this->bookingB);
});

/**
 * @param  list<int>  $rates
 */
function tagTestBooking(Property $property, RoomCategory $category, string $ref, array $rates): Booking
{
    $total = array_sum($rates) * 2;
    $booking = Booking::query()->create([
        'property_id' => $property->id,
        'booking_ref_id' => $ref,
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(3)->toDateString(),
        'room_total' => $total,
        'total' => $total,
        'outstanding' => $total,
    ]);

    foreach ($rates as $rate) {
        $room = Room::factory()->create(['room_category_id' => $category->id]);
        $booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $room->id, 'adults' => 2, 'rate_per_night' => $rate]);
    }

    return $booking;
}

/**
 * @return Collection<int, SubFolio>
 */
function folioWindowsOf(Booking $booking): Collection
{
    return $booking->subBookings()->orderBy('id')->get()->map(fn (SubBooking $room) => $room->folio->subFolios()->first());
}

/**
 * @param  list<array<string, mixed>>  $allocations
 */
function addTaggedPayment(Property $property, float $amount, array $allocations)
{
    return test()->actingAs(test()->admin)->post('/admin/databases/payments?property='.$property->id, [
        'guest_name' => 'Tour leader',
        'payment_mode' => 'Cash',
        'total_txn_amount' => $amount,
        'allocations' => $allocations,
    ]);
}

test('one receipt is tagged to two bookings, each spread over its own folio and sub-folios as chosen', function () {
    addTaggedPayment($this->property, 7000, [
        ['booking_id' => $this->bookingA->id, 'tagged_amount' => 4500, 'windows' => [
            ['sub_folio_id' => $this->roomsA[0]->id, 'amount' => 2000],
            ['sub_folio_id' => $this->roomsA[1]->id, 'amount' => 2000],
            ['sub_folio_id' => $this->masterA->id, 'amount' => 500],
        ]],
        ['booking_id' => $this->bookingB->id, 'tagged_amount' => 2000, 'windows' => [
            ['sub_folio_id' => $this->roomsB[1]->id, 'amount' => 1500],
            ['sub_folio_id' => $this->masterB->id, 'amount' => 500],
        ]],
    ])->assertSessionHasNoErrors();

    $payment = Payment::query()->where('guest_name', 'Tour leader')->sole();

    expect((float) $payment->tagged_amount)->toBe(6500.0)
        ->and((float) $payment->untagged_amount)->toBe(500.0)
        ->and(BookingPaymentMapping::query()->where('payment_id', $payment->id)->pluck('tagged_amount', 'booking_id')->map(fn ($amount) => (float) $amount)->all())
        ->toBe([$this->bookingA->id => 4500.0, $this->bookingB->id => 2000.0]);

    // Every folio got what was chosen for it — and the ledger sync after the commit left it there.
    expect($this->roomsA[0]->balance())->toBe(0.0)
        ->and($this->roomsA[1]->balance())->toBe(1000.0)
        ->and($this->masterA->balance())->toBe(-500.0)
        ->and($this->roomsB[0]->balance())->toBe(1000.0)
        ->and($this->roomsB[1]->balance())->toBe(0.0)
        ->and($this->masterB->balance())->toBe(-500.0);

    // Each booking owes what its folios add up to.
    expect((float) $this->bookingA->fresh()->outstanding)->toBe(500.0)
        ->and((float) $this->bookingB->fresh()->outstanding)->toBe(500.0);

    // The receipt's credits: one entry per chosen folio across both bookings, 6,500 in total.
    $credits = Transaction::query()->where('payment_id', $payment->id)->get();
    expect($credits)->toHaveCount(5)
        ->and(round($credits->sum('amount'), 2))->toBe(6500.0);
});

test('a booking tagged without a split is settled in the default order, next to one split by hand', function () {
    addTaggedPayment($this->property, 5500, [
        ['booking_id' => $this->bookingA->id, 'tagged_amount' => 3000, 'windows' => [
            ['sub_folio_id' => $this->roomsA[1]->id, 'amount' => 3000],
        ]],
        // No windows: rooms that owe, in order, then the master folio.
        ['booking_id' => $this->bookingB->id, 'tagged_amount' => 2500],
    ])->assertSessionHasNoErrors();

    expect($this->roomsA[0]->balance())->toBe(2000.0)
        ->and($this->roomsA[1]->balance())->toBe(0.0)
        ->and($this->roomsB[0]->balance())->toBe(0.0)
        ->and($this->roomsB[1]->balance())->toBe(0.0)
        ->and(round($this->masterB->balance(), 2))->toBe(0.0);
});

test('a folio of another booking can\'t take a booking\'s share, and then nothing is saved', function () {
    addTaggedPayment($this->property, 3000, [
        ['booking_id' => $this->bookingA->id, 'tagged_amount' => 2000, 'windows' => [
            ['sub_folio_id' => $this->roomsA[0]->id, 'amount' => 1000],
            ['sub_folio_id' => $this->roomsB[0]->id, 'amount' => 1000],
        ]],
        ['booking_id' => $this->bookingB->id, 'tagged_amount' => 1000],
    ])->assertSessionHasErrors('distributions');

    expect(Payment::query()->where('guest_name', 'Tour leader')->exists())->toBeFalse()
        ->and(Transaction::query()->whereNotNull('payment_id')->count())->toBe(0)
        ->and($this->roomsA[0]->balance())->toBe(2000.0)
        ->and($this->roomsB[0]->balance())->toBe(1000.0);
});

test('cancelling a receipt tagged to two bookings puts every folio back to what it owed', function () {
    addTaggedPayment($this->property, 4000, [
        ['booking_id' => $this->bookingA->id, 'tagged_amount' => 2500, 'windows' => [
            ['sub_folio_id' => $this->roomsA[0]->id, 'amount' => 2000],
            ['sub_folio_id' => $this->roomsA[1]->id, 'amount' => 500],
        ]],
        ['booking_id' => $this->bookingB->id, 'tagged_amount' => 1500, 'windows' => [
            ['sub_folio_id' => $this->roomsB[1]->id, 'amount' => 1500],
        ]],
    ])->assertSessionHasNoErrors();

    $payment = Payment::query()->where('guest_name', 'Tour leader')->sole();

    $this->actingAs($this->admin)->post(route('admin.databases.payments.cancel', $payment), ['cancellation_reason' => 'Card declined'])
        ->assertSessionHas('success');

    expect(round($this->roomsA[0]->balance(), 2))->toBe(2000.0)
        ->and(round($this->roomsA[1]->balance(), 2))->toBe(3000.0)
        ->and(round($this->roomsB[1]->balance(), 2))->toBe(1500.0)
        ->and((float) $this->bookingA->fresh()->outstanding)->toBe(5000.0)
        ->and((float) $this->bookingB->fresh()->outstanding)->toBe(2500.0);
});
