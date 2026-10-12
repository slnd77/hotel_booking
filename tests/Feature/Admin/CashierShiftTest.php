<?php

use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Models\CashierShift;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use App\Services\DailyCashierReport;
use App\Services\NightAuditSettings;
use App\Services\PropertySettings;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->property = Property::factory()->create(['timezone' => 'Asia/Kolkata']);
    $this->cashier = User::factory()->create(['name' => 'Ravi']);
    $this->cashier->assignRole(Role::findOrCreate('Clerk', 'web'));
    $this->cashier->givePermissionTo(Permission::findOrCreate('front_office.cashier_shift', 'web'));
});

/**
 * @param  array<string, mixed>  $overrides
 */
function shiftPayment(Property $property, float $amount, array $overrides = []): Payment
{
    return Payment::create([
        'property_id' => $property->id, 'type' => 'receipt', 'guest_name' => 'Asha Rao', 'payment_mode' => PaymentMode::Cash,
        'total_txn_amount' => $amount, 'tagged_amount' => 0, 'untagged_amount' => $amount,
        'payment_creation_date' => now(), 'payment_transaction_date' => now(), 'status' => PaymentStatus::Received,
        ...$overrides,
    ]);
}

test('a cashier opens a shift, the money they take counts in it, and closing works out the variance', function () {
    $this->actingAs($this->cashier)
        ->post(route('admin.cashierShifts.open', $this->property), ['opening_float' => 2000, 'opening_notes' => 'Morning'])
        ->assertSessionHas('success');

    $this->post(route('admin.cashierShifts.open', $this->property), ['opening_float' => 500])
        ->assertSessionHasErrors(['opening_float' => 'You already have a shift open at this property.']);

    shiftPayment($this->property, 1500);
    shiftPayment($this->property, 800, ['payment_mode' => PaymentMode::UPI]);
    shiftPayment($this->property, 300, ['type' => 'refund', 'status' => PaymentStatus::Refunded]);
    shiftPayment($this->property, 999, ['status' => PaymentStatus::Cancelled]);
    $elsewhere = shiftPayment(Property::factory()->create(), 400);
    $shift = CashierShift::query()->sole();

    expect($shift->payments()->count())->toBe(4)
        ->and($elsewhere->cashier_shift_id)->toBeNull();

    $this->get(route('admin.cashierShifts.show', $this->property))
        ->assertInertia(fn ($page) => $page
            ->component('admin/cashier-shifts/Show')
            ->where('current.cash_receipts', 1500)
            ->where('current.cash_refunds', 300)
            ->where('current.expected_cash', 3200)
            ->where('current.receipts_total', 2300)
            ->where('current.by_mode', fn ($modes): bool => collect($modes)->pluck('mode')->all() === ['Cash', 'UPI']));

    // The next cashier takes over the drawer.
    $next = User::factory()->create(['name' => 'Meera']);

    $this->post(route('admin.cashierShifts.close', $this->property), ['cash_counted' => 3100, 'closing_notes' => 'Short 100', 'handed_over_to' => $next->id])
        ->assertSessionHas('success', 'Shift closed. The drawer is short by 100.00.');

    $shift->refresh();
    $handedOver = CashierShift::query()->where('user_id', $next->id)->sole();

    expect($shift->closed_at)->not->toBeNull()
        ->and((float) $shift->expected_cash)->toBe(3200.0)
        ->and((float) $shift->variance)->toBe(-100.0)
        ->and($shift->handed_over_to)->toBe($next->id)
        ->and($handedOver->isOpen())->toBeTrue()
        ->and((float) $handedOver->opening_float)->toBe(3100.0);

    // Payments now go to nobody's shift until the cashier opens another one.
    expect(shiftPayment($this->property, 50)->cashier_shift_id)->toBeNull();

    $this->post(route('admin.cashierShifts.close', $this->property), ['cash_counted' => 0])
        ->assertSessionHasErrors(['cash_counted' => 'You have no shift open at this property.']);
});

test('with the shift required, payments are recorded only while the cashier has a shift open', function () {
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    app(NightAuditSettings::class)->save($this->property, ['require_cashier_shift' => true]);
    app(PropertySettings::class)->forget();
    $payment = ['guest_name' => 'Asha Rao', 'payment_mode' => 'Cash', 'total_txn_amount' => 500];

    $this->actingAs($admin)
        ->post(route('admin.databases.payments.store', ['property' => $this->property->id]), $payment)
        ->assertSessionHasErrors(['total_txn_amount' => 'Open your cashier shift (Cashier Shift page) before recording a payment.']);

    $this->post(route('admin.cashierShifts.open', $this->property), ['opening_float' => 0]);
    $this->post(route('admin.databases.payments.store', ['property' => $this->property->id]), $payment)->assertSessionHasNoErrors();

    expect(Payment::query()->where('property_id', $this->property->id)->sole()->cashier_shift_id)->toBe(CashierShift::query()->sole()->id);
});

test('the daily cashier report lists the day\'s shifts', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-03 06:00:00', 'UTC'));
    $this->actingAs($this->cashier)->post(route('admin.cashierShifts.open', $this->property), ['opening_float' => 1000]);
    shiftPayment($this->property, 500);
    $this->post(route('admin.cashierShifts.close', $this->property), ['cash_counted' => 1500]);

    $sheets = app(DailyCashierReport::class)->report($this->property, CarbonImmutable::parse('2026-10-03'))['sheets'];
    $shifts = collect($sheets)->firstWhere('title', 'Shifts');

    expect($shifts['rows'])->toBe([
        ['Ravi', '03 Oct, 2026 11:30', '03 Oct, 2026 11:30', 1000, 500, 0, 1500, 1500, 0, '--', '--'],
    ]);
});

test('cashiers see their own shifts, and all shifts only with the permission', function () {
    $other = User::factory()->create(['name' => 'Meera']);
    CashierShift::factory()->closed()->create(['property_id' => $this->property->id, 'user_id' => $other->id]);
    CashierShift::factory()->closed()->create(['property_id' => $this->property->id, 'user_id' => $this->cashier->id]);

    $this->actingAs($this->cashier)->get(route('admin.cashierShifts.show', $this->property))
        ->assertInertia(fn ($page) => $page->where('viewAll', false)->has('history', 1)->where('history.0.cashier', 'Ravi'));

    $this->cashier->givePermissionTo(Permission::findOrCreate('front_office.cashier_shifts_view_all', 'web'));

    $this->actingAs($this->cashier)->get(route('admin.cashierShifts.show', $this->property))
        ->assertInertia(fn ($page) => $page->where('viewAll', true)->has('history', 2));

    $outsider = User::factory()->create();
    $outsider->assignRole(Role::findOrCreate('Clerk', 'web'));
    $this->actingAs($outsider)->get(route('admin.cashierShifts.show', $this->property))->assertForbidden();
});
