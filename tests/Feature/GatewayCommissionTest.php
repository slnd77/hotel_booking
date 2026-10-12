<?php

use App\Classes\PaymentGateway\Cashfree;
use App\Classes\PaymentGateway\Razorpay;
use App\Models\Currency;
use App\Models\Language;
use App\Models\MetaField;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Services\DailyCashierReport;
use App\Services\GatewayCommissions;
use App\Services\PropertySettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->property = Property::factory()->create();
    $field = MetaField::query()->firstOrCreate(['key' => 'booking_engine_settings'], ['key_label' => 'Booking Engine', 'status' => true]);
    // 2% plus 3 a payment, and 18% tax on that fee.
    $this->property->metaFieldValues()->create(['meta_fields_key_id' => $field->id, 'enabled' => true, 'data' => json_encode(['allowed_payment_gateways' => [
        ['id' => 'test', 'name' => 'Test', 'enabled' => true, 'commission_percent' => 2, 'commission_fixed' => 3, 'commission_tax_percent' => 18],
    ]])]);
    app(PropertySettings::class)->forgetMetaFields();
});

test('an online booking records the gateway commission from the configured rate', function () {
    $category = RoomCategory::factory()->create(['property_id' => $this->property->id, 'meal_plans' => ['EP']]);
    sellOnBookingEngine($this->property, $category, 1000);

    bookThroughEngine($this, $this->property, [
        'check_in' => now()->addDays(3)->toDateString(),
        'check_out' => now()->addDays(5)->toDateString(),
        'rooms' => [['category_id' => $category->id, 'meal_plan' => 'EP']],
    ])->assertOk();

    // (2% of 2 000 + 3) + 18% = 50.74
    $payment = Payment::query()->where('property_id', $this->property->id)->sole();
    expect((float) $payment->pg_commission_amount)->toBe(50.74);

    $receipts = collect(app(DailyCashierReport::class)->report($this->property, CarbonImmutable::parse($this->property->today()))['sheets'])->firstWhere('title', 'Receipts');
    $commissionColumn = array_search('Total PG Commission Amount (If applicable)', $receipts['headers'], true);

    expect($receipts['rows'][0][$commissionColumn])->toBe(50.74)
        ->and(end($receipts['rows'])[$commissionColumn])->toBe(50.74);
});

test('the fee the gateway reports wins over the configured rate', function () {
    expect(app(GatewayCommissions::class)->for($this->property, 'test', 1000, ['fee' => 20, 'fee_tax' => 3.6]))->toBe(23.6)
        ->and(app(GatewayCommissions::class)->for($this->property, 'razorpay', 1000))->toBeNull();

    // A live Razorpay payment reads its fee (tax included, in paise) once the signature checks out.
    Http::fake(['api.razorpay.com/v1/payments/pay_1' => Http::response(['id' => 'pay_1', 'fee' => 2360, 'tax' => 360])]);
    $signature = hash_hmac('sha256', 'order_1|pay_1', 'live_secret');

    $verification = (new Razorpay('rzp_live_key', 'live_secret'))->verifyPayment(['order_id' => 'order_1', 'transaction_id' => 'pay_1', 'signature' => $signature]);

    expect($verification['success'])->toBeTrue()
        ->and($verification['fee'])->toBe(20.0)
        ->and($verification['fee_tax'])->toBe(3.6);
});

test('a settled cashfree order reports what cashfree kept, an unsettled one leaves it to the rate', function () {
    Http::fake([
        'sandbox.cashfree.com/pg/orders/order_1/settlements' => Http::response(['cf_payment_id' => 1, 'service_charge' => 20, 'service_tax' => 3.6]),
        'sandbox.cashfree.com/pg/orders/order_2/settlements' => Http::response(['message' => 'Settlement not found'], 404),
        'sandbox.cashfree.com/pg/orders/*' => Http::response(['order_status' => 'PAID', 'order_amount' => 1000]),
    ]);
    $cashfree = new Cashfree('cf_live_app', 'cf_secret', 'sandbox');

    $settled = $cashfree->verifyPayment(['order_id' => 'order_1']);
    $unsettled = $cashfree->verifyPayment(['order_id' => 'order_2']);

    expect($settled['success'])->toBeTrue()
        ->and($settled['fee'])->toBe(20.0)
        ->and($settled['fee_tax'])->toBe(3.6)
        ->and($unsettled['success'])->toBeTrue()
        ->and($unsettled)->not->toHaveKey('fee');
});

test('a live razorpay payment without a signature is not verified', function () {
    $verification = (new Razorpay('rzp_live_key', 'live_secret'))->verifyPayment(['order_id' => 'order_1', 'transaction_id' => 'pay_1']);

    expect($verification['success'])->toBeFalse();
});
