<?php

use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    Currency::create(['code' => 'INR', 'name' => 'Indian Rupee']);
    Language::create(['code' => 'en', 'name' => 'English']);
});

function createTestPayment(Property $property, array $overrides = []): Payment
{
    $createdAt = $overrides['created_at'] ?? null;
    unset($overrides['created_at']);

    $payment = Payment::create(array_merge([
        'property_id' => $property->id,
        'type' => 'receipt',
        'voucher_number' => 'CON'.fake()->numerify('#####'),
        'receipt_number' => 'RCPT/2026-27/'.fake()->numerify('#####'),
        'status' => 'Received',
        'guest_name' => fake()->name(),
        'payment_mode' => 'E-Collect',
        'total_txn_amount' => 9660.00,
        'booking_source' => 'Direct',
        'payment_creation_date' => now(),
        'payment_transaction_date' => now(),
    ], $overrides));

    if ($createdAt) {
        $payment->forceFill(['created_at' => $createdAt])->save();
    }

    return $payment;
}

test('payments index is scoped to the selected property and shows receipts by default', function () {
    $property = Property::factory()->create();
    $otherProperty = Property::factory()->create();

    $payment1 = createTestPayment($property, ['guest_name' => 'Dhanu Mulchandani', 'created_at' => now()->subDay()]);
    $payment2 = createTestPayment($property, ['guest_name' => 'Dheeraj Wadhwa', 'created_at' => now()]);
    createTestPayment($otherProperty, ['guest_name' => 'Other Property Guest']);

    $response = $this->actingAs($this->admin)->get("/admin/databases/payments?property={$property->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/databases/Payments')
        ->where('property.id', $property->id)
        ->where('activeTab', 'receipts')
        ->has('payments.data', 2)
        ->where('payments.data.0.id', $payment2->id)
        ->where('payments.data.1.id', $payment1->id));
});

test('payments index can switch to refunds tab', function () {
    $property = Property::factory()->create();

    $receipt = createTestPayment($property, ['type' => 'receipt']);
    $refund = createTestPayment($property, ['type' => 'refund', 'total_txn_amount' => 1500.00]);

    $response = $this->actingAs($this->admin)->get("/admin/databases/payments?property={$property->id}&tab=refunds");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/databases/Payments')
        ->where('activeTab', 'refunds')
        ->has('payments.data', 1)
        ->where('payments.data.0.id', $refund->id));
});

test('payments index filters by query term q', function () {
    $property = Property::factory()->create();

    $match = createTestPayment($property, ['voucher_number' => 'CON002699', 'guest_name' => 'Special Guest']);
    $other = createTestPayment($property, ['voucher_number' => 'CON001111', 'guest_name' => 'Another Person']);

    $response = $this->actingAs($this->admin)->get("/admin/databases/payments?property={$property->id}&q=Special");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('payments.data', 1)
        ->where('payments.data.0.id', $match->id));
});

test('downloading payments list is rejected without an eligible date filter', function () {
    $property = Property::factory()->create();
    createTestPayment($property);

    $response = $this->actingAs($this->admin)->get("/admin/databases/payments/download?property={$property->id}");

    $response->assertStatus(422);
});

test('downloading payments list succeeds with a date filter of 30 days or fewer', function () {
    $property = Property::factory()->create();
    createTestPayment($property);

    $from = now()->subDays(5)->toDateString();
    $to = now()->addDays(5)->toDateString();

    $response = $this->actingAs($this->admin)->get(
        "/admin/databases/payments/download?property={$property->id}&date_from={$from}&date_to={$to}"
    );

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $this->admin->id,
        'property_id' => $property->id,
        'action' => 'payment_list_downloaded',
    ]);
});

test('can manually store a payment receipt', function () {
    $property = Property::factory()->create();

    $response = $this->actingAs($this->admin)->post('/admin/databases/payments', [
        'property' => $property->id,
        'guest_name' => 'Ayan Saha',
        'payment_mode' => 'E-Collect',
        'total_txn_amount' => 4830.00,
        'booking_source' => 'Direct',
        'voucher_number' => 'CON002695',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('payments', [
        'property_id' => $property->id,
        'guest_name' => 'Ayan Saha',
        'total_txn_amount' => 4830.00,
        'status' => 'Received',
    ]);
});

test('can cancel a payment receipt with a cancellation reason', function () {
    $property = Property::factory()->create();
    $payment = createTestPayment($property, ['status' => 'Received']);

    $response = $this->actingAs($this->admin)->post("/admin/databases/payments/{$payment->id}/cancel", [
        'cancellation_reason' => 'Guest requested refund/cancellation',
    ]);

    $response->assertRedirect();
    $payment->refresh();
    expect($payment->status)->toBe(PaymentStatus::Cancelled)
        ->and($payment->cancellation_reason)->toBe('Guest requested refund/cancellation');
});

test('booking engine paid reservation creates a payment receipt visible on the payments page', function () {
    $property = Property::factory()->create();
    $category = RoomCategory::factory()->create(['property_id' => $property->id, 'meal_plans' => []]);

    sellOnBookingEngine($property, $category, 4250);

    $response = bookThroughEngine($this, $property, [
        'check_in' => now()->addDays(2)->toDateString(),
        'check_out' => now()->addDays(4)->toDateString(),
        'guest' => ['name' => 'Prarthana Sen', 'email' => 'prarthana@example.com', 'phone' => '9876543210'],
        'rooms' => [['category_id' => $category->id, 'meal_plan' => 'EP']],
    ]);

    $response->assertOk();
    $bookingRefId = $response->json('booking.booking_ref_id');

    $this->assertDatabaseHas('payments', [
        'property_id' => $property->id,
        'voucher_number' => $bookingRefId,
        'guest_name' => 'Prarthana Sen',
        'total_txn_amount' => 8500.00,
        'booking_source' => 'Booking Engine',
        'status' => 'Received',
    ]);

    $response = $this->actingAs($this->admin)->get("/admin/databases/payments?property={$property->id}");
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/databases/Payments')
        ->has('payments.data', 1)
        ->where('payments.data.0.voucher_number', $bookingRefId)
        ->where('payments.data.0.guest_name', 'Prarthana Sen')
        ->where('payments.data.0.total_txn_amount', fn ($val) => (float) $val === 8500.0));
});

test('creating a booking automatically logs a payment in the payments table', function () {
    $property = Property::factory()->create();

    $booking = $property->bookings()->create([
        'booking_ref_id' => 'BK-AUTO-001',
        'source' => 'direct',
        'status' => 'confirmed',
        'total' => 6500.00,
        'outstanding' => 0.00,
        'pay_at_hotel' => false,
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(3)->toDateString(),
    ]);

    $this->assertDatabaseHas('payments', [
        'property_id' => $property->id,
        'booking_id' => $booking->id,
        'voucher_number' => 'BK-AUTO-001',
        'total_txn_amount' => 6500.00,
        'tagged_amount' => 6500.00,
        'untagged_amount' => 0.00,
        'status' => 'Received',
    ]);
});

test('adding manual payment to a partially paid booking does not zero out or make pending the previous payment', function () {
    $property = Property::factory()->create();

    $booking = $property->bookings()->create([
        'booking_ref_id' => 'BK-PARTIAL-001',
        'source' => 'booking_engine',
        'status' => 'confirmed',
        'total' => 5000.00,
        'outstanding' => 2500.00,
        'pay_at_hotel' => true,
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
    ]);

    // Initial online payment of 2500
    $initialPayment = Payment::create([
        'property_id' => $property->id,
        'booking_id' => $booking->id,
        'type' => 'receipt',
        'voucher_number' => $booking->booking_ref_id,
        'receipt_number' => 'RCPT/2026-27/00001',
        'status' => PaymentStatus::Received,
        'payment_mode' => PaymentMode::Online,
        'guest_name' => 'Initial Guest',
        'total_txn_amount' => 2500.00,
        'tagged_amount' => 2500.00,
        'untagged_amount' => 0.00,
    ]);

    // Now add new manual cash payment of 2500
    $response = $this->actingAs($this->admin)->post('/admin/databases/payments', [
        'property_id' => $property->id,
        'booking_id' => $booking->id,
        'guest_name' => 'Initial Guest',
        'payment_mode' => 'Cash',
        'total_txn_amount' => 2500.00,
    ]);

    $response->assertSessionHasNoErrors();

    // Verify initial payment is UNTOUCHED (still 2500 and Received)
    $initialPayment->refresh();
    expect((float) $initialPayment->total_txn_amount)->toBe(2500.00)
        ->and($initialPayment->status)->toBe(PaymentStatus::Received);

    // Verify booking now has 0 outstanding
    $booking->refresh();
    expect((float) $booking->outstanding)->toBe(0.00);

    // Verify bookings_payments_mappings has both payments
    $this->assertDatabaseHas('bookings_payments_mappings', [
        'booking_id' => $booking->id,
        'payment_id' => $initialPayment->id,
        'tagged_amount' => 2500.00,
    ]);

    expect($booking->payments()->count())->toBe(2);
});

test('a single payment can be tagged to multiple bookings via mappings', function () {
    $property = Property::factory()->create();

    $booking1 = $property->bookings()->create([
        'booking_ref_id' => 'BK-MULTI-1',
        'source' => 'direct',
        'status' => 'confirmed',
        'total' => 3000.00,
        'outstanding' => 3000.00,
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
    ]);

    $booking2 = $property->bookings()->create([
        'booking_ref_id' => 'BK-MULTI-2',
        'source' => 'direct',
        'status' => 'confirmed',
        'total' => 2000.00,
        'outstanding' => 2000.00,
        'check_in' => now()->addDay()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
    ]);

    // Single payment of 5000 allocated 3000 to booking1 and 2000 to booking2
    $response = $this->actingAs($this->admin)->post('/admin/databases/payments', [
        'property_id' => $property->id,
        'guest_name' => 'Corporate Payer',
        'payment_mode' => 'Bank Transfer',
        'total_txn_amount' => 5000.00,
        'allocations' => [
            ['booking_id' => $booking1->id, 'tagged_amount' => 3000.00],
            ['booking_id' => $booking2->id, 'tagged_amount' => 2000.00],
        ],
    ]);

    $response->assertSessionHasNoErrors();

    $this->assertDatabaseHas('payments', [
        'property_id' => $property->id,
        'total_txn_amount' => 5000.00,
        'tagged_amount' => 5000.00,
        'untagged_amount' => 0.00,
    ]);

    $this->assertDatabaseHas('bookings_payments_mappings', [
        'booking_id' => $booking1->id,
        'tagged_amount' => 3000.00,
    ]);

    $this->assertDatabaseHas('bookings_payments_mappings', [
        'booking_id' => $booking2->id,
        'tagged_amount' => 2000.00,
    ]);

    expect((float) $booking1->refresh()->outstanding)->toBe(0.00)
        ->and((float) $booking2->refresh()->outstanding)->toBe(0.00);
});

test('a received payment has no cancellation reason, so its receipt is not shown as cancelled', function () {
    $property = Property::factory()->create();
    createTestPayment($property, ['status' => 'Received', 'cancellation_reason' => null]);

    $this->actingAs($this->admin)
        ->get("/admin/databases/payments?property={$property->id}")
        ->assertInertia(fn ($page) => $page->where('payments.data.0.cancellation_reason', null));
});
