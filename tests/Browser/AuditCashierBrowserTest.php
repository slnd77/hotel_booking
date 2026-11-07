<?php

use App\Enums\BookingStatus;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Enums\SubBookingStatus;
use App\Models\Addon;
use App\Models\Booking;
use App\Models\CashierShift;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\BookingAddons;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/AuditBrowser.php';

beforeEach(function () {
    auditBootstrap();
    $this->admin = auditAdmin();
    $this->cashier = auditRoleUser('Front Office', [
        'front_office.cashier_shifts_view_all', 'database.payments.add', 'database.payments.view_download', 'database.invoices.view_download',
    ], 'Cashier Chitra');
    [$this->property, $this->category, $this->room] = auditProperty();
    $this->today = CarbonImmutable::parse($this->property->today());
    $this->tomorrow = $this->today->addDay()->toDateString();
    // The tests run at 06:00 UTC; a reported check-out time must not be in the future.
    $this->stayTime = $this->today->toDateString().'T05:30';
});

/**
 * CHECK OUT → the check-out dialog, submitted with a reported time of 05:30 today.
 */
function auditCheckOut(object $page, string $stayTime): object
{
    return $page->click('CHECK OUT')
        ->assertSee('Check-out date & time')
        ->type('input[type="datetime-local"]', $stayTime)
        // The invoice PDF needs a headless Chrome this machine lacks; e-mailing it is not under test.
        ->uncheck('label:has-text("Send invoice to guest") input[type="checkbox"]')
        ->click('button[type="submit"]:text-is("Check Out")');
}

/**
 * A guest in room 101 tonight, checked in, 2 100 due (2 000 + 5% tax).
 */
function auditCheckedInBooking(object $test): Booking
{
    return auditStay($test->property, $test->room, $test->today->toDateString(), $test->tomorrow, SubBookingStatus::CheckedIn, auditGuest($test->property));
}

/**
 * Records a payment on the booking page: Payments → ADD.
 */
function auditAddPayment(object $page, string $mode, string $amount): object
{
    return $page->click('button[type="button"]:text-is("ADD")')
        ->assertVisible('@payment-mode')
        ->select('@payment-mode', $mode)
        ->type('input[placeholder="0.00"][required]', $amount)
        ->click('button:text-is("Add Payment")')
        ->assertSee('added successfully.');
}

/**
 * A cash receipt taken at the property, as the payment store records it.
 */
function auditCashReceipt(object $test, Booking $booking, float $amount, PaymentMode $mode = PaymentMode::Cash): Payment
{
    return Payment::create([
        'property_id' => $test->property->id, 'booking_id' => $booking->id, 'type' => 'receipt', 'guest_name' => 'Asha Rao',
        'payment_mode' => $mode, 'total_txn_amount' => $amount, 'tagged_amount' => $amount, 'untagged_amount' => 0,
        'collected_by_staff' => $test->cashier->name, 'voucher_number' => $booking->booking_ref_id,
        'payment_creation_date' => now(), 'payment_transaction_date' => now(), 'status' => PaymentStatus::Received,
    ]);
}

// TC-CSH-01
test('the cashier opens a shift with a 5 000 float', function () {
    $this->actingAs($this->cashier);

    visit(route('admin.cashierShifts.show', $this->property))
        ->assertSee('Open a shift')
        ->type('label:has-text("Opening cash float") input', '5000')
        ->click('Open shift')
        ->assertSee('Shift opened.')
        ->assertSee('Your shift')
        ->assertSee('Cash expected in the drawer')
        ->assertSee('5,000.00')
        ->assertNoJavaScriptErrors();

    expect((float) CashierShift::query()->where('user_id', $this->cashier->id)->sole()->opening_float)->toBe(5000.0);
});

// TC-CSH-02
test('an add-on on a checked-in booking raises the net payable and the header total', function () {
    $booking = auditCheckedInBooking($this);
    $pickup = Addon::factory()->create(['property_id' => $this->property->id, 'name' => 'Airport pickup', 'pricing' => 'per_booking', 'price' => 800, 'tax_rate' => 0]);
    $this->actingAs($this->cashier);

    visit(route('admin.bookings.show', [$this->property, $booking]))
        ->assertSee('CHECKED IN')
        ->select('label:has-text("Add a service") select', (string) $pickup->id)
        ->click('button[type="submit"]:text-is("ADD")')
        ->assertSee('Airport pickup')
        ->assertSee('800.00')
        ->assertSee('Addons (incl. tax)')
        ->assertSee('Net Payable by Guest')
        ->assertSee('2,900.00')
        ->assertScript(<<<'JS'
            (() => {
                const label = [...document.querySelectorAll('div')].find((element) => element.textContent.trim() === 'TOTAL BILL');

                return (label?.nextElementSibling?.textContent ?? '').includes('2,900.00');
            })()
            JS, true)
        ->assertNoJavaScriptErrors();

    expect((float) $booking->fresh()->addons_total)->toBe(800.0)
        ->and((float) $booking->fresh()->outstanding)->toBe(2900.0);
});

// TC-CSH-03
test('cash and then UPI payments settle the booking to nothing payable', function () {
    $booking = auditCheckedInBooking($this);
    $this->actingAs($this->cashier);

    $page = visit(route('admin.bookings.show', [$this->property, $booking]))->assertSee('CHECKED IN');
    auditAddPayment($page, 'Cash', '1000')->assertSee('1,000.00');
    auditAddPayment($page, 'UPI', '1100')
        ->assertSee('UPI')
        ->assertSee('1,100.00')
        ->assertNoJavaScriptErrors();

    expect((float) $booking->fresh()->outstanding)->toBe(0.0)
        ->and(Payment::query()->where('booking_id', $booking->id)->where('type', 'receipt')->where('total_txn_amount', '>', 0)->pluck('payment_mode')->map(fn ($mode) => $mode instanceof PaymentMode ? $mode->value : (string) $mode)->sort()->values()->all())->toBe(['Cash', 'UPI']);

    $page->assertScript(<<<'JS'
        (() => {
            const row = [...document.querySelectorAll('span')].find((element) => element.textContent.trim() === 'Net Payable by Guest');

            return (row?.nextElementSibling?.textContent ?? '').trim();
        })()
        JS, '0.00');
});

// TC-CSH-04
test('check-out of a settled booking invoices the full bill with its add-on and reads "Checked out"', function () {
    $booking = auditCheckedInBooking($this);
    $pickup = Addon::factory()->create(['property_id' => $this->property->id, 'name' => 'Airport pickup', 'pricing' => 'per_booking', 'price' => 800, 'tax_rate' => 0]);
    app(BookingAddons::class)->sync($booking, [['addon_id' => $pickup->id, 'quantity' => 1]], 'front_desk', $this->admin);
    $this->actingAs($this->cashier);

    $page = visit(route('admin.bookings.show', [$this->property, $booking]))->assertSee('CHECKED IN');
    auditCheckOut(auditAddPayment($page, 'Cash', '2900'), $this->stayTime)
        ->assertSee('Booking status updated to Checked Out.')
        ->assertSee('CHECKED OUT')
        ->assertDontSee('Checked In Checked Out')
        ->assertDontSee('Booking status updated to Checked In.')
        ->assertNoJavaScriptErrors();

    $invoice = Invoice::query()->where('booking_id', $booking->id)->sole();

    expect($booking->fresh()->status)->toBe(BookingStatus::CheckedOut)
        ->and((float) $invoice->invoice_amount)->toBe(2900.0);

    visit(route('admin.databases.invoices.index', ['property' => $this->property->id]))
        ->assertSee('Invoice Database')
        ->assertSee($invoice->invoice_number)
        ->assertSee('2,900.00')
        ->assertNoJavaScriptErrors();
});

// TC-CSH-05
test('closing the shift with less cash than expected shows the shortfall and lists the shift', function () {
    $booking = auditCheckedInBooking($this);
    $this->actingAs($this->cashier)
        ->post(route('admin.cashierShifts.open', $this->property), ['opening_float' => 5000])
        ->assertSessionHas('success', 'Shift opened.');
    auditCashReceipt($this, $booking, 1000);

    visit(route('admin.cashierShifts.show', $this->property))
        ->assertSee('Cash expected in the drawer')
        ->assertSee('6,000.00')
        ->type('label:has-text("Cash counted in the drawer") input', '5950')
        ->assertSee('Short by')
        ->assertSee('50.00')
        ->click('Close shift')
        ->assertSee('Shift closed. The drawer is short by 50.00.')
        ->assertSee('Shifts, last 30 days')
        ->assertSee($this->cashier->name)
        ->assertSee('-50.00')
        ->assertNoJavaScriptErrors();

    $shift = CashierShift::query()->where('user_id', $this->cashier->id)->sole();

    expect((float) $shift->expected_cash)->toBe(6000.0)
        ->and((float) $shift->cash_counted)->toBe(5950.0)
        ->and((float) $shift->variance)->toBe(-50.0);
});

// TC-CSH-06
test('check-out with a balance due is refused without the outstanding-checkout permission and allowed for the admin', function () {
    $booking = auditCheckedInBooking($this);
    $this->actingAs($this->cashier);

    auditCheckOut(visit(route('admin.bookings.show', [$this->property, $booking]))->assertSee('CHECKED IN'), $this->stayTime)
        ->assertSee('Checkout with an outstanding balance requires authorization.')
        ->assertDontSee('Booking status updated to Checked Out.')
        ->assertNoJavaScriptErrors();

    expect($booking->fresh()->status)->toBe(BookingStatus::CheckedIn);

    $this->actingAs($this->admin);

    auditCheckOut(visit(route('admin.bookings.show', [$this->property, $booking]))->assertSee('CHECKED IN'), $this->stayTime)
        ->assertSee('Booking status updated to Checked Out.')
        ->assertSee('CHECKED OUT')
        ->assertNoJavaScriptErrors();

    expect($booking->fresh()->status)->toBe(BookingStatus::CheckedOut);
});

// TC-CSH-07
test('the payments database lists the receipts with their mode and the staff who took them', function () {
    $booking = auditCheckedInBooking($this);
    auditCashReceipt($this, $booking, 1000);
    auditCashReceipt($this, $booking, 1100, PaymentMode::UPI);
    $this->actingAs($this->cashier);

    visit(route('admin.databases.payments.index', ['property' => $this->property->id]))
        ->assertSee('Payments Database')
        ->assertSee($booking->booking_ref_id)
        ->assertSee('Cash')
        ->assertSee('UPI')
        ->assertSee('1,000.00')
        ->assertSee('1,100.00')
        ->assertSee($this->cashier->name)
        ->assertNoJavaScriptErrors();
});

// TC-X-01
test('a wrong password is refused on the log-in page', function () {
    auth()->logout();

    visit(route('login'))
        ->type('#email', $this->cashier->email)
        ->type('#password', 'not-the-password')
        ->press('Log in')
        ->assertSee('These credentials do not match our records.')
        ->assertPathIs('/login')
        ->assertNoJavaScriptErrors();
});
