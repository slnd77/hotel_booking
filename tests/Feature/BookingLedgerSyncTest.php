<?php

use App\Enums\PaymentLinkStatus;
use App\Enums\SubBookingStatus;
use App\Enums\TransactionType;
use App\Models\Booking;
use App\Models\ChargeCode;
use App\Models\Currency;
use App\Models\Folio;
use App\Models\Language;
use App\Models\PaymentLink;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\SubBooking;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BookingLedgerSync;
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
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mirroredBookingPayload(RoomCategory $category, array $overrides = []): array
{
    return [
        'booking_ref_id' => 'MIRROR-1',
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => now()->addDays(2)->toDateString(),
        'check_out' => now()->addDays(4)->toDateString(),
        'status' => 'confirmed',
        'discount' => 0,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
        'rooms' => array_fill(0, 2, ['room_category_id' => $category->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]),
        ...$overrides,
    ];
}

function mirroredBooking(): Booking
{
    return Booking::query()->where('booking_ref_id', 'MIRROR-1')->sole();
}

test('a new booking gets one sub-booking per room and a master folio that nets to its total', function () {
    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), mirroredBookingPayload($this->category))->assertSessionHasNoErrors();

    $booking = mirroredBooking();
    $subBookings = $booking->subBookings()->get();

    expect($subBookings)->toHaveCount(2)
        ->and($subBookings->pluck('status')->unique()->all())->toBe([SubBookingStatus::Booked])
        ->and($subBookings->pluck('inventory_unit_id')->filter())->toHaveCount(2)
        ->and($subBookings->first()->check_in_date->toDateString())->toBe($booking->check_in->toDateString());

    $roomCharges = Transaction::query()->whereHas('chargeCode', fn ($query) => $query->where('code', ChargeCode::ROOM))->get();

    expect($roomCharges)->toHaveCount(2)
        ->and($roomCharges->pluck('source_sub_booking_id')->sort()->values()->all())->toBe($subBookings->pluck('id')->sort()->values()->all())
        ->and($booking->outstandingBalance())->toBe((float) $booking->total)
        ->and($booking->outstandingBalance())->toBe((float) $booking->outstanding);
});

test('editing a booking posts an adjustment instead of changing earlier entries', function () {
    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), mirroredBookingPayload($this->category));
    $booking = mirroredBooking();
    $firstEntries = Transaction::query()->pluck('amount', 'id');

    $this->actingAs($this->admin)
        ->put(route('admin.bookings.update', [$this->property, $booking]), mirroredBookingPayload($this->category, ['discount' => 500]))
        ->assertSessionHasNoErrors();

    $booking->refresh();
    $adjustment = Transaction::query()->whereHas('chargeCode', fn ($query) => $query->where('code', ChargeCode::ADJUSTMENT))->latest('id')->first();

    expect($adjustment->transaction_type)->toBe(TransactionType::Credit)
        ->and(Transaction::query()->whereKey($firstEntries->keys())->pluck('amount', 'id')->all())->toEqual($firstEntries->all())
        ->and($booking->outstandingBalance())->toBe((float) $booking->total)
        ->and($booking->subBookings()->count())->toBe(2);
});

test('a received payment is credited once, and cancelling it reverses the credit and the outstanding amount', function () {
    config(['services.test_gateway.enabled' => true]);

    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), mirroredBookingPayload($this->category));
    $booking = mirroredBooking();
    $total = (float) $booking->total;

    $link = PaymentLink::factory()->create(['property_id' => $this->property->id, 'booking_id' => $booking->id, 'amount' => 1000, 'created_by' => $this->admin->id]);
    $order = $this->postJson(route('payment-links.initiate', $link->token), ['payment_gateway' => 'test'])->json();
    $this->postJson(route('payment-links.confirm', $link->token), ['payment_gateway' => 'test', 'transaction_id' => 'pay_1', 'order_id' => $order['order_id']])->assertOk();

    $payment = $link->fresh()->payment;
    $booking->refresh();

    expect(Transaction::query()->where('payment_id', $payment->id)->sole()->transaction_type)->toBe(TransactionType::Credit)
        ->and($booking->outstandingBalance())->toBe($total - 1000)
        ->and($booking->outstandingBalance())->toBe((float) $booking->outstanding);

    $this->actingAs($this->admin)->post(route('admin.databases.payments.cancel', $payment), ['cancellation_reason' => 'Chargeback']);
    $booking->refresh();

    expect(Transaction::query()->where('payment_id', $payment->id)->count())->toBe(2) // credit + reversing debit
        ->and((float) $booking->outstanding)->toBe($total)
        ->and($booking->outstandingBalance())->toBe($total);
    expect($link->fresh()->status)->toBe(PaymentLinkStatus::Paid);
});

test('the sync command backfills existing bookings and reports drift', function () {
    // A booking written before the ledger existed: a room, but no folios.
    $booking = Booking::withoutEvents(fn () => Booking::query()->create([
        'property_id' => $this->property->id,
        'booking_ref_id' => 'OLD-1',
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
        'status' => 'confirmed',
        'total' => 2100,
        'outstanding' => 2100,
    ]));
    SubBooking::withoutEvents(fn () => $booking->rooms()->create([
        'room_category_id' => $this->category->id,
        'room_id' => $this->rooms[0]->id,
        'check_in_date' => $booking->check_in,
        'check_out_date' => $booking->check_out,
        'status' => 'booked',
        'adults' => 1,
        'rate_per_night' => 2000,
    ]));

    expect(Folio::query()->count())->toBe(0);

    $this->artisan('bookings:sync-ledger', ['--booking' => [$booking->id]])->assertSuccessful();

    expect($booking->rooms()->sole()->folio->balance())->toBe(2100.0)
        ->and($booking->outstandingBalance())->toBe(2100.0);

    // The stored amount drifts (e.g. a legacy bug): --check reports it without writing.
    $booking->updateQuietly(['outstanding' => 1500]);
    $entries = Transaction::query()->count();

    $this->artisan('bookings:sync-ledger', ['--booking' => [$booking->id], '--check' => true])
        ->expectsOutputToContain('differs from the stored outstanding amount')
        ->assertFailed();

    expect(Transaction::query()->count())->toBe($entries);
});

test('each room bills on its own folio, with its share of discount and tax', function () {
    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), mirroredBookingPayload($this->category, [
        'discount' => 300,
        'rooms' => [
            ['room_category_id' => $this->category->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000],
            ['room_category_id' => $this->category->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => 2000],
        ],
    ]));

    $booking = mirroredBooking();
    [$first, $second] = $booking->subBookings()->orderBy('id')->get()->map(fn ($room) => $room->folio->balance())->all();

    // 2 nights: 2,000 and 4,000 of room charges; the 300 discount splits 100 / 200.
    expect($first + $second)->toBe((float) $booking->total)
        ->and($first)->toBe(round(2000 - 100 + ((float) $booking->taxes / 3), 2))
        ->and($booking->folios()->first()->balance())->toBe(0.0);
});

test('removing a room from a booking keeps what was paid on it in the balance', function () {
    config(['services.test_gateway.enabled' => true]);

    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), mirroredBookingPayload($this->category));
    $booking = mirroredBooking();

    $link = PaymentLink::factory()->create(['property_id' => $this->property->id, 'booking_id' => $booking->id, 'amount' => 1500, 'created_by' => $this->admin->id]);
    $order = $this->postJson(route('payment-links.initiate', $link->token), ['payment_gateway' => 'test'])->json();
    $this->postJson(route('payment-links.confirm', $link->token), ['payment_gateway' => 'test', 'transaction_id' => 'pay_2', 'order_id' => $order['order_id']]);

    // The receipt went to the first room that owed money.
    $firstRoom = $booking->subBookings()->orderBy('id')->first();
    expect(Transaction::query()->where('payment_id', $link->fresh()->payment_id)->sole()->subFolio->folio->owner->is($firstRoom))->toBeTrue();

    // Edit down to one room: the second room row goes, the first keeps its identity.
    $this->actingAs($this->admin)
        ->put(route('admin.bookings.update', [$this->property, $booking]), mirroredBookingPayload($this->category, [
            'rooms' => [['room_category_id' => $this->category->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
        ]))
        ->assertSessionHasNoErrors();

    $booking->refresh();

    expect($booking->subBookings()->pluck('id')->all())->toBe([$firstRoom->id])
        ->and($booking->outstandingBalance())->toBe((float) $booking->outstanding)
        ->and($booking->outstandingBalance())->toBe(round((float) $booking->total - 1500, 2));
});

test('what a booking owes always follows the ledger, whichever screen records the money', function () {
    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), mirroredBookingPayload($this->category));
    $booking = mirroredBooking();
    $total = (float) $booking->total;

    // A receipt added on the Payments page, tagged to the booking.
    $this->actingAs($this->admin)->post(route('admin.databases.payments.store', ['property' => $this->property->id]), [
        'guest_name' => 'Walk-in',
        'payment_mode' => 'Cash',
        'total_txn_amount' => 700,
        'booking_id' => $booking->id,
    ])->assertSessionHasNoErrors();

    expect((float) $booking->fresh()->outstanding)->toBe($total - 700);

    // Someone edits the stored amount directly: the next sync puts the ledger's figure back.
    $booking->updateQuietly(['outstanding' => 0]);
    app(BookingLedgerSync::class)->sync($booking);

    expect((float) $booking->fresh()->outstanding)->toBe($total - 700);
});
