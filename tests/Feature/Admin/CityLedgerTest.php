<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\ChargeCode;
use App\Models\CityLedgerAccount;
use App\Models\CityLedgerEntry;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
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

    // Checked out today, 5 900 still owed.
    $this->booking = DB::transaction(function () use ($category, $room): Booking {
        $booking = $this->property->bookings()->create([
            'booking_ref_id' => 'CL-BOOKING', 'source' => 'direct', 'status' => BookingStatus::CheckedOut,
            'check_in' => now()->subDays(5)->toDateString(), 'check_out' => now()->toDateString(),
            'room_total' => 5000, 'discount' => 0, 'taxes' => 900, 'total' => 5900, 'outstanding' => 5900,
            'room_bill_to' => 'to_company', 'extras_bill_to' => 'to_guest',
        ]);
        $booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $room->id, 'meal_plan' => 'EP', 'adults' => 1, 'rate_per_night' => 1000]);

        return $booking;
    });
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cityLedgerAccountFields(array $overrides = []): array
{
    return ['name' => 'Acme Travels', 'code' => 'ACME', 'account_type' => 'company', 'credit_limit' => 5000, 'payment_terms_days' => 30, 'is_active' => true, ...$overrides];
}

test('a booking\'s bill moves to a city ledger account within its credit limit, and the account pays it', function () {
    $this->post(route('admin.databases.cityLedger.store', $this->property), cityLedgerAccountFields())->assertRedirect();
    $account = CityLedgerAccount::query()->sole();

    $this->post(route('admin.databases.cityLedger.transfer', $account), ['booking_ref_id' => 'CL-BOOKING'])
        ->assertSessionHasErrors(['amount' => 'This would take the account over its credit limit of 5,000.00 (it owes 0.00).']);

    $this->put(route('admin.databases.cityLedger.update', $account), cityLedgerAccountFields(['credit_limit' => 10000]))->assertSessionHasNoErrors();

    $this->post(route('admin.databases.cityLedger.transfer', $account), ['booking_ref_id' => 'CL-BOOKING', 'amount' => 6000])
        ->assertSessionHasErrors(['amount' => 'At most 5,900.00 is owed on this booking.']);
    $this->post(route('admin.databases.cityLedger.transfer', $account), ['booking_ref_id' => 'CL-BOOKING'])->assertSessionHasNoErrors();

    $charge = CityLedgerEntry::query()->sole();

    expect((float) $charge->amount)->toBe(5900.0)
        ->and($charge->due_date->toDateString())->toBe(now()->addDays(30)->toDateString())
        ->and($charge->booking_id)->toBe($this->booking->id)
        ->and((float) $this->booking->refresh()->outstanding)->toBe(0.0)
        ->and($account->balance())->toBe(5900.0)
        ->and(Transaction::query()->find($charge->transaction_id)->charge_code_id)->toBe(ChargeCode::system(ChargeCode::CITY_LEDGER)->id);

    $this->post(route('admin.databases.cityLedger.payment', $account), ['amount' => 2000, 'payment_mode' => 'Bank Transfer', 'reference' => 'UTR99'])->assertSessionHasNoErrors();

    $payment = Payment::query()->where('collection_source', 'City Ledger')->sole();

    expect((float) $payment->total_txn_amount)->toBe(2000.0)
        ->and($payment->payee_name)->toBe('Acme Travels')
        ->and($payment->payment_ref_id)->toBe('UTR99')
        ->and($account->balance())->toBe(3900.0);

    // 45 days on, what is left of the bill is 15 days past due.
    $this->travel(45)->days();

    $this->get(route('admin.databases.cityLedger.index', ['property' => $this->property->id]))
        ->assertInertia(fn ($page) => $page
            ->component('admin/databases/CityLedger')
            ->where('accounts.0.balance', 3900)
            ->where('accounts.0.available_credit', 6100)
            ->where('accounts.0.buckets.days_1_30', 3900)
            ->where('totals.overdue', 3900));

    $this->get(route('admin.databases.cityLedger.show', $account))
        ->assertInertia(fn ($page) => $page
            ->where('statement.opening', 0)
            ->where('statement.closing', 3900)
            ->has('statement.entries', 2));

    $this->get(route('admin.databases.cityLedger.statement', $account))
        ->assertOk()
        ->assertSee('Acme Travels')
        ->assertSee('CL-BOOKING')
        ->assertSee('3,900.00');
});

test('a credit adjustment lowers the balance and settles the oldest bill first', function () {
    $account = CityLedgerAccount::factory()->create(['property_id' => $this->property->id, 'credit_limit' => null, 'payment_terms_days' => 0]);
    $this->post(route('admin.databases.cityLedger.transfer', $account), ['booking_ref_id' => 'CL-BOOKING', 'amount' => 1000]);
    $this->travel(40)->days();
    $this->post(route('admin.databases.cityLedger.transfer', $account), ['booking_ref_id' => 'CL-BOOKING', 'amount' => 2000]);
    $this->post(route('admin.databases.cityLedger.adjust', $account), ['amount' => -1500, 'description' => 'Agreed discount'])->assertSessionHasNoErrors();

    // The 1 000 bill is settled, and 500 of the 2 000 one; what remains is due today.
    $this->get(route('admin.databases.cityLedger.show', $account))
        ->assertInertia(fn ($page) => $page
            ->where('ageing.balance', 1500)
            ->where('ageing.buckets.current', 1500)
            ->where('ageing.overdue', 0));
});

test('the city ledger needs its permissions', function () {
    $account = CityLedgerAccount::factory()->create(['property_id' => $this->property->id]);
    $clerk = User::factory()->create();
    $clerk->assignRole(Role::findOrCreate('Clerk', 'web'));
    $clerk->givePermissionTo(Permission::findOrCreate('database.city_ledger.view', 'web'));

    $this->actingAs($clerk)->get(route('admin.databases.cityLedger.show', $account))->assertOk();
    $this->actingAs($clerk)->post(route('admin.databases.cityLedger.payment', $account), ['amount' => 10, 'payment_mode' => 'Cash'])->assertForbidden();
});

test('the booking page offers the property\'s active accounts to bill to, and the balance in other currencies', function () {
    CityLedgerAccount::factory()->create(['property_id' => $this->property->id, 'name' => 'Acme Travels']);
    CityLedgerAccount::factory()->create(['property_id' => $this->property->id, 'name' => 'Closed Co', 'is_active' => false]);
    CityLedgerAccount::factory()->create(['property_id' => Property::factory()->create()->id, 'name' => 'Elsewhere']);

    $this->get(route('admin.bookings.show', [$this->property, $this->booking]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/bookings/Show')
            ->where('cityLedgerAccounts', fn ($accounts): bool => collect($accounts)->pluck('name')->all() === ['Acme Travels'])
            ->has('exchangeRates'));
});

test('an account page is in its property\'s currency', function () {
    $account = CityLedgerAccount::factory()->create(['property_id' => $this->property->id]);

    $this->get(route('admin.databases.cityLedger.show', $account))
        ->assertInertia(fn ($page) => $page->where('currency.code', Currency::forProperty($this->property->fresh('currency'))['code']));
});
