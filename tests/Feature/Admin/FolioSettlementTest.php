<?php

use App\Enums\BookingStatus;
use App\Enums\FolioStatus;
use App\Enums\TransactionType;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\ChargeCode;
use App\Models\Currency;
use App\Models\Folio;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\BookingLedgerSync;
use App\Services\FolioSettlements;
use App\Services\Ledger;
use App\Services\PaymentReceipts;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create();
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    Room::factory()->count(3)->create(['room_category_id' => $this->category->id]);
});

/**
 * A one-room booking, paid in full when $paid.
 */
function settlementBooking(string $ref, bool $paid = true): Booking
{
    test()->actingAs(test()->admin)->post(route('admin.bookings.store', test()->property), [
        'booking_ref_id' => $ref, 'source' => 'direct', 'type' => 'walk_in', 'status' => 'confirmed', 'discount' => 0,
        'check_in' => now()->toDateString(), 'check_out' => now()->addDay()->toDateString(),
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        'rooms' => [['room_category_id' => test()->category->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
    ])->assertSessionHasNoErrors();
    $booking = Booking::query()->where('booking_ref_id', $ref)->sole();

    if ($paid) {
        app(PaymentReceipts::class)->record(test()->property->id, ['booking_id' => $booking->id, 'guest_name' => 'Guest', 'payment_mode' => 'Cash', 'total_txn_amount' => (float) $booking->total], 'Staff');
        app(BookingLedgerSync::class)->sync($booking);
    }

    return $booking->refresh();
}

/**
 * @return list<FolioStatus>
 */
function settlementFolioStatuses(Booking $booking): array
{
    return $booking->allFoliosQuery()->orderBy('id')->get()->map(fn (Folio $folio): FolioStatus => $folio->status)->all();
}

function settlementLateCharge(Booking $booking, float $amount = 250): void
{
    $ledger = app(Ledger::class);
    $ledger->postEntry($ledger->defaultWindowFor($booking), TransactionType::Debit, $amount, ChargeCode::system(ChargeCode::CANCELLATION), 'Late charge');
    app(BookingLedgerSync::class)->sync($booking);
}

test('checking out settles folios with nothing left on them; a booking that still owes stays open', function () {
    $paid = settlementBooking('FS-1');
    $owing = settlementBooking('FS-2', paid: false);

    expect(settlementFolioStatuses($paid))->each->toBe(FolioStatus::Open);

    $paid->update(['status' => BookingStatus::CheckedOut]);
    $owing->update(['status' => BookingStatus::CheckedOut]);

    expect(settlementFolioStatuses($paid))->not->toBeEmpty()->each->toBe(FolioStatus::Settled)
        ->and(settlementFolioStatuses($owing))->toContain(FolioStatus::Open);

    // Undoing the check-out opens them again.
    $paid->update(['status' => BookingStatus::CheckedIn]);

    expect(settlementFolioStatuses($paid))->each->toBe(FolioStatus::Open);
});

test('a late charge reopens a settled folio, which settles again once paid', function () {
    $booking = settlementBooking('FS-3');
    $booking->update(['status' => BookingStatus::CheckedOut]);

    settlementLateCharge($booking);

    $master = Folio::query()->whereMorphedTo('owner', $booking)->sole();
    expect($master->status)->toBe(FolioStatus::Open)
        ->and(Folio::query()->whereMorphedTo('owner', $booking)->count())->toBe(1)
        ->and(ActivityLog::query()->where('action', 'like', "%folio #{$master->id} reopened from settled%")->exists())->toBeTrue();

    app(PaymentReceipts::class)->record($this->property->id, ['booking_id' => $booking->id, 'guest_name' => 'Guest', 'payment_mode' => 'Cash', 'total_txn_amount' => 250], 'Staff');
    app(BookingLedgerSync::class)->sync($booking);

    expect(settlementFolioStatuses($booking))->each->toBe(FolioStatus::Settled);
});

test('the night audit locks settled folios of stays that ended; only allowed users can post to them', function () {
    $booking = settlementBooking('FS-4');
    $booking->update(['status' => BookingStatus::CheckedOut]);
    $inHouse = settlementBooking('FS-5');

    expect(app(FolioSettlements::class)->lockForAudit($this->property, $booking->check_out))->toBe(count(settlementFolioStatuses($booking)))
        ->and(settlementFolioStatuses($booking))->each->toBe(FolioStatus::Locked)
        ->and(settlementFolioStatuses($inHouse))->each->toBe(FolioStatus::Open);

    // A sync leaves locked folios alone.
    app(BookingLedgerSync::class)->sync($booking);
    expect(settlementFolioStatuses($booking))->each->toBe(FolioStatus::Locked);

    $clerk = User::factory()->create();
    $this->actingAs($clerk);
    expect(fn () => settlementLateCharge($booking))->toThrow(ValidationException::class, 'locked by the night audit');
    expect(settlementFolioStatuses($booking))->each->toBe(FolioStatus::Locked);

    $clerk->givePermissionTo(Permission::findOrCreate(FolioSettlements::UNLOCK_PERMISSION, 'web'));
    settlementLateCharge($booking->refresh());

    expect(Folio::query()->whereMorphedTo('owner', $booking)->sole()->status)->toBe(FolioStatus::Open)
        ->and(ActivityLog::query()->where('action', 'like', '%reopened from locked (night audit)%')->exists())->toBeTrue();
});
