<?php

use App\Enums\BookingStatus;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\Booking;
use App\Models\BookingPaymentMapping;
use App\Models\ChargeCode;
use App\Models\CityLedgerAccount;
use App\Models\Currency;
use App\Models\GlAccount;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BookingLedgerSync;
use App\Services\GeneralLedger;
use App\Services\Ledger;
use App\Services\PropertySettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);

    $this->property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    $room = Room::factory()->create(['room_category_id' => $category->id]);

    // 5 000 of rooms and 900 of tax, checked out, nothing paid.
    $this->booking = DB::transaction(function () use ($category, $room): Booking {
        $booking = $this->property->bookings()->create([
            'booking_ref_id' => 'GL-BOOKING', 'source' => 'direct', 'status' => BookingStatus::CheckedOut,
            'check_in' => now()->subDays(5)->toDateString(), 'check_out' => now()->toDateString(),
            'room_total' => 5000, 'discount' => 0, 'taxes' => 900, 'total' => 5900, 'outstanding' => 5900,
            'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
        ]);
        $booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $room->id, 'meal_plan' => 'EP', 'adults' => 1, 'rate_per_night' => 1000]);

        return $booking;
    });
});

/**
 * The account's balance in its normal direction, from the trial balance.
 */
function glBalance(Property $property, string $systemKey): float
{
    $id = app(GeneralLedger::class)->account($property->id, $systemKey);

    return (float) collect(app(GeneralLedger::class)->trialBalance($property->id, CarbonImmutable::parse($property->today())->addYear())['rows'])->firstWhere('id', $id)['balance'];
}

test('a journal entry must balance', function () {
    $ledger = app(GeneralLedger::class);

    expect(fn () => $ledger->post($this->property->id, [
        [$ledger->account($this->property->id, 'cash'), 100.0, 0.0],
        [$ledger->account($this->property->id, 'room_revenue'), 0.0, 90.0],
    ]))->toThrow(InvalidArgumentException::class);
});

test('folio charges and payments post balanced entries, and the guest ledger equals the folios', function () {
    $this->post(route('admin.databases.payments.store', ['property' => $this->property->id]), [
        'guest_name' => 'Asha Rao', 'payment_mode' => 'Cash', 'total_txn_amount' => 2000, 'booking_id' => $this->booking->id,
    ])->assertSessionHasNoErrors();

    expect(glBalance($this->property, 'guest_ar'))->toBe(3900.0)
        ->and(glBalance($this->property, 'room_revenue'))->toBe(5000.0)
        ->and(glBalance($this->property, 'tax_payable'))->toBe(900.0)
        ->and(glBalance($this->property, 'cash'))->toBe(2000.0);

    $trialBalance = app(GeneralLedger::class)->trialBalance($this->property->id, CarbonImmutable::parse($this->property->today()));
    $control = app(GeneralLedger::class)->receivableControl($this->property->id);

    expect($trialBalance['total_debit'])->toBe($trialBalance['total_credit'])
        ->and($control['difference'])->toBe(0.0)
        ->and($control['unjournalled'])->toBe(0);

    // Every folio entry has exactly one balanced entry.
    $lines = JournalLine::query()->whereNotNull('transaction_id')->get()->groupBy('transaction_id');
    expect($lines->every(fn ($pair) => $pair->count() === 2 && (float) $pair->sum('debit') === (float) $pair->sum('credit')))->toBeTrue();
});

test('credit notes, city ledger and posting rules land in their accounts', function () {
    $invoice = Invoice::generateForBooking($this->booking->refresh(), $this->admin->name);
    $this->post(route('admin.databases.invoices.creditNotes.store', $invoice), ['total' => 1180, 'reason' => 'Overcharge'])->assertSessionHasNoErrors();

    // 1 000 back off revenue and 180 back out of tax.
    expect(glBalance($this->property, 'sales_returns'))->toBe(-1000.0)
        ->and(glBalance($this->property, 'tax_payable'))->toBe(720.0)
        ->and(glBalance($this->property, 'guest_ar'))->toBe(4720.0);

    $account = CityLedgerAccount::factory()->create(['property_id' => $this->property->id, 'credit_limit' => null]);
    $this->post(route('admin.databases.cityLedger.transfer', $account), ['booking_ref_id' => 'GL-BOOKING'])->assertSessionHasNoErrors();

    // A custom bank account for bank transfers.
    $this->post(route('admin.generalLedger.accounts.store', $this->property), ['code' => '1015', 'name' => 'HDFC Current Account', 'type' => 'asset'])->assertSessionHasNoErrors();
    $hdfc = GlAccount::query()->where('code', '1015')->sole();
    $this->put(route('admin.generalLedger.mappings.update', $this->property), ['mappings' => [['type' => 'payment_mode', 'key' => 'Bank Transfer', 'gl_account_id' => $hdfc->id]]])->assertSessionHasNoErrors();

    $this->post(route('admin.databases.cityLedger.payment', $account), ['amount' => 4720, 'payment_mode' => 'Bank Transfer'])->assertSessionHasNoErrors();

    $hdfcBalance = collect(app(GeneralLedger::class)->trialBalance($this->property->id, CarbonImmutable::parse($this->property->today()))['rows'])->firstWhere('id', $hdfc->id)['balance'];

    expect(glBalance($this->property, 'guest_ar'))->toBe(0.0)
        ->and(glBalance($this->property, 'city_ledger_ar'))->toBe(0.0)
        ->and($hdfcBalance)->toBe(4720.0);

    $this->get(route('admin.generalLedger.show', [$this->property, 'account' => $hdfc->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/general-ledger/Show')
            ->where('statement.closing', 4720)
            ->where('control.difference', 0));
});

test('the backfill journals older folio entries once', function () {
    $window = app(Ledger::class)->defaultWindowFor($this->booking);
    // Written straight to the folio, as before the general ledger existed.
    $older = Transaction::query()->create([
        'sub_folio_id' => $window->id, 'transaction_type' => TransactionType::Debit, 'amount' => 250,
        'charge_code_id' => ChargeCode::system(ChargeCode::POS_FNB)->id, 'description' => 'Old dinner', 'posted_at' => now()->subMonth(),
    ]);

    expect(app(GeneralLedger::class)->receivableControl($this->property->id)['unjournalled'])->toBe(1);

    $this->artisan('ledger:journal-backfill')->assertSuccessful();
    $this->artisan('ledger:journal-backfill')->assertSuccessful();

    expect(JournalLine::query()->where('transaction_id', $older->id)->count())->toBe(2)
        ->and(glBalance($this->property, 'fnb_revenue'))->toBe(250.0)
        ->and(app(GeneralLedger::class)->receivableControl($this->property->id)['difference'])->toBe(0.0);
});

/**
 * A booking on the test property, created the way the app creates one (ledger synced on commit).
 *
 * @param  array<string, mixed>  $attributes
 */
function glBooking(Property $property, array $attributes): Booking
{
    $category = RoomCategory::query()->where('property_id', $property->id)->firstOrFail();

    return DB::transaction(function () use ($property, $category, $attributes): Booking {
        $booking = $property->bookings()->create([
            'source' => 'direct', 'discount' => 0, 'taxes' => 0, 'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
            ...$attributes,
            'outstanding' => $attributes['total'],
        ]);
        $booking->rooms()->create(['room_category_id' => $category->id, 'meal_plan' => 'EP', 'adults' => 1, 'rate_per_night' => 1000]);

        return $booking;
    });
}

test('room revenue is earned night by night, the rest waits in Unearned Room Revenue', function () {
    $roomRevenueBefore = glBalance($this->property, 'room_revenue');

    // Five nights at 1 000 with 500 off; arrived two days ago, so two nights have passed.
    $stay = glBooking($this->property, [
        'booking_ref_id' => 'GL-INHOUSE', 'status' => BookingStatus::CheckedIn,
        'check_in' => now()->subDays(2)->toDateString(), 'check_out' => now()->addDays(3)->toDateString(),
        'room_total' => 5000, 'discount' => 500, 'total' => 4500,
    ]);

    expect(round(glBalance($this->property, 'room_revenue') - $roomRevenueBefore, 2))->toBe(2000.0)
        ->and(glBalance($this->property, 'discounts'))->toBe(-200.0)
        ->and(glBalance($this->property, 'unearned_room_revenue'))->toBe(2700.0);

    // Closing tonight earns a third night; running it again changes nothing.
    $tonight = CarbonImmutable::parse($this->property->today());
    app(GeneralLedger::class)->recogniseRevenue($this->property, $tonight);
    $this->artisan('ledger:recognise-revenue', ['--property' => $this->property->id, '--date' => $tonight->toDateString()])->assertSuccessful();

    expect(round(glBalance($this->property, 'room_revenue') - $roomRevenueBefore, 2))->toBe(3000.0)
        ->and(glBalance($this->property, 'unearned_room_revenue'))->toBe(1800.0);

    // Checking out early earns the rest.
    $stay->update(['status' => BookingStatus::CheckedOut]);
    app(BookingLedgerSync::class)->sync($stay);

    expect(glBalance($this->property, 'unearned_room_revenue'))->toBe(0.0)
        ->and(glBalance($this->property, 'discounts'))->toBe(-500.0);
});

test('money paid before arrival is an advance deposit until the guest checks in', function () {
    $future = glBooking($this->property, [
        'booking_ref_id' => 'GL-FUTURE', 'status' => BookingStatus::Confirmed,
        'check_in' => now()->addDays(10)->toDateString(), 'check_out' => now()->addDays(12)->toDateString(),
        'room_total' => 2000, 'total' => 2000,
    ]);

    $this->post(route('admin.databases.payments.store', ['property' => $this->property->id]), [
        'guest_name' => 'Asha Rao', 'payment_mode' => 'Cash', 'total_txn_amount' => 800, 'booking_id' => $future->id,
    ])->assertSessionHasNoErrors();

    expect(glBalance($this->property, 'advance_deposits'))->toBe(800.0)
        ->and(glBalance($this->property, 'unallocated_receipts'))->toBe(0.0)
        ->and(app(GeneralLedger::class)->receivableControl($this->property->id)['difference'])->toBe(0.0);

    $future->update(['status' => BookingStatus::CheckedIn]);
    app(BookingLedgerSync::class)->sync($future);

    expect(glBalance($this->property, 'advance_deposits'))->toBe(0.0)
        ->and(app(GeneralLedger::class)->receivableControl($this->property->id)['difference'])->toBe(0.0);
});

test('a receipt not tagged to a booking waits in Unallocated Receipts', function () {
    $payment = Payment::query()->create([
        'property_id' => $this->property->id, 'type' => 'receipt', 'receipt_number' => 'RCPT/UNTAGGED', 'payment_mode' => PaymentMode::Cash,
        'status' => PaymentStatus::Received, 'total_txn_amount' => 1500, 'tagged_amount' => 0, 'untagged_amount' => 1500,
    ]);

    expect(glBalance($this->property, 'unallocated_receipts'))->toBe(1500.0)
        ->and(glBalance($this->property, 'cash'))->toBe(1500.0);

    DB::transaction(function () use ($payment): void {
        BookingPaymentMapping::query()->create(['booking_id' => $this->booking->id, 'payment_id' => $payment->id, 'tagged_amount' => 1000]);
        $payment->update(['tagged_amount' => 1000, 'untagged_amount' => 500]);
    });

    // 1 000 is on the folio now; the cash is still 1 500 in all.
    expect(glBalance($this->property, 'unallocated_receipts'))->toBe(500.0)
        ->and(glBalance($this->property, 'cash'))->toBe(1500.0)
        ->and(app(GeneralLedger::class)->receivableControl($this->property->id)['difference'])->toBe(0.0);

    $payment->update(['status' => PaymentStatus::Cancelled]);

    expect(glBalance($this->property, 'unallocated_receipts'))->toBe(0.0);
});

test('GST splits into CGST and SGST payable the way the invoice does', function () {
    $categoryField = MetaField::query()->firstOrCreate(['key' => 'tax_settings_tax_category'], ['key_label' => 'Tax Category', 'status' => true]);
    $ruleField = MetaField::query()->firstOrCreate(['key' => 'tax_settings_tax_rule'], ['key_label' => 'Tax Rule', 'status' => true]);
    $component = fn (string $name): array => ['component_choice' => $name, 'applied_on' => 'rack_rate', 'tax_value_type' => 'percentage', 'tax_value' => 6, 'define_tax_slab' => false];
    $this->property->metaFieldValues()->create(['meta_fields_key_id' => $categoryField->id, 'enabled' => true, 'data' => json_encode(['categories' => [['id' => 1, 'name' => 'Room']]])]);
    $this->property->metaFieldValues()->create(['meta_fields_key_id' => $ruleField->id, 'enabled' => true, 'data' => json_encode(['rules' => [
        ['tax_category_id' => 1, 'deleted' => false, 'start_date' => null, 'end_date' => null, 'components' => [$component('CGST'), $component('SGST')]],
    ]])]);
    app(PropertySettings::class)->forget();

    glBooking($this->property, [
        'booking_ref_id' => 'GL-GST', 'status' => BookingStatus::Confirmed,
        'check_in' => now()->addDay()->toDateString(), 'check_out' => now()->addDays(3)->toDateString(),
        'room_total' => 2000, 'taxes' => 240, 'total' => 2240,
    ]);

    expect(glBalance($this->property, 'cgst_payable'))->toBe(120.0)
        ->and(glBalance($this->property, 'sgst_payable'))->toBe(120.0);
});
