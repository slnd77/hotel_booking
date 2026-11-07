<?php

use App\Enums\BookingStatus;
use App\Enums\SubBookingStatus;
use App\Models\ActivityLog;
use App\Models\Addon;
use App\Models\Booking;
use App\Models\Room;
use App\Services\ApplicationSettings;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/AuditBrowser.php';

beforeEach(function () {
    auditBootstrap();
    $this->admin = auditAdmin();
    $this->frontOffice = auditRoleUser('Front Office');
    [$this->property, $this->category, $this->room] = auditProperty();
    $this->today = CarbonImmutable::parse($this->property->today());
    $this->tomorrow = $this->today->addDay()->toDateString();
    // The tests run at 06:00 UTC; a reported stay time must not be in the future.
    $this->stayTime = $this->today->toDateString().'T05:30';
});

/**
 * The Quick Book form for room 101 tonight.
 */
function auditQuickBookUrl(object $test): string
{
    return route('admin.bookings.create', [$test->property, 'check_in' => $test->today->toDateString(), 'check_out' => $test->tomorrow, 'room_ids' => $test->room->id]);
}

/**
 * Opens the Add Guest modal from the guest search box.
 */
function auditOpenAddGuest(object $page): object
{
    $page->click('input[placeholder="Search by name, Contact No., Email, Guest ID"]')
        ->assertSee('+ Add New Guest')
        ->script("[...document.querySelectorAll('button')].find((button) => button.textContent.trim() === '+ Add New Guest').click()");

    return $page->assertSee('Basic Details');
}

// TC-FD-01
test('a web booking shows on the front desk arrivals with the guest, room, dates and no balance', function () {
    $response = bookThroughEngine($this, $this->property, [
        'check_in' => $this->tomorrow, 'check_out' => $this->today->addDays(2)->toDateString(),
        'rooms' => [['category_id' => $this->category->id, 'meal_plan' => 'EP', 'adults' => 2, 'children' => 0]],
    ])->assertOk();
    $reference = $response->json('booking.booking_ref_id');
    $this->actingAs($this->frontOffice);

    visit(route('admin.frontDesk.show', [$this->property, 'date' => $this->tomorrow]))
        ->assertSee('Front Desk')
        ->assertSee('Arrivals (0/1)')
        ->assertSee('Asha Rao')
        ->assertSee('101')
        ->assertSee('Deluxe')
        ->assertSee('Expected')
        ->assertSee($reference)
        ->assertSee('0.00')
        ->assertNoJavaScriptErrors();

    expect((float) Booking::query()->where('booking_ref_id', $reference)->sole()->outstanding)->toBe(0.0);
});

// TC-FD-02
test('a walk-in is quick-booked with a new guest added from the form, priced at the rate plus 5% tax', function () {
    $this->actingAs($this->frontOffice);

    $page = visit(auditQuickBookUrl($this))
        ->assertSee('Deluxe')
        ->assertValue('@room-rate-0', '2000');

    auditOpenAddGuest($page)
        ->select('.fixed.inset-0 select >> nth=0', 'Mr.')
        ->type('.fixed.inset-0 input[type="text"][required] >> nth=0', 'Vikram')
        ->type('.fixed.inset-0 input[type="text"]:not([required]) >> nth=0', 'Mehta')
        ->type('.fixed.inset-0 input[type="text"][required] >> nth=1', '9000000002')
        ->assertVisible('.fixed.inset-0 input[type="email"]')
        ->type('.fixed.inset-0 input[type="email"]', 'vikram@example.com')
        ->click('.fixed.inset-0 button:text-is("Add Guest")')
        ->assertSee('Mr. Vikram Mehta')
        ->click('Save & Add Payment')
        ->assertSee('created.')
        ->assertNoJavaScriptErrors();

    $booking = Booking::query()->where('property_id', $this->property->id)->sole();

    expect((float) $booking->room_total)->toBe(2000.0)
        ->and((float) $booking->taxes)->toBe(100.0)
        ->and((float) $booking->total)->toBe(2100.0)
        ->and($booking->guests()->sole())->first_name->toBe('Vikram')->emails->toBe(['vikram@example.com'])
        ->and($booking->rooms()->sole()->room_id)->toBe($this->room->id);
});

// TC-FD-02b
test('a quick booking without a guest is refused with a validation error', function () {
    $this->actingAs($this->frontOffice);

    visit(auditQuickBookUrl($this))
        ->assertSee('No guests added yet.')
        ->click('Save & Add Payment')
        ->assertVisible('@guest-ids-error')
        ->assertNoJavaScriptErrors();

    expect(Booking::query()->where('property_id', $this->property->id)->exists())->toBeFalse();
});

// TC-FD-03
test('a room whose guest overstays is not offered on the quick book form and cannot be checked into', function () {
    Room::factory()->create(['room_category_id' => $this->category->id, 'name' => '102', 'display_order' => 2, 'housekeeping_status' => 'Clean']);
    $overstay = auditStay($this->property, $this->room, $this->today->subDays(2)->toDateString(), $this->today->subDay()->toDateString(), SubBookingStatus::CheckedIn, auditGuest($this->property));
    $this->actingAs($this->frontOffice);

    visit(route('admin.bookings.create', [$this->property, 'check_in' => $this->today->toDateString(), 'check_out' => $this->tomorrow]))
        ->assertSee('Quick Book')
        // The form asks the server which rooms are free for the dates: room 102 only.
        ->wait(2)
        ->assertScript("[...document.querySelectorAll('option')].some((option) => option.textContent.trim() === 'Deluxe (1 available)')", true)
        ->select('label:has-text("Category") select >> nth=0', (string) $this->category->id)
        ->select('label:has-text("Rooms") select >> nth=0', '1')
        ->assertSee('Room No.')
        // Room 101 is still occupied: its option is disabled (or not offered at all).
        ->assertScript(<<<JS
            (() => {
                const select = [...document.querySelectorAll('select')].find((element) => [...element.options].some((option) => option.textContent.trim() === 'Auto'));
                const option = select ? [...select.options].find((option) => option.value === '{$this->room->id}') : null;

                return option === null || option === undefined || option.disabled;
            })()
            JS, true)
        ->assertNoJavaScriptErrors();

    // A booking arriving today that was given room 101 anyway cannot be checked into it.
    $arriving = auditStay($this->property, $this->room, $this->today->toDateString(), $this->tomorrow, SubBookingStatus::Booked, auditGuest($this->property, ['first_name' => 'Meera', 'phone' => '9000000003']));

    visit(route('admin.bookings.show', [$this->property, $arriving]))
        ->click('@booking-check-in')
        ->type('@check-in-time', $this->stayTime)
        ->click('@check-in-submit')
        ->assertSee('101 is occupied by booking')
        ->assertDontSee('Booking status updated to Checked In.')
        ->assertNoJavaScriptErrors();

    expect($arriving->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($overstay->fresh()->status)->toBe(BookingStatus::CheckedIn);
});

// TC-FD-04
test('check-in marks the booking checked in with its reported time and shows on the chart, and is refused while a required e-mail is missing', function () {
    $booking = auditStoreBooking($this, $this->admin, $this->property, $this->category, $this->room, $this->today->toDateString(), $this->tomorrow, auditGuest($this->property));
    $this->actingAs($this->frontOffice);

    visit(route('admin.bookings.show', [$this->property, $booking]))
        ->click('@booking-check-in')
        ->type('@check-in-time', $this->stayTime)
        ->click('@check-in-submit')
        ->assertSee('Booking status updated to Checked In.')
        ->assertSee('CHECKED IN')
        ->assertSee('Reported Check-in:')
        ->assertNoJavaScriptErrors();

    expect($booking->fresh()->status)->toBe(BookingStatus::CheckedIn)
        ->and($booking->rooms()->sole()->status)->toBe(SubBookingStatus::CheckedIn);

    visit(route('admin.dashboard', ['property' => $this->property->id]))
        ->assertSee('Smart Booking Chart')
        ->click('[title="Expand all rooms"]')
        ->assertSee('Asha Rao')
        ->assertNoJavaScriptErrors();

    // Application Settings → Reservation: an e-mail is required at check-in.
    $settings = app(ApplicationSettings::class);
    $reservation = $settings->get(ApplicationSettings::RESERVATION);
    $reservation['check_in']['guest_email'] = true;
    $settings->save(ApplicationSettings::RESERVATION, $reservation, null, 'Test setup.');

    $room102 = Room::factory()->create(['room_category_id' => $this->category->id, 'name' => '102', 'display_order' => 2, 'housekeeping_status' => 'Clean']);
    $noEmail = auditStoreBooking($this, $this->admin, $this->property, $this->category, $room102, $this->today->toDateString(), $this->tomorrow, auditGuest($this->property, ['first_name' => 'Meera', 'phone' => '9000000003', 'emails' => []]));
    $this->actingAs($this->frontOffice);

    visit(route('admin.bookings.show', [$this->property, $noEmail]))
        ->click('@booking-check-in')
        ->type('@check-in-time', $this->stayTime)
        ->click('@check-in-submit')
        ->assertSee('Meera Rao is missing: E-mail')
        ->assertNoJavaScriptErrors();

    expect($noEmail->fresh()->status)->toBe(BookingStatus::Confirmed);
});

// TC-FD-05
test('checking into a dirty room warns and needs "check in anyway" ticked first', function () {
    $this->room->update(['housekeeping_status' => 'Dirty']);
    $booking = auditStoreBooking($this, $this->admin, $this->property, $this->category, $this->room, $this->today->toDateString(), $this->tomorrow, auditGuest($this->property));
    $this->actingAs($this->frontOffice);

    visit(route('admin.bookings.show', [$this->property, $booking]))
        ->click('@booking-check-in')
        ->assertSee('101 is Dirty')
        ->assertSee('Check in anyway')
        ->assertDisabled('@check-in-submit')
        ->check('label:has-text("Check in anyway") input[type="checkbox"]')
        ->assertEnabled('@check-in-submit')
        ->type('@check-in-time', $this->stayTime)
        ->click('@check-in-submit')
        ->assertSee('CHECKED IN')
        ->assertNoJavaScriptErrors();

    expect($booking->fresh()->status)->toBe(BookingStatus::CheckedIn);
});

// TC-FD-07
test('a Front Office user is refused the night audit page', function () {
    $this->actingAs($this->frontOffice);

    visit(route('admin.nightAudits.edit', $this->property))
        ->assertSee('403')
        ->assertDontSee('Night Audit');
});

test('the booking page names who created the booking and lists only its own activity', function () {
    $booking = auditStoreBooking($this, $this->admin, $this->property, $this->category, $this->room, $this->today->toDateString(), $this->tomorrow, auditGuest($this->property));
    $other = auditStoreBooking($this, $this->admin, $this->property, $this->category, null, $this->tomorrow, $this->today->addDays(2)->toDateString(), auditGuest($this->property, ['first_name' => 'Meera', 'phone' => '9000000003']));
    ActivityLog::create(['user_id' => $this->admin->id, 'property_id' => $this->property->id, 'booking_id' => $other->id, 'action' => 'Note on the other booking only', 'date' => now()]);
    ActivityLog::create(['user_id' => $this->admin->id, 'property_id' => $this->property->id, 'booking_id' => $booking->id, 'action' => 'Note on this booking', 'date' => now()]);
    $this->actingAs($this->frontOffice);

    visit(route('admin.bookings.show', [$this->property, $booking]))
        ->assertSee("by {$this->admin->name}")
        ->assertDontSee("by {$this->frontOffice->name}")
        ->assertSee('Activity Log')
        ->assertSee('Note on this booking')
        ->assertDontSee('Note on the other booking only')
        ->assertNoJavaScriptErrors();
});

test('the booking header total matches the bill summary total, add-ons included', function () {
    $pickup = Addon::factory()->create(['property_id' => $this->property->id, 'name' => 'Airport pickup', 'pricing' => 'per_booking', 'price' => 800, 'tax_rate' => 0]);
    $booking = auditStoreBooking($this, $this->admin, $this->property, $this->category, $this->room, $this->today->toDateString(), $this->tomorrow, auditGuest($this->property), [
        'addons' => [['addon_id' => $pickup->id, 'quantity' => 1]],
    ]);
    $this->actingAs($this->frontOffice);

    expect((float) $booking->fresh()->addons_total)->toBe(800.0);

    visit(route('admin.bookings.show', [$this->property, $booking]))
        ->assertSee('Airport pickup')
        ->assertSee('Addons (incl. tax)')
        ->assertSee('Net Payable by Guest')
        ->assertSee('2,900.00')
        // The header's TOTAL BILL is the bill summary's total: rooms, taxes and add-ons.
        ->assertScript(<<<'JS'
            (() => {
                const label = [...document.querySelectorAll('div')].find((element) => element.textContent.trim() === 'TOTAL BILL');

                return (label?.nextElementSibling?.textContent ?? '').includes('2,900.00');
            })()
            JS, true)
        ->assertNoJavaScriptErrors();
});

test('a flash message is shown once', function () {
    $booking = auditStoreBooking($this, $this->admin, $this->property, $this->category, $this->room, $this->today->toDateString(), $this->tomorrow, auditGuest($this->property));
    $this->actingAs($this->frontOffice);

    visit(route('admin.bookings.show', [$this->property, $booking]))
        ->click('@booking-check-in')
        ->type('@check-in-time', $this->stayTime)
        ->click('@check-in-submit')
        ->assertSee('Booking status updated to Checked In.')
        ->assertScript("document.body.innerText.split('Booking status updated to Checked In.').length - 1", 1)
        ->assertNoJavaScriptErrors();
});
