<?php

use App\Models\Currency;
use App\Models\Language;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use App\Services\DailyCashierReport;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);
    $inr = Currency::firstOrCreate(['code' => 'INR'], ['name' => 'Indian Rupee']);
    Currency::firstOrCreate(['code' => 'GBP'], ['name' => 'Pound Sterling']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);

    $this->property = Property::factory()->create(['currency_id' => $inr->id, 'timezone' => 'Asia/Kolkata']);
});

test('exchange rates apply from their date, and a payment in another currency is converted on the server', function () {
    $today = CarbonImmutable::parse($this->property->today());

    $this->post(route('admin.exchangeRates.store', $this->property), ['currency_code' => 'GBP', 'rate' => 105.5, 'effective_date' => $today->subDay()->toDateString()])->assertSessionHasNoErrors();
    $this->post(route('admin.exchangeRates.store', $this->property), ['currency_code' => 'GBP', 'rate' => 110, 'effective_date' => $today->addDays(5)->toDateString()])->assertSessionHasNoErrors();
    $this->post(route('admin.exchangeRates.store', $this->property), ['currency_code' => 'INR', 'rate' => 1, 'effective_date' => $today->toDateString()])
        ->assertSessionHasErrors(['currency_code' => 'This is the property\'s own currency.']);

    $this->getJson(route('admin.exchangeRates.current', $this->property))
        ->assertOk()
        ->assertJsonPath('base', 'INR')
        ->assertJsonPath('rates.0.currency_code', 'GBP')
        ->assertJsonPath('rates.0.rate', 105.5);

    // The form's amount in rupees is ignored: 100 GBP at today's rate.
    $this->post(route('admin.databases.payments.store', ['property' => $this->property->id]), [
        'guest_name' => 'Tom Hardy', 'payment_mode' => 'Cash', 'currency_code' => 'GBP', 'currency_amount' => 100, 'total_txn_amount' => 1,
    ])->assertSessionHasNoErrors();

    $payment = Payment::query()->sole();

    expect((float) $payment->total_txn_amount)->toBe(10550.0)
        ->and($payment->currency_code)->toBe('GBP')
        ->and((float) $payment->currency_amount)->toBe(100.0)
        ->and((float) $payment->exchange_rate)->toBe(105.5);

    $receipts = collect(app(DailyCashierReport::class)->report($this->property, $today)['sheets'])->firstWhere('title', 'Receipts');
    $row = array_combine($receipts['headers'], $receipts['rows'][0]);

    expect($row['Currency'])->toBe('GBP')
        ->and($row['Currency Amount'])->toBe(100)
        ->and($row['Total Amount'])->toBe(10550);
});

test('a payment in a currency with no rate is refused', function () {
    $this->post(route('admin.databases.payments.store', ['property' => $this->property->id]), [
        'guest_name' => 'Tom Hardy', 'payment_mode' => 'Cash', 'currency_code' => 'GBP', 'currency_amount' => 100,
    ])->assertSessionHasErrors('currency_code');

    expect(Payment::query()->count())->toBe(0);
});
