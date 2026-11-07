<?php

use App\Enums\BookingStatus;
use App\Enums\PaymentMode;
use App\Models\Booking;
use App\Models\CityLedgerAccount;
use App\Models\CompanyProfile;
use App\Models\Currency;
use App\Models\Guest;
use App\Models\Invoice;
use App\Models\Language;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\CityLedger;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create();
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    Room::factory()->count(2)->create(['room_category_id' => $this->category->id]);
    $this->account = CityLedgerAccount::factory()->create(['property_id' => $this->property->id, 'name' => 'Acme Corp', 'tax_number' => '27AAAAA0000A1Z5', 'credit_limit' => null, 'payment_terms_days' => 15]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function companyGuestPayload(array $overrides = []): array
{
    return ['first_name' => 'Asha', 'phone' => '9800000000', 'subscribe_newsletter' => false, ...$overrides];
}

/**
 * A one-room, two-night booking (2,000) of the guest, billed to the guest's company.
 *
 * @param  array<string, mixed>  $overrides
 */
function companyBooking(Guest $guest, string $ref = 'CO-1', array $overrides = []): Booking
{
    test()->actingAs(test()->admin)->post(route('admin.bookings.store', test()->property), [
        'booking_ref_id' => $ref,
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => now()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
        'status' => 'confirmed',
        'discount' => 0,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
        'bill_room_to_guest_company' => true,
        'guest_ids' => [$guest->id],
        'rooms' => [['room_category_id' => test()->category->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
        ...$overrides,
    ])->assertSessionHasNoErrors();

    return Booking::query()->where('booking_ref_id', $ref)->sole();
}

test('the guest form links the company to a City Ledger account, or opens one', function () {
    $this->actingAs($this->admin)
        ->getJson(route('admin.guests.companies', [$this->property, 'q' => '27AAAAA']))
        ->assertOk()
        ->assertJsonPath('companies.0.name', 'Acme Corp');

    $linked = $this->actingAs($this->admin)
        ->postJson(route('admin.guests.store', $this->property), companyGuestPayload(['company' => ['name' => 'Acme Corp'], 'city_ledger_account_id' => $this->account->id]))
        ->assertOk()
        ->assertJsonPath('guest.city_ledger_account.name', 'Acme Corp');

    // Saving the guest again without the field keeps the link.
    $this->actingAs($this->admin)
        ->putJson(route('admin.guests.update', [$this->property, $linked->json('guest.id')]), companyGuestPayload(['last_name' => 'Rao']))
        ->assertOk();
    expect(Guest::query()->find($linked->json('guest.id'))->city_ledger_account_id)->toBe($this->account->id);

    $opened = $this->actingAs($this->admin)
        ->postJson(route('admin.guests.store', $this->property), companyGuestPayload(['company' => ['name' => 'Globex', 'gstin' => '29bbbbb1111b1z5'], 'create_city_ledger_account' => true]))
        ->assertOk();

    $account = CityLedgerAccount::query()->where('name', 'Globex')->sole();
    expect($opened->json('guest.city_ledger_account_id'))->toBe($account->id)
        ->and($account->property_id)->toBe($this->property->id)
        ->and($account->tax_number)->toBe('29BBBBB1111B1Z5')
        ->and(CompanyProfile::query()->where('name', 'Globex')->value('city_ledger_account_id'))->toBe($account->id);
});

test('another property\'s account cannot be linked, and staff without the city ledger permission cannot open one', function () {
    $other = CityLedgerAccount::factory()->create(['property_id' => Property::factory()->create()->id]);

    $this->actingAs($this->admin)
        ->postJson(route('admin.guests.store', $this->property), companyGuestPayload(['city_ledger_account_id' => $other->id]))
        ->assertJsonValidationErrors('city_ledger_account_id');

    $clerk = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'Front Desk Clerk', 'guard_name' => 'web']);
    $clerk->assignRole($role);

    $this->actingAs($clerk)
        ->postJson(route('admin.guests.store', $this->property), companyGuestPayload(['company' => ['name' => 'Initech'], 'create_city_ledger_account' => true]))
        ->assertJsonValidationErrors('create_city_ledger_account');

    expect(CityLedgerAccount::query()->where('name', 'Initech')->exists())->toBeFalse();
});

test('a booking billed to the guest\'s company moves the room bill to the account at checkout, with the invoice made out to the company', function () {
    $guest = Guest::factory()->create(['property_id' => $this->property->id, 'company' => ['name' => 'Acme Corp'], 'city_ledger_account_id' => $this->account->id]);
    $booking = companyBooking($guest);

    expect($booking->city_ledger_account_id)->toBe($this->account->id);

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.show', [$this->property, $booking]))
        ->assertInertia(fn ($page) => $page
            ->where('booking.company_billing.name', 'Acme Corp')
            ->where('booking.company_billing.due_at_checkout', 2000)
            ->where('booking.company_billing.unpaid', 0));

    $booking->update(['status' => BookingStatus::CheckedOut]);

    expect($this->account->balance())->toEqual(2000.0)
        ->and($booking->refresh()->outstandingBalance())->toEqual(0.0);

    $invoice = Invoice::generateForBooking($booking->refresh());
    expect($invoice->paid_by)->toBe('Company')
        ->and($invoice->party_name)->toBe('Acme Corp')
        ->and($invoice->party_gstin)->toBe('27AAAAA0000A1Z5')
        ->and($invoice->city_ledger_account_id)->toBe($this->account->id)
        ->and($invoice->due_date->toDateString())->toBe($invoice->invoice_generation_date->addDays(15)->toDateString());

    // The invoice list shows what the company still owes, and filters on it.
    $this->actingAs($this->admin)
        ->get(route('admin.databases.invoices.index', ['property' => $this->property->id, 'billed_to' => 'company_unpaid']))
        ->assertInertia(fn ($page) => $page
            ->has('invoices.data', 1)
            ->where('invoices.data.0.company_unpaid', 2000));

    app(CityLedger::class)->recordPayment($this->account, 2000, PaymentMode::BankTransfer, 'NEFT-1', $this->admin);

    $this->actingAs($this->admin)
        ->get(route('admin.databases.invoices.index', ['property' => $this->property->id, 'billed_to' => 'company_unpaid']))
        ->assertInertia(fn ($page) => $page->has('invoices.data', 0));
});

test('the company\'s credit limit refuses a booking it cannot cover', function () {
    $this->account->update(['credit_limit' => 1500]);
    $guest = Guest::factory()->create(['property_id' => $this->property->id, 'company' => ['name' => 'Acme Corp'], 'city_ledger_account_id' => $this->account->id]);

    $this->actingAs($this->admin)->post(route('admin.bookings.store', $this->property), [
        'booking_ref_id' => 'CO-LIMIT',
        'source' => 'direct',
        'type' => 'walk_in',
        'check_in' => now()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
        'status' => 'confirmed',
        'discount' => 0,
        'room_bill_to' => 'to_guest',
        'extras_bill_to' => 'to_guest',
        'bill_room_to_guest_company' => true,
        'guest_ids' => [$guest->id],
        'rooms' => [['room_category_id' => $this->category->id, 'room_id' => null, 'meal_plan' => 'EP', 'adults' => 2, 'children' => 0, 'infants' => 0, 'rate_per_night' => 1000]],
    ])->assertSessionHasErrors('bill_room_to_guest_company');

    expect(Booking::query()->where('booking_ref_id', 'CO-LIMIT')->exists())->toBeFalse();
});

test('a booking whose guest company has no account bills the guest, and the page says so', function () {
    $guest = Guest::factory()->create(['property_id' => $this->property->id, 'company' => ['name' => 'Unlinked Ltd']]);
    $booking = companyBooking($guest, 'CO-2');

    expect($booking->city_ledger_account_id)->toBeNull();

    $this->actingAs($this->admin)
        ->get(route('admin.bookings.show', [$this->property, $booking]))
        ->assertInertia(fn ($page) => $page->where('booking.company_billing.linked', false));

    $booking->update(['status' => BookingStatus::CheckedOut]);
    expect($booking->refresh()->outstandingBalance())->toEqual(2000.0);
});
