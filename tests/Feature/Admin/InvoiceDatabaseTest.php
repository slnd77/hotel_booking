<?php

use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Guest;
use App\Models\Invoice;
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

test('booking, payment, and invoice models properly cast status and payment mode to enum classes', function () {
    $property = Property::factory()->create();

    $booking = Booking::create([
        'property_id' => $property->id,
        'booking_ref_id' => 'BOOK-TEST-1',
        'source' => 'direct',
        'check_in' => now()->toDateString(),
        'check_out' => now()->addDays(2)->toDateString(),
        'status' => 'confirmed',
        'total' => 5000.00,
        'outstanding' => 0.00,
    ]);

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->fresh()->status->value)->toBe('confirmed');

    $payment = Payment::create([
        'property_id' => $property->id,
        'booking_id' => $booking->id,
        'type' => 'receipt',
        'receipt_number' => 'RCPT/2026-27/00001',
        'total_txn_amount' => 5000.00,
        'status' => 'Received',
        'payment_mode' => 'Online',
    ]);

    expect($payment->fresh()->status)->toBe(PaymentStatus::Received)
        ->and($payment->fresh()->status->value)->toBe('Received')
        ->and($payment->fresh()->payment_mode)->toBe(PaymentMode::Online)
        ->and($payment->fresh()->payment_mode->value)->toBe('Online');

    $invoice = Invoice::create([
        'property_id' => $property->id,
        'booking_id' => $booking->id,
        'invoice_number' => 'INVCON00001',
        'invoice_generation_date' => now(),
        'status' => 'Bill Generated',
        'invoice_amount' => 5000.00,
        'taxable_amount' => 4500.00,
        'total_tax' => 500.00,
        'amount_paid' => 5000.00,
        'invoice_outstanding_amount' => 0.00,
    ]);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::BillGenerated)
        ->and($invoice->fresh()->status->value)->toBe('Bill Generated');
});

test('checking out a booking automatically generates an invoice with InvoiceStatus enum', function () {
    $property = Property::factory()->create(['abbreviation' => 'CON']);
    $category = RoomCategory::factory()->create(['property_id' => $property->id]);
    $booking = Booking::create([
        'property_id' => $property->id,
        'booking_ref_id' => 'CON001234',
        'source' => 'direct',
        'check_in' => now()->subDays(2)->toDateString(),
        'check_out' => now()->toDateString(),
        'status' => BookingStatus::Confirmed,
        'room_total' => 6000.00,
        'discount' => 0.00,
        'taxes' => 600.00,
        'total' => 6600.00,
        'outstanding' => 0.00,
    ]);

    $guest = Guest::factory()->create(['property_id' => $property->id]);
    $booking->guests()->attach($guest);

    $response = $this->actingAs($this->admin)->patch("/admin/properties/{$property->id}/bookings/{$booking->id}/status", [
        'status' => 'checked_out',
    ]);

    $response->assertRedirect();
    $booking->refresh();

    expect($booking->status)->toBe(BookingStatus::CheckedOut);

    $invoice = Invoice::query()->where('booking_id', $booking->id)->first();
    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe(InvoiceStatus::BillGenerated)
        ->and($invoice->invoice_number)->toBe('INVCON00001')
        ->and((float) $invoice->invoice_amount)->toBe(6600.00);
});

test('invoices index page displays invoices for the selected property', function () {
    $property = Property::factory()->create(['abbreviation' => 'TST']);
    $booking = Booking::create([
        'property_id' => $property->id,
        'booking_ref_id' => 'TST009988',
        'source' => 'direct',
        'check_in' => now()->toDateString(),
        'check_out' => now()->addDays(1)->toDateString(),
        'status' => BookingStatus::CheckedOut,
        'total' => 3500.00,
        'outstanding' => 0.00,
    ]);

    Invoice::create([
        'property_id' => $property->id,
        'booking_id' => $booking->id,
        'invoice_number' => 'INVTST000001',
        'folio_number' => 'TST009988',
        'invoice_generation_date' => now(),
        'status' => InvoiceStatus::BillGenerated,
        'invoice_amount' => 3500.00,
        'taxable_amount' => 3000.00,
        'total_tax' => 500.00,
        'amount_paid' => 3500.00,
        'invoice_outstanding_amount' => 0.00,
    ]);

    $response = $this->actingAs($this->admin)->get("/admin/databases/invoices?property={$property->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/databases/Invoices')
        ->has('invoices.data', 1)
        ->where('invoices.data.0.invoice_number', 'INVTST000001')
        ->where('invoices.data.0.status', 'Bill Generated')
    );
});

test('invoices export download validates 30-day date range', function () {
    $property = Property::factory()->create(['abbreviation' => 'TST']);

    // Without date range -> rejected with 422
    $rejectResponse = $this->actingAs($this->admin)->get("/admin/databases/invoices/download?property={$property->id}");
    $rejectResponse->assertStatus(422);

    // With date range <= 30 days -> succeeds with CSV download
    $successResponse = $this->actingAs($this->admin)->get("/admin/databases/invoices/download?property={$property->id}&invoice_generation_date_from=".now()->subDays(5)->toDateString().'&invoice_generation_date_to='.now()->toDateString());
    $successResponse->assertOk();
    expect($successResponse->headers->get('content-type'))->toContain('text/csv');
});
