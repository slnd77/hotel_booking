<?php

use App\Models\Booking;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\WebCheckin;
use App\Services\WebCheckins;
use Carbon\CarbonImmutable;
use Database\Seeders\FormFieldSeeder;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/AuditBrowser.php';

beforeEach(function () {
    auditBootstrap();
    $this->admin = auditAdmin();
    [$this->property, $this->category, $this->room] = auditProperty();
    $this->today = CarbonImmutable::parse($this->property->today());
    $this->checkIn = $this->today->addDay()->toDateString();
    $this->checkOut = $this->today->addDays(2)->toDateString();
    config(['services.test_gateway.enabled' => true]);
    // The meal plan labels ("Room Only" for EP) are reference data.
    $this->seed(FormFieldSeeder::class);
});

/**
 * Picks the Deluxe room (Room Only) for the stay and goes on to the guest details step, once
 * the server has quoted the price (paying needs the quote).
 */
function auditEngineWithRoomInCart(object $test): object
{
    return visit(auditEngineUrl($test->property, $test->checkIn, $test->checkOut))
        ->assertSee('Deluxe')
        ->click("@add-{$test->category->id}-EP")
        ->assertSee('Room added to cart')
        ->click('PROCEED')
        ->assertSee('Guest Details')
        ->wait(3);
}

/**
 * Signs the web check-in signature pad: a pointer stroke across the canvas.
 */
function auditSignCanvas(object $page): object
{
    $page->script(<<<'JS'
        (() => {
            const canvas = document.querySelector('canvas');
            canvas.setPointerCapture = () => {};
            const box = canvas.getBoundingClientRect();
            const fire = (type, x, y) => canvas.dispatchEvent(new PointerEvent(type, {
                clientX: box.left + x, clientY: box.top + y, pointerId: 1, pointerType: 'mouse', isPrimary: true, buttons: 1, bubbles: true, cancelable: true,
            }));
            fire('pointerdown', 20, 30);
            fire('pointermove', 90, 70);
            fire('pointermove', 160, 40);
            fire('pointerup', 160, 40);
        })()
        JS);

    return $page;
}

// TC-CUST-01
test('the search prices each category per night with taxes, and a Room Only choice adds up in the summary', function () {
    visit(auditEngineUrl($this->property, $this->checkIn, $this->checkOut))
        ->assertSee('Deluxe')
        ->assertSee('Room Only')
        ->assertSee('Per night (incl. taxes)')
        ->assertSee('$2,100.00')
        ->click("@add-{$this->category->id}-EP")
        ->assertSee('Room added to cart')
        ->assertSee('ROOM 1 : Deluxe')
        ->assertSee('1 in cart')
        ->assertSee('Total Taxes')
        ->click('VIEW PRICE DETAILS')
        ->assertSee('Total Room Charges')
        ->assertSee('$2,000.00')
        ->assertSee('$100.00')
        ->assertSee('Total Payable')
        ->assertSee('$2,100.00')
        ->assertNoJavaScriptErrors();
});

// TC-CUST-02
test('a guest books and pays in full on the test gateway, and the confirmation shows their details', function () {
    app(WebCheckins::class)->saveSettings($this->property, ['enabled' => true]);

    auditEngineWithRoomInCart($this)
        ->type('input[placeholder="Enter primary guest full name"]', 'Asha Rao')
        ->type('input[placeholder="e.g. +91 9876543210"]', '+91 9000000001')
        ->type('input[placeholder="booking.confirmation@example.com"]', 'asha@example.com')
        ->assertSee('Full Payment')
        ->check('input[name="selected_gateway"][value="test"]')
        ->check('agree-terms')
        ->click('button:has-text("WITH TEST GATEWAY")')
        ->assertSee('Zero-Credential Simulator')
        ->assertSee('Amount to Charge Now:')
        ->click('button:has-text("Simulate Successful Payment")')
        ->assertSee('Reservation Confirmed!')
        ->assertSee('Booking Reference:')
        ->assertSee('Asha Rao')
        ->assertSee('+91 9000000001')
        ->assertSee('asha@example.com')
        ->assertSee('$2,100.00')
        ->assertSee('Check in online')
        ->assertDontSee('in cart')
        ->assertNoJavaScriptErrors();

    $booking = Booking::query()->where('property_id', $this->property->id)->sole();

    expect($booking->source)->toBe('booking_engine')
        ->and((float) $booking->total)->toBe(2100.0)
        ->and((float) $booking->outstanding)->toBe(0.0)
        ->and($booking->guests()->sole())->first_name->toBe('Asha')->phone->toBe('+91 9000000001');
});

// TC-CUST-03
test('the guest form flags a blank mobile number and a bad e-mail inline, and pays only once the terms are accepted', function () {
    auditEngineWithRoomInCart($this)
        ->type('input[placeholder="Enter primary guest full name"]', 'Asha Rao')
        ->type('input[placeholder="booking.confirmation@example.com"]', 'not-an-email')
        ->check('input[name="selected_gateway"][value="test"]')
        ->assertDisabled('button:has-text("WITH TEST GATEWAY")')
        ->check('agree-terms')
        ->assertEnabled('button:has-text("WITH TEST GATEWAY")')
        ->click('button:has-text("WITH TEST GATEWAY")')
        ->assertSee('Mobile number is required')
        ->assertSee('Please enter a valid e-mail address')
        ->assertSee('Missing guest information')
        ->assertVisible('[role="alert"][data-variant="error"]')
        ->assertDontSee('Zero-Credential Simulator')
        ->assertNoJavaScriptErrors();

    expect(Booking::query()->where('property_id', $this->property->id)->exists())->toBeFalse();
});

// TC-CUST-04
test('days before today cannot be picked in the date picker', function () {
    visit(auditEngineUrl($this->property, $this->checkIn, $this->checkOut))
        ->click('label:has-text("Check-In") button')
        ->assertSee('Apply')
        // Every day before today is disabled; today is the first day that can be picked.
        ->assertScript(<<<'JS'
            (() => {
                const grid = document.querySelector('.grid-cols-7');
                const days = [...grid.querySelectorAll('button')];
                const first = days.findIndex((day) => !day.disabled);
                const today = String(new Date().getDate());

                return first >= 0
                    && days.slice(0, first).every((day) => day.disabled)
                    && days[first].querySelector('span').textContent.trim() === today;
            })()
            JS, true)
        ->assertNoJavaScriptErrors();
});

// TC-CUST-05. The form posts multipart (the ID upload), which pest-plugin-browser's in-process
// server does not parse yet (LaravelHttpServer: "@TODO files"), so this one cannot pass here.
// The ID upload itself is covered by tests/Feature/AddonsAndWebCheckinTest.php: pest-plugin-browser's
// in-process server does not parse multipart bodies, so the ID file is already on the check-in here.
test('the guest checks in online with an ID number and a signature, which the booking page reports as completed', function () {
    Storage::fake('local');
    app(WebCheckins::class)->saveSettings($this->property, ['enabled' => true, 'opens_days_before' => 7, 'require_id_document' => true, 'require_signature' => true, 'terms' => 'No smoking in the rooms.']);
    $guest = auditGuest($this->property);
    $booking = auditStoreBooking($this, $this->admin, $this->property, $this->category, $this->room, $this->checkIn, $this->checkOut, $guest);
    $checkin = app(WebCheckins::class)->for($booking);
    Storage::disk('local')->put('web-checkins/audit-id.png', file_get_contents(auditPng()));
    $checkin->update(['id_document_path' => 'web-checkins/audit-id.png']);
    auth()->logout();

    $page = visit(route('web-checkin.show', $checkin->token))
        ->assertSee('Online check-in')
        ->assertSee($booking->booking_ref_id)
        ->assertValue('wc-first-name', 'Asha')
        ->type('wc-phone', '9000000001')
        ->select('wc-id-type', 'Passport')
        ->type('wc-id-number', 'P1234567');

    auditSignCanvas($page)
        ->check('wc-terms')
        ->check('wc-privacy')
        ->click('Complete check-in')
        ->assertSee('Your online check-in is complete')
        ->assertSee("You're checked in online")
        ->assertNoJavaScriptErrors();

    expect($checkin->fresh()->status)->toBe(WebCheckin::COMPLETED)
        ->and($checkin->fresh()->details['id_number'])->toBe('P1234567');

    $this->actingAs($this->admin);

    visit(route('admin.bookings.show', [$this->property, $booking]))
        ->assertSee('Completed')
        ->assertNoJavaScriptErrors();
});

// TC-CUST-06
test('the terms and privacy pages render', function () {
    visit(route('legal.terms', $this->property->ulid))
        ->assertSee('Terms of Booking')
        ->assertSee($this->property->name)
        ->assertNoJavaScriptErrors();

    visit(route('legal.privacy', $this->property->ulid))
        ->assertSee('Privacy Policy')
        ->assertSee($this->property->name)
        ->assertNoJavaScriptErrors();
});

test('a category with no rate is only offered when zero-rate bookings are allowed', function () {
    $dormitory = RoomCategory::factory()->create(['property_id' => $this->property->id, 'name' => 'Dormitory', 'meal_plans' => ['EP'], 'display_order' => 2]);
    Room::factory()->create(['room_category_id' => $dormitory->id, 'name' => 'D1']);

    visit(auditEngineUrl($this->property, $this->checkIn, $this->checkOut))
        ->assertSee('Deluxe')
        ->assertDontSee('Dormitory')
        ->assertNoJavaScriptErrors();

    auditSetting($this->property, 'booking_settings_reservation', ['allow_zero_rate_bookings' => true]);

    visit(auditEngineUrl($this->property, $this->checkIn, $this->checkOut))
        ->assertSee('Dormitory')
        ->assertNoJavaScriptErrors();
});

test('the partial and full payment choices each describe their own amounts whichever is selected', function () {
    auditSetting($this->property, 'booking_engine_settings', ['enable_booking_engine' => true, 'allow_partial_payment' => true, 'partial_payment_percentage' => 50]);

    $page = auditEngineWithRoomInCart($this)
        ->assertSee('Partial Payment (50%)')
        ->assertSee('Full Payment (100%)');

    foreach (['false', 'true'] as $partial) {
        $page->click("input[name=\"payment_choice\"][value=\"{$partial}\"]")
            ->assertSee('Pay $1,050.00 now to confirm your booking. Remaining $1,050.00 will be paid upon check-in at the hotel.')
            ->assertSee('Pay the full amount of $2,100.00 online now.');
    }

    $page->assertNoJavaScriptErrors();
});
