<?php

use App\Enums\PaymentMode;
use App\Enums\TransactionType;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\SubBooking;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger;
use App\Services\PaymentDistributionService;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->ledger = app(Ledger::class);
    $this->service = app(PaymentDistributionService::class);

    $this->property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $this->property->id]);

    // The father's booking: three rooms for two nights. Each room bills on its own folio
    // (three invoices): 3,000 + 2,500 + 3,500.
    $this->booking = Booking::query()->create([
        'property_id' => $this->property->id,
        'booking_ref_id' => 'FAMILY-1',
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(3)->toDateString(),
        'room_total' => 9000,
        'total' => 9000,
        'outstanding' => 9000,
    ]);

    foreach ([1500, 1250, 1750] as $rate) {
        $room = Room::factory()->create(['room_category_id' => $category->id]);
        $this->booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $room->id, 'adults' => 2, 'rate_per_night' => $rate]);
    }

    $this->windows = $this->booking->subBookings()->orderBy('id')->get()
        ->map(fn (SubBooking $room) => $room->folio->subFolios()->first());
});

test('one card swipe settles three separate room folios, each credit tagged to the master payment', function () {
    $payment = $this->service->processMasterPayment($this->booking->id, 9000, [
        ['sub_folio_id' => $this->windows[0]->id, 'amount' => 3000],
        ['sub_folio_id' => $this->windows[1]->id, 'amount' => 2500],
        ['sub_folio_id' => $this->windows[2]->id, 'amount' => 3500],
    ], PaymentMode::CreditCard, 'ch_3PxLumpSum');

    expect((float) $payment->total_txn_amount)->toBe(9000.0)
        ->and($payment->pg_txn_id)->toBe('ch_3PxLumpSum')
        ->and($payment->booking_id)->toBe($this->booking->id)
        ->and($payment->transactions()->count())->toBe(3)
        ->and($payment->transactions()->pluck('transaction_type')->unique()->all())->toBe([TransactionType::Credit])
        ->and((float) $payment->transactions()->sum('amount'))->toBe(9000.0);

    // Three invoices stay separate, each settled.
    foreach ($this->windows as $window) {
        expect($window->balance())->toBe(0.0);
    }

    // The transition mirror sees the credits and doesn't credit the payment again.
    expect(Transaction::query()->where('payment_id', $payment->id)->count())->toBe(3)
        ->and($this->booking->outstandingBalance())->toBe(0.0)
        ->and((float) $this->booking->fresh()->outstanding)->toBe(0.0);
});

test('a split that does not add up to the payment total writes nothing', function () {
    $payments = Payment::query()->count();

    expect(fn () => $this->service->processMasterPayment($this->booking->id, 9000, [
        ['sub_folio_id' => $this->windows[0]->id, 'amount' => 3000],
        ['sub_folio_id' => $this->windows[1]->id, 'amount' => 2500],
    ]))->toThrow(ValidationException::class);

    expect(Payment::query()->count())->toBe($payments)
        ->and(Transaction::query()->whereNotNull('payment_id')->count())->toBe(0);
});

test("a payment can't settle a billing window of another booking", function () {
    $otherBooking = Booking::query()->create([
        'property_id' => $this->property->id,
        'booking_ref_id' => 'OTHER-1',
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
    ]);
    $otherWindow = $this->ledger->defaultWindowFor($otherBooking);

    expect(fn () => $this->service->processMasterPayment($this->booking->id, 3500, [
        ['sub_folio_id' => $this->windows[0]->id, 'amount' => 3000],
        ['sub_folio_id' => $otherWindow->id, 'amount' => 500],
    ]))->toThrow(ValidationException::class, 'A billing window does not belong to this booking.');

    expect(Transaction::query()->whereNotNull('payment_id')->count())->toBe(0);
});

test('Add Payment puts a tagged amount on the folio and sub-folios chosen, and the ledger leaves it there', function () {
    Role::firstOrCreate(['name' => 'admin']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $master = $this->ledger->defaultWindowFor($this->booking);

    $this->actingAs($admin)->post('/admin/databases/payments?property='.$this->property->id, [
        'guest_name' => 'Father',
        'payment_mode' => 'Cash',
        'total_txn_amount' => 4000,
        'allocations' => [[
            'booking_id' => $this->booking->id,
            'tagged_amount' => 4000,
            'windows' => [
                ['sub_folio_id' => $this->windows[0]->id, 'amount' => 0],
                ['sub_folio_id' => $this->windows[1]->id, 'amount' => 2500],
                ['sub_folio_id' => $master->id, 'amount' => 1500],
            ],
        ]],
    ])->assertSessionHasNoErrors();

    // Room 2 settled, 1,500 held on the master folio — not moved onto rooms 1 and 3 by the sync.
    expect($this->windows[0]->balance())->toBe(3000.0)
        ->and($this->windows[1]->balance())->toBe(0.0)
        ->and($this->windows[2]->balance())->toBe(3500.0)
        ->and($master->balance())->toBe(-1500.0);
});

test('an Add Payment folio split that does not add up to the tagged amount saves nothing', function () {
    Role::firstOrCreate(['name' => 'admin']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->post('/admin/databases/payments?property='.$this->property->id, [
        'guest_name' => 'Father',
        'payment_mode' => 'Cash',
        'total_txn_amount' => 4000,
        'allocations' => [[
            'booking_id' => $this->booking->id,
            'tagged_amount' => 4000,
            'windows' => [['sub_folio_id' => $this->windows[1]->id, 'amount' => 2500]],
        ]],
    ])->assertSessionHasErrors('distributions');

    expect(Payment::query()->where('guest_name', 'Father')->exists())->toBeFalse()
        ->and(Transaction::query()->whereNotNull('payment_id')->count())->toBe(0);
});

test('the booking page lists each room folio and staff can record a split payment there', function () {
    Role::firstOrCreate(['name' => 'admin']);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get(route('admin.bookings.show', [$this->property, $this->booking]))
        ->assertInertia(fn ($page) => $page
            ->has('folios', 4) // three rooms + the booking's master folio
            ->where('folios.0.is_room', true)
            ->where('folios.0.windows.0.balance', 3000)
            // Numbered like the room details, whatever the physical room is called.
            ->where('folios.0.label', fn (string $label): bool => str_starts_with($label, 'Room 1 | '))
            ->where('folios.2.label', fn (string $label): bool => str_starts_with($label, 'Room 3 | '))
            ->has('folios.0.room_number')
            ->where('folios.3.label', 'Booking (master folio)')
            // Add Payment → tag transactions finds the booking by any of its folio numbers.
            ->where('reservations.0.folio_ids', $this->booking->folioIds())
            ->has('reservations.0.folio_ids', 4)
            // …and can split a tagged amount over its windows: rooms first (in order), then the master folio.
            ->has('reservations.0.windows', 4)
            ->where('reservations.0.windows.0.sub_folio_id', $this->windows[0]->id)
            ->where('reservations.0.windows.0.balance', 3000)
            ->where('reservations.0.windows.3.is_room', false));

    $this->actingAs($admin)
        ->post(route('admin.bookings.splitPayments.store', [$this->property, $this->booking]), [
            'payment_mode' => 'Cash',
            'distributions' => [
                ['sub_folio_id' => $this->windows[0]->id, 'amount' => 3000],
                ['sub_folio_id' => $this->windows[2]->id, 'amount' => 1000],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    expect($this->windows[0]->balance())->toBe(0.0)
        ->and($this->windows[1]->balance())->toBe(2500.0)
        ->and($this->windows[2]->balance())->toBe(2500.0)
        ->and($this->booking->outstandingBalance())->toBe(5000.0)
        ->and((float) $this->booking->fresh()->outstanding)->toBe(5000.0);
});
