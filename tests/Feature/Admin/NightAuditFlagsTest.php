<?php

use App\Enums\BookingStatus;
use App\Enums\SubBookingStatus;
use App\Models\Booking;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Language;
use App\Models\NightAudit;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\NightAudits;
use App\Services\NightAuditSettings;
use App\Services\ReportFiles;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    // 11:30 on 02/10 in India.
    $this->travelTo(CarbonImmutable::parse('2026-10-02 06:00:00', 'UTC'));

    $this->property = Property::factory()->create(['timezone' => 'Asia/Kolkata']);
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id]);
    $this->room = Room::factory()->create(['room_category_id' => $this->category->id, 'name' => '101']);
    $this->settings = app(NightAuditSettings::class);
});

/**
 * A two-night stay that checked out at the given UTC instant.
 */
function checkedOutBooking(Property $property, RoomCategory $category, Room $room, string $checkOut, string $checkedOutAt): Booking
{
    $checkIn = CarbonImmutable::parse($checkOut)->subDays(2)->toDateString();
    $booking = $property->bookings()->create([
        'booking_ref_id' => 'CO-'.fake()->unique()->numerify('####'), 'source' => 'direct', 'type' => 'walk_in', 'status' => BookingStatus::CheckedOut,
        'check_in' => $checkIn, 'check_out' => $checkOut,
        'room_total' => 2000, 'discount' => 0, 'taxes' => 0, 'total' => 2000, 'outstanding' => 0,
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
    ]);
    $booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $room->id, 'meal_plan' => 'EP', 'adults' => 1, 'rate_per_night' => 1000])
        ->update(['status' => SubBookingStatus::CheckedOut, 'checked_out_at' => $checkedOutAt]);

    return $booking;
}

test('"Restrict Reports" limits dated reports to business dates the night audit has closed', function () {
    $reportUrl = fn (string $report, string $date, ?string $end = null): string => route('admin.reports.download', ['property' => $this->property, 'report' => $report, 'date' => $date, 'end_date' => $end, 'type' => 'excel']);

    // Off: any date.
    $this->actingAs($this->admin)->get($reportUrl('daily_reports', '2026-10-02'))->assertOk();

    $this->settings->save($this->property, ['restrict_reports' => true]);

    $this->actingAs($this->admin)->get($reportUrl('daily_reports', '2026-10-01'))->assertStatus(422);

    NightAudit::query()->create(['property_id' => $this->property->id, 'business_date' => '2026-10-01', 'status' => NightAudit::COMPLETED]);

    $this->actingAs($this->admin)->get($reportUrl('daily_reports', '2026-10-01'))->assertOk();
    $this->actingAs($this->admin)->get($reportUrl('daily_reports', '2026-10-02'))->assertStatus(422);
    $this->actingAs($this->admin)->get($reportUrl('gstr1_sales_register_report', '2026-09-01', '2026-10-02'))->assertStatus(422);
    $this->actingAs($this->admin)->get($reportUrl('gstr1_sales_register_report', '2026-09-01', '2026-10-01'))->assertOk();

    // Operational reports look at open days and ahead.
    $this->actingAs($this->admin)->get($reportUrl('inventory_availability_report', '2026-10-02', '2026-10-09'))->assertOk();

    expect(fn () => app(ReportFiles::class)->ensureReportable($this->property, 'daily_reports', CarbonImmutable::parse('2026-10-02')))
        ->toThrow(ValidationException::class, 'up to 01/10/2026');

    $this->actingAs($this->admin)->get(route('admin.reports.edit', $this->property))->assertInertia(fn ($page) => $page
        ->where('closedUntil', '2026-10-01')
        ->where('restrictable', fn ($reports) => collect($reports)->contains('daily_reports') && ! collect($reports)->contains('inventory_availability_report')));
});

test('"Validate Invoice" keeps the day open while a check-out has no invoice, until it is generated', function () {
    $this->settings->save($this->property, ['validate_invoice' => true]);
    // 10:00 India time on 02/10.
    $booking = checkedOutBooking($this->property, $this->category, $this->room, '2026-10-02', '2026-10-02 04:30:00');

    $audit = app(NightAudits::class)->run($this->property, CarbonImmutable::parse('2026-10-02'));

    expect($audit->status)->toBe(NightAudit::FAILED)
        ->and($audit->error)->toContain("Invoice check failed for booking(s) {$booking->booking_ref_id} (no invoice)");

    $this->actingAs($this->admin)->get(route('admin.nightAudits.edit', $this->property))->assertInertia(fn ($page) => $page
        ->where('invoiceValidation', true)
        ->has('invoiceProblems', 1)
        ->where('invoiceProblems.0.problem', 'missing'));

    $this->actingAs($this->admin)->post(route('admin.nightAudits.invoices', $this->property))->assertSessionHas('success', '1 invoice(s) generated or updated.');
    expect(Invoice::query()->where('booking_id', $booking->id)->value('invoice_amount'))->toEqual(2000);

    expect(app(NightAudits::class)->run($this->property, CarbonImmutable::parse('2026-10-02'))->status)->toBe(NightAudit::COMPLETED);
});

test('an invoice that no longer matches its bill fails the check; updating it keeps its number', function () {
    $this->settings->save($this->property, ['validate_invoice' => true]);
    $booking = checkedOutBooking($this->property, $this->category, $this->room, '2026-10-02', '2026-10-02 04:30:00');
    $this->actingAs($this->admin);
    $invoice = Invoice::generateForBooking($booking, 'Staff');

    // A charge posted after the invoice was issued.
    $booking->update(['total' => 2500]);

    $problems = app(NightAudits::class)->invoiceProblems($this->property, CarbonImmutable::parse('2026-10-02'));
    expect($problems)->toHaveCount(1)
        ->and($problems[0]['problem'])->toBe('outdated');

    $this->post(route('admin.nightAudits.invoices', $this->property))->assertSessionHasNoErrors();

    expect($invoice->fresh())->invoice_number->toBe($invoice->invoice_number)->invoice_amount->toEqual(2500)
        ->and(app(NightAudits::class)->invoiceProblems($this->property, CarbonImmutable::parse('2026-10-02')))->toBe([]);
});

test('without an Invoice Start Date only the day being closed is checked; with one, every check-out since', function () {
    $this->settings->save($this->property, ['validate_invoice' => true]);
    $older = checkedOutBooking($this->property, $this->category, $this->room, '2026-09-30', '2026-09-30 05:00:00');
    $nightAudits = app(NightAudits::class);
    $day = CarbonImmutable::parse('2026-10-02');

    expect($nightAudits->invoiceProblems($this->property, $day))->toBe([]);

    $this->settings->save($this->property, ['invoice_start_date' => '2026-09-29']);
    expect(array_column($nightAudits->invoiceProblems($this->property, $day), 'booking_id'))->toBe([$older->id]);

    $this->settings->save($this->property, ['invoice_start_date' => '2026-10-01']);
    expect($nightAudits->invoiceProblems($this->property, $day))->toBe([]);

    // Off: nothing is checked.
    $this->settings->save($this->property, ['validate_invoice' => false, 'invoice_start_date' => '2026-09-29']);
    expect($nightAudits->run($this->property, $day)->status)->toBe(NightAudit::COMPLETED);
});
