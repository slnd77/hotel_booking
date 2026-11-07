<?php

use App\Enums\BookingStatus;
use App\Enums\ChargeCategory;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Enums\SubBookingStatus;
use App\Enums\TransactionType;
use App\Models\Agency;
use App\Models\Agent;
use App\Models\Booking;
use App\Models\ChargeCode;
use App\Models\CreditNote;
use App\Models\Currency;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Invoice;
use App\Models\Language;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\RoomCategory;
use App\Models\State;
use App\Models\SubBooking;
use App\Models\SubFolio;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReportSettings;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create(['name' => 'Shahashi Singh']);
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);

    // 11:30 on 03/10 in India.
    $this->travelTo(CarbonImmutable::parse('2026-10-03 06:00:00', 'UTC'));

    $this->property = Property::factory()->create(['name' => 'CONCH BHAVAN', 'timezone' => 'Asia/Kolkata', 'currency_id' => $currency->id]);
    $this->category = RoomCategory::factory()->create(['property_id' => $this->property->id, 'name' => 'Suite Room Type B']);
    $this->room = Room::factory()->create(['room_category_id' => $this->category->id, 'name' => 'SRTB -6']);
});

function frontOfficeBooking(Property $property, RoomCategory $category, Room $room, string $ref, string $checkIn, string $checkOut, BookingStatus $status, string $source = Booking::SOURCE_DIRECT): Booking
{
    $booking = $property->bookings()->create([
        'booking_ref_id' => $ref, 'source' => $source, 'type' => 'walk_in', 'status' => $status,
        'check_in' => $checkIn, 'check_out' => $checkOut,
        'room_total' => 11500, 'discount' => 0, 'taxes' => 575, 'total' => 12075, 'outstanding' => 0,
        'room_bill_to' => 'to_guest', 'extras_bill_to' => 'to_guest',
    ]);
    $booking->rooms()->create(['room_category_id' => $category->id, 'room_id' => $room->id, 'meal_plan' => 'EP', 'adults' => 4, 'rate_per_night' => 11500]);

    return $booking;
}

function downloadedWorkbook(string $content): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'dfo').'.xlsx';
    file_put_contents($path, $content);

    return IOFactory::load($path);
}

it('downloads the daily front office workbook with a sheet per section', function () {
    frontOfficeBooking($this->property, $this->category, $this->room, 'CON0026270', '2026-10-03', '2026-10-04', BookingStatus::Confirmed);
    frontOfficeBooking($this->property, $this->category, $this->room, 'CON0026106', '2026-10-02', '2026-10-04', BookingStatus::CheckedIn);
    frontOfficeBooking($this->property, $this->category, $this->room, 'CON0026296', '2026-10-02', '2026-10-03', BookingStatus::CheckedOut, Booking::SOURCE_TRAVEL_AGENT);
    frontOfficeBooking($this->property, $this->category, $this->room, 'CON0026305', '2026-10-03', '2026-10-04', BookingStatus::OnHold);
    frontOfficeBooking($this->property, $this->category, $this->room, 'CON0026999', '2026-10-03', '2026-10-04', BookingStatus::Cancelled);

    $response = $this->get(route('admin.reports.download', [$this->property, 'daily_reports']).'?date=2026-10-03')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('DailyFrontOfficeReport.xlsx');

    $workbook = downloadedWorkbook($response->streamedContent());

    expect($workbook->getSheetNames())->toBe([
        "Today's Confirm Check-In", 'Staying Over', "Today's Check-Out", 'Hold Expiring Today',
        "Today's Hold Check-In", 'Enquiry Check-In Today', 'Yesterday No Show Booking',
    ]);

    $arrivals = $workbook->getSheetByName("Today's Confirm Check-In");

    expect($arrivals->getCell('A2')->getValue())->toStartWith('Daily Front Office Report for CONCH BHAVAN - Oct, 03 2026  Downloaded At 03 Oct, 2026 11:30')
        ->and($arrivals->getCell('A3')->getValue())->toBe("Today's Confirm Check-In (1 Bookings, 1 Rooms)")
        ->and($arrivals->getCell('A4')->getValue())->toBe('S.No.')
        ->and($arrivals->getCell('A5')->getValue())->toBe('Direct')
        ->and($arrivals->rangeToArray('A6:N6', null, true, false)[0])->toBe([1, 'CON0026270', '03 Oct, 2026', '04 Oct, 2026', 'Direct', 'Confirmed', 'No Stay', 'Others', '--', '--', 'Suite Room Type B X 1', 'SRTB -6', 4, 'EP X 1']);

    $headers = $arrivals->rangeToArray('A4:'.$arrivals->getHighestColumn().'4', null, true, false)[0];
    $row = array_combine($headers, $arrivals->rangeToArray('A6:'.$arrivals->getHighestColumn().'6', null, true, false)[0]);

    expect($row)->toMatchArray([
        'Net Tariff' => 11500, 'Total Taxes' => 575, 'Gross Tariff' => 12075, 'Advance Amount' => 12075, 'Net Payable' => '--',
        'Created By' => 'Shahashi Singh', 'Last Modified By' => 'Shahashi Singh', 'Default Tax Choice' => '--',
    ]);

    expect($workbook->getSheetByName('Staying Over')->getCell('B6')->getValue())->toBe('CON0026106')
        ->and($workbook->getSheetByName("Today's Check-Out")->getCell('A5')->getValue())->toBe('Agent')
        ->and($workbook->getSheetByName("Today's Hold Check-In")->getCell('F6')->getValue())->toBe('On-hold')
        ->and($workbook->getSheetByName('Enquiry Check-In Today')->getCell('A3')->getValue())->toBe('Enquiry Check-In Today (0 Bookings, 0 Rooms)')
        ->and($workbook->getSheetByName('Enquiry Check-In Today')->getCell('A4')->getValue())->toBeNull();
});

it('leaves out the columns hidden under Select Columns', function () {
    frontOfficeBooking($this->property, $this->category, $this->room, 'CON0026270', '2026-10-03', '2026-10-04', BookingStatus::Confirmed);
    app(ReportSettings::class)->saveReport($this->property, 'daily_reports', ['hidden_columns' => ['s_no', 'guest_email']]);

    $workbook = downloadedWorkbook($this->get(route('admin.reports.download', [$this->property, 'daily_reports']).'?date=2026-10-03')->streamedContent());
    $sheet = $workbook->getSheet(0);
    $headers = $sheet->rangeToArray('A4:'.$sheet->getHighestColumn().'4')[0];

    expect($headers[0])->toBe('Reservation No.')
        ->and($headers)->not->toContain('Guest E-mail Id')
        ->and($headers)->toContain('Guest Contact No.');
});

it('only downloads reports that are built', function () {
    $this->get(route('admin.reports.download', [$this->property, 'post_master_report']))->assertNotFound();
});

it('downloads the same report as a PDF', function () {
    Pdf::fake();
    frontOfficeBooking($this->property, $this->category, $this->room, 'CON0026270', '2026-10-03', '2026-10-04', BookingStatus::Confirmed);
    app(ReportSettings::class)->saveReport($this->property, 'daily_reports', ['hidden_columns' => ['guest_email']]);

    $this->get(route('admin.reports.download', [$this->property, 'daily_reports']).'?date=2026-10-03&type=pdf')->assertOk();

    Pdf::assertRespondedWithPdf(fn (PdfBuilder $pdf): bool => $pdf->isDownload()
        && $pdf->downloadName === 'DailyFrontOfficeReport.pdf'
        && $pdf->contains('Daily Front Office Report for CONCH BHAVAN - Oct, 03 2026')
        && $pdf->contains('Confirm Check-In (1 Bookings, 1 Rooms)')
        && $pdf->contains('Yesterday No Show Booking (0 Bookings, 0 Rooms)')
        && $pdf->contains('CON0026270')
        && ! $pdf->contains('Guest E-mail Id'));
});

/** Backdates a booking's created / updated times (UTC) without the model touching them. */
function stampBooking(Booking $booking, string $createdAt, ?string $updatedAt = null): void
{
    $booking->timestamps = false;
    $booking->forceFill(['created_at' => $createdAt, 'updated_at' => $updatedAt ?? $createdAt])->save();
}

it('downloads the daily sales workbook with the day\'s new, modified, cancelled and enquiry bookings', function () {
    // "Today" is 03/10 in India: 02/10 18:30 UTC to 03/10 18:30 UTC.
    frontOfficeBooking($this->property, $this->category, $this->room, 'NEW-1', '2026-10-10', '2026-10-11', BookingStatus::Confirmed);
    frontOfficeBooking($this->property, $this->category, $this->room, 'HOLD-1', '2026-10-20', '2026-10-22', BookingStatus::OnHold);
    stampBooking(frontOfficeBooking($this->property, $this->category, $this->room, 'MOD-1', '2026-11-04', '2026-11-09', BookingStatus::Confirmed), '2026-09-22 05:00:00', '2026-10-03 05:00:00');
    stampBooking(frontOfficeBooking($this->property, $this->category, $this->room, 'CXL-1', '2026-11-01', '2026-11-02', BookingStatus::Cancelled), '2026-09-20 05:00:00', '2026-10-02 19:00:00');
    stampBooking(frontOfficeBooking($this->property, $this->category, $this->room, 'OLD-1', '2026-11-01', '2026-11-02', BookingStatus::Confirmed), '2026-09-20 05:00:00', '2026-10-02 17:00:00');
    frontOfficeBooking($this->property, $this->category, $this->room, 'ENQ-1', '2026-12-01', '2026-12-02', BookingStatus::Enquiry);

    $response = $this->get(route('admin.reports.download', [$this->property, 'daily_sales_reports']).'?date=2026-10-03')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('DailySalesReport.xlsx');

    $workbook = downloadedWorkbook($response->streamedContent());

    expect($workbook->getSheetNames())->toBe(['New Confirmed Booking', 'New Room Holdings', 'Modified Confirmation & Hold Ro', 'Rooms Cancelled', 'Enquiry Created Today']);

    $newSheet = $workbook->getSheet(0);

    expect($newSheet->getCell('A2')->getValue())->toBe('Daily Sales Report for CONCH BHAVAN - Oct, 03 2026')
        ->and($newSheet->getCell('A3')->getValue())->toBe('New Confirmed Booking (1 Bookings, 1 Rooms)')
        ->and($newSheet->getStyle('A3')->getFill()->getStartColor()->getRGB())->toBe('669966')
        ->and($newSheet->getCell('B6')->getValue())->toBe('NEW-1')
        ->and($workbook->getSheet(1)->getCell('B6')->getValue())->toBe('HOLD-1')
        ->and($workbook->getSheet(2)->getCell('A3')->getValue())->toBe('Modified Confirmation & Hold Rooms (1 Bookings, 1 Rooms)')
        ->and($workbook->getSheet(2)->getCell('B6')->getValue())->toBe('MOD-1')
        ->and($workbook->getSheet(3)->getCell('B6')->getValue())->toBe('CXL-1')
        ->and($workbook->getSheet(4)->getCell('B6')->getValue())->toBe('ENQ-1');
});

it('downloads the daily sales report as a PDF', function () {
    Pdf::fake();

    $this->get(route('admin.reports.download', [$this->property, 'daily_sales_reports']).'?date=2026-10-03&type=pdf')->assertOk();

    Pdf::assertRespondedWithPdf(fn (PdfBuilder $pdf): bool => $pdf->downloadName === 'DailySalesReport.pdf'
        && $pdf->contains('Daily Sales Report for CONCH BHAVAN - Oct, 03 2026')
        && $pdf->contains('Enquiry Created Today (0 Bookings, 0 Rooms)'));
});

it('downloads the inventory availability per night and category', function () {
    $suite = RoomCategory::factory()->create(['property_id' => $this->property->id, 'name' => 'Super Deluxe Type A', 'display_order' => 2]);
    Room::factory()->count(3)->create(['room_category_id' => $suite->id]);
    // One Suite Room Type B room (from beforeEach) is taken the night of 03/10.
    frontOfficeBooking($this->property, $this->category, $this->room, 'STAY-1', '2026-10-03', '2026-10-04', BookingStatus::Confirmed);

    $response = $this->get(route('admin.reports.download', [$this->property, 'inventory_availability_report']).'?date=2026-10-03&end_date=2026-10-04')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('InventoryAvailability.xlsx');

    $sheet = downloadedWorkbook($response->streamedContent())->getSheet(0);
    $lastColumn = $sheet->getHighestColumn();
    $headers = $sheet->rangeToArray("A3:{$lastColumn}3", null, true, false)[0];
    $nights = $sheet->rangeToArray("A4:{$lastColumn}5", null, true, false);
    $column = array_flip(array_filter($headers));

    expect($sheet->getTitle())->toBe('CONCH BHAVAN')
        ->and($sheet->getCell('A2')->getValue())->toBe('CONCH BHAVAN - Category Wise Availability - 03-Oct-2026 - 04-Oct-2026')
        ->and($headers)->toContain('SUITE ROOM TYPE B', 'SUPER DELUXE TYPE A')
        ->and(end($headers))->toBe('TOTAL')
        ->and($nights[0][0])->toBe('2026-10-03')
        ->and($nights[0][$column['SUITE ROOM TYPE B']])->toBe(0)
        ->and($nights[0][$column['SUPER DELUXE TYPE A']])->toBe(3)
        ->and($nights[1][0])->toBe('2026-10-04')
        ->and($nights[1][$column['SUITE ROOM TYPE B']])->toBe(1)
        // TOTAL is the sum of the night's categories.
        ->and($nights[0][$column['TOTAL']])->toBe(array_sum(array_slice($nights[0], 1, -1)))
        ->and($nights[1][$column['TOTAL']] - $nights[0][$column['TOTAL']])->toBe(1);
});

it('limits the inventory availability range', function () {
    $this->get(route('admin.reports.download', [$this->property, 'inventory_availability_report']).'?date=2026-10-01&end_date=2027-03-01')->assertStatus(422);
});

it('downloads the occupied rooms workbook with a row per room sold for the night', function () {
    $secondRoom = Room::factory()->create(['room_category_id' => $this->category->id, 'name' => 'SRTB -2']);

    $inHouse = frontOfficeBooking($this->property, $this->category, $this->room, 'CON0026106', '2026-10-02', '2026-10-04', BookingStatus::CheckedIn);
    $inHouse->rooms()->first()->update(['checked_in_at' => '2026-10-02 08:45:00']);
    $inHouse->guests()->attach(Guest::factory()->create(['property_id' => $this->property->id, 'title' => null, 'first_name' => 'Asha', 'last_name' => 'Rao', 'nationality' => 'Indian']));
    frontOfficeBooking($this->property, $this->category, $secondRoom, 'CON0026270', '2026-10-03', '2026-10-04', BookingStatus::Confirmed);
    // Departs today, on hold or cancelled: not sold for the night of 03/10.
    frontOfficeBooking($this->property, $this->category, $secondRoom, 'CON0026296', '2026-10-02', '2026-10-03', BookingStatus::CheckedOut);
    frontOfficeBooking($this->property, $this->category, $this->room, 'CON0026305', '2026-10-03', '2026-10-04', BookingStatus::OnHold);
    frontOfficeBooking($this->property, $this->category, $this->room, 'CON0026999', '2026-10-03', '2026-10-04', BookingStatus::Cancelled);

    $response = $this->get(route('admin.reports.download', [$this->property, 'occupied_rooms_reports']).'?date=2026-10-03')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('OccupiedRoomsReport.xlsx');

    $sheet = downloadedWorkbook($response->streamedContent())->getSheet(0);
    $headers = $sheet->rangeToArray('A4:'.$sheet->getHighestColumn().'4', null, true, false)[0];
    $rows = array_map(fn (array $cells): array => array_combine($headers, $cells), $sheet->rangeToArray('A5:'.$sheet->getHighestColumn().'6', null, true, false));

    expect($sheet->getTitle())->toBe('Occupied Rooms')
        ->and($sheet->getCell('A2')->getValue())->toStartWith('Occupied Rooms Report for CONCH BHAVAN - Oct, 03 2026  Downloaded At 03 Oct, 2026 11:30')
        ->and($sheet->getCell('A3')->getValue())->toBe('Occupied Rooms (2 Rooms, 2 Bookings)')
        ->and($headers)->toHaveCount(28)
        ->and($sheet->getCell('A7')->getValue())->toBeNull()
        ->and(array_column($rows, 'Room No.'))->toBe(['SRTB -2', 'SRTB -6']);

    expect($rows[1])->toMatchArray([
        'Room Category' => 'Suite Room Type B', 'Guest Name' => 'Asha Rao', 'Arrival Date' => '02 Oct, 2026', 'Departure Date' => '04 Oct, 2026',
        'Total Adults' => 4, 'Meal Plan' => 'EP', 'Room Cost' => 11500, 'Discount' => '--', 'Total Tax' => 287.5, 'Total Price' => 11787.5,
        'Nationality' => 'Indian', 'Current Status' => 'Checked In', 'Check-In Time' => '02 Oct, 2026 14:15', 'Check-Out Time' => '--',
    ])->and($rows[0])->toMatchArray(['Guest Name' => '--', 'Current Status' => 'No Stay', 'Total Tax' => 575]);
});

it('downloads the occupied rooms report as a PDF without the hidden columns', function () {
    Pdf::fake();
    frontOfficeBooking($this->property, $this->category, $this->room, 'CON0026106', '2026-10-02', '2026-10-04', BookingStatus::CheckedIn);
    app(ReportSettings::class)->saveReport($this->property, 'occupied_rooms_reports', ['hidden_columns' => ['folio_number']]);

    $this->get(route('admin.reports.download', [$this->property, 'occupied_rooms_reports']).'?date=2026-10-03&type=pdf')->assertOk();

    Pdf::assertRespondedWithPdf(fn (PdfBuilder $pdf): bool => $pdf->downloadName === 'OccupiedRoomsReport.pdf'
        && $pdf->contains('Occupied Rooms Report for CONCH BHAVAN - Oct, 03 2026')
        && $pdf->contains('Occupied Rooms (1 Rooms, 1 Bookings)')
        && $pdf->contains('SRTB -6')
        && ! $pdf->contains('Folio Number'));
});

/** A payment on the property at a UTC time, collected by $staff. */
function cashierPayment(Property $property, string $receiptNumber, string $txnAt, float $amount, array $overrides = []): Payment
{
    return Payment::create([
        'property_id' => $property->id, 'type' => 'receipt', 'receipt_number' => $receiptNumber, 'guest_name' => 'Asha Rao',
        'payee_name' => 'Guest', 'payment_mode' => PaymentMode::Cash, 'total_txn_amount' => $amount, 'tagged_amount' => $amount, 'untagged_amount' => 0,
        'payment_creation_date' => $txnAt, 'payment_transaction_date' => $txnAt, 'status' => PaymentStatus::Received,
        'collected_by_staff' => 'Shahashi Singh', ...$overrides,
    ]);
}

it('downloads the cashier workbook with the day\'s receipts, a total row and the refunds', function () {
    // "Today" is 03/10 in India: 02/10 18:30 UTC to 03/10 18:30 UTC.
    cashierPayment($this->property, 'RCPT-1', '2026-10-02 19:00:00', 1500, ['payment_ref_id' => 'UTR123', 'payment_mode' => PaymentMode::UPI, 'notes' => 'Advance']);
    cashierPayment($this->property, 'RCPT-2', '2026-10-03 06:00:00', 2500.5, ['tagged_amount' => 2000, 'untagged_amount' => 500.5, 'collected_by_staff' => 'Ravi']);
    cashierPayment($this->property, 'RCPT-OLD', '2026-10-02 18:00:00', 999);
    cashierPayment($this->property, 'RCPT-CXL', '2026-10-03 07:00:00', 999, ['status' => PaymentStatus::Cancelled]);
    cashierPayment($this->property, 'RF-1', '2026-10-03 08:00:00', 300, ['type' => 'refund', 'payee_name' => 'Asha Rao', 'status' => PaymentStatus::Refunded]);

    $response = $this->get(route('admin.reports.download', [$this->property, 'daily_cashier_report']).'?date=2026-10-03')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('PropertyCashierReport - CONCH BHAVAN.xlsx');

    $workbook = downloadedWorkbook($response->streamedContent());
    $receipts = $workbook->getSheetByName('Receipts');
    $rows = $receipts->rangeToArray('A1:P4', null, true, false);

    expect($workbook->getSheetNames())->toBe(['Receipts', 'Refund', 'Shifts'])
        ->and($rows[0])->toBe(['Receipt Number.', 'Guest Name', 'Txn Date', 'Reference No. 1', 'Reference No. 2', 'Mode', 'Total Amount', 'Total Tagged Amount', 'Total PG Commission Amount (If applicable)', 'Notes', 'Collection Source', 'Paid By', 'Party Name', 'Collected By Staff', 'Currency', 'Currency Amount'])
        ->and($rows[1])->toBe(['RCPT-1', 'Asha Rao', '03 Oct, 2026 00:30', 'UTR123', '--', 'UPI', 1500, 1500, '--', 'Advance', '--', 'Guest', '--', 'Shahashi Singh', 'USD', 1500])
        ->and($rows[2][0])->toBe('RCPT-2')
        ->and($rows[3])->toBe(['Total', '--', '--', '--', '--', '--', 4000.5, 3500, '--', '--', '--', '--', '--', '--', '--', 4000.5])
        ->and($receipts->getCell('A5')->getValue())->toBeNull()
        ->and($receipts->getStyle('A1')->getFont()->getColor()->getRGB())->toBe('0000FF');

    expect($workbook->getSheetByName('Refund')->rangeToArray('A1:I2', null, true, false))->toBe([
        ['Txn Date', 'Refund Number.', 'Mode', 'Total Amount', 'Issued To', 'Reference No. 1', 'Reference No. 2', 'Refund Status', 'Notes'],
        ['03 Oct, 2026 13:30', 'RF-1', 'Cash', 300, 'Asha Rao', '--', '--', 'Refunded', '--'],
    ]);
});

it('narrows the cashier report to one staff member and leaves out hidden columns', function () {
    cashierPayment($this->property, 'RCPT-1', '2026-10-03 05:00:00', 1500);
    cashierPayment($this->property, 'RCPT-2', '2026-10-03 06:00:00', 2500, ['collected_by_staff' => 'Ravi']);
    app(ReportSettings::class)->saveReport($this->property, 'daily_cashier_report', ['hidden_columns' => ['receipt_number', 'notes']]);

    $workbook = downloadedWorkbook($this->get(route('admin.reports.download', [$this->property, 'daily_cashier_report']).'?date=2026-10-03&staff='.$this->admin->id)->streamedContent());
    $receipts = $workbook->getSheetByName('Receipts');
    $headers = $receipts->rangeToArray('A1:'.$receipts->getHighestColumn().'1')[0];

    expect($headers[0])->toBe('Guest Name')
        ->and($headers)->not->toContain('Notes')
        ->and($receipts->getHighestRow())->toBe(3)
        ->and($receipts->getCell('A3')->getValue())->toBe('Total')
        ->and($receipts->getCell('F3')->getValue())->toBe(1500);
});

it('downloads the cashier report as a PDF', function () {
    Pdf::fake();
    cashierPayment($this->property, 'RCPT-1', '2026-10-03 05:00:00', 1500);

    $this->get(route('admin.reports.download', [$this->property, 'daily_cashier_report']).'?date=2026-10-03&type=pdf')->assertOk();

    Pdf::assertRespondedWithPdf(fn (PdfBuilder $pdf): bool => $pdf->downloadName === 'PropertyCashierReport - CONCH BHAVAN.pdf'
        && $pdf->contains('Daily Cashier Report for CONCH BHAVAN - Oct, 03 2026')
        && $pdf->contains('RCPT-1')
        && $pdf->contains('No refund on this date.'));
});

/** Checks out every room of the booking at a UTC time. */
function checkOutRooms(Booking $booking, string $checkedOutAt): Booking
{
    $booking->rooms()->update(['status' => SubBookingStatus::CheckedOut->value, 'checked_out_at' => $checkedOutAt]);

    return $booking;
}

it('downloads the checked-out bookings changed on the day after their checkout', function () {
    $secondRoom = Room::factory()->create(['room_category_id' => $this->category->id, 'name' => 'SRTB -2']);

    // Made (and their room charges posted) well before any checkout.
    $this->travelTo(CarbonImmutable::parse('2026-09-15 05:00:00', 'UTC'));
    $edited = checkOutRooms(frontOfficeBooking($this->property, $this->category, $this->room, 'CON-EDIT', '2026-09-29', '2026-10-01', BookingStatus::CheckedOut), '2026-10-01 05:00:00');
    stampBooking($edited, '2026-09-25 05:00:00', '2026-10-03 05:00:00');

    $posted = checkOutRooms(frontOfficeBooking($this->property, $this->category, $secondRoom, 'CON-POST', '2026-09-29', '2026-10-01', BookingStatus::CheckedOut), '2026-10-01 06:00:00');
    stampBooking($posted, '2026-09-25 05:00:00', '2026-10-01 06:00:00');
    $checkedOutToday = checkOutRooms(frontOfficeBooking($this->property, $this->category, $this->room, 'CON-OUT', '2026-10-01', '2026-10-03', BookingStatus::CheckedOut), '2026-10-03 05:00:00');
    $changedYesterday = checkOutRooms(frontOfficeBooking($this->property, $this->category, $this->room, 'CON-OLD', '2026-09-20', '2026-09-22', BookingStatus::CheckedOut), '2026-09-22 05:00:00');

    // "Today" is 03/10 in India: 02/10 18:30 UTC to 03/10 18:30 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-03 06:00:00', 'UTC'));
    $folio = Folio::factory()->create(['owner_type' => (new SubBooking)->getMorphClass(), 'owner_id' => $posted->rooms()->first()->id]);
    Transaction::factory()->create(['sub_folio_id' => SubFolio::factory()->create(['folio_id' => $folio->id])->id, 'posted_at' => '2026-10-03 07:15:00']);

    // Checked out today (the checkout's own save isn't a later change), and changed yesterday.
    stampBooking($checkedOutToday, '2026-09-25 05:00:00', '2026-10-03 05:00:20');
    stampBooking($changedYesterday, '2026-09-15 05:00:00', '2026-10-02 18:00:00');

    $response = $this->get(route('admin.reports.download', [$this->property, 'checked_out_folios_modification_report']).'?date=2026-10-03')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('CheckedOutFoliosModificationReport.xlsx');

    $sheet = downloadedWorkbook($response->streamedContent())->getSheet(0);

    expect($sheet->getCell('A2')->getValue())->toStartWith('CheckedOut Folios Modification Report for CONCH BHAVAN - Oct, 03 2026')
        ->and($sheet->getCell('A3')->getValue())->toBe('Checked Out Folios Modified (2 Bookings)')
        ->and($sheet->rangeToArray('A4:J6', null, true, false))->toBe([
            ['S.No.', 'Reservation No.', 'Reference No.', 'Arrival Date', 'Departure Date', 'Checkout Date', 'Last Modified Date', 'Created Date', 'Modified By', 'Room Number'],
            [1, 'CON-EDIT', '--', '29 Sep, 2026', '01 Oct, 2026', '01 Oct, 2026 10:30', '03 Oct, 2026 10:30', '25 Sep, 2026', 'Shahashi Singh', 'SRTB -6'],
            [2, 'CON-POST', '--', '29 Sep, 2026', '01 Oct, 2026', '01 Oct, 2026 11:30', '03 Oct, 2026 12:45', '25 Sep, 2026', 'Shahashi Singh', 'SRTB -2'],
        ])
        ->and($sheet->getCell('A7')->getValue())->toBeNull();
});

it('leaves out the checked-out folio columns hidden under Select Columns', function () {
    app(ReportSettings::class)->saveReport($this->property, 'checked_out_folios_modification_report', ['hidden_columns' => ['s_no', 'reference_no']]);

    $sheet = downloadedWorkbook($this->get(route('admin.reports.download', [$this->property, 'checked_out_folios_modification_report']).'?date=2026-10-03')->streamedContent())->getSheet(0);

    expect($sheet->rangeToArray('A4:H4')[0])->toBe(['Reservation No.', 'Arrival Date', 'Departure Date', 'Checkout Date', 'Last Modified Date', 'Created Date', 'Modified By', 'Room Number']);
});

/** The one-sheet report's data rows (under the row 4 headers), as header => cell. */
function sheetRows(Spreadsheet $workbook): array
{
    $sheet = $workbook->getSheet(0);
    $lastColumn = $sheet->getHighestColumn();
    $headers = $sheet->rangeToArray("A4:{$lastColumn}4", null, true, false)[0];

    return array_map(fn (array $cells): array => array_combine($headers, $cells), $sheet->rangeToArray("A5:{$lastColumn}".max(5, $sheet->getHighestRow()), null, true, false));
}

it('downloads the daily transaction workbook with each room\'s night', function () {
    $secondRoom = Room::factory()->create(['room_category_id' => $this->category->id, 'name' => 'SRTB -2']);

    $inHouse = frontOfficeBooking($this->property, $this->category, $this->room, 'CON-STAY', '2026-10-02', '2026-10-04', BookingStatus::CheckedIn);
    $inHouse->guests()->attach(Guest::factory()->create(['property_id' => $this->property->id, 'title' => null, 'first_name' => 'Asha', 'last_name' => 'Rao', 'company' => ['name' => 'Acme Pvt Ltd']]));
    frontOfficeBooking($this->property, $this->category, $secondRoom, 'CON-ARR', '2026-10-03', '2026-10-04', BookingStatus::Confirmed, Booking::SOURCE_TRAVEL_AGENT)->update(['discount' => 1000]);
    frontOfficeBooking($this->property, $this->category, $secondRoom, 'CON-DEP', '2026-10-01', '2026-10-03', BookingStatus::CheckedOut);

    $response = $this->get(route('admin.reports.download', [$this->property, 'daily_transaction_report']).'?date=2026-10-03')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('DailyTransactionReport.xlsx');

    $workbook = downloadedWorkbook($response->streamedContent());
    $rows = sheetRows($workbook);

    expect($workbook->getSheet(0)->getCell('A3')->getValue())->toBe('Daily Transaction (2 Rooms, 2 Bookings)')
        ->and(array_column($rows, 'Room No.'))->toBe(['SRTB -2', 'SRTB -6'])
        ->and($rows[0])->toMatchArray([
            'Type of Reservation' => 'Agent', 'No. of Pax' => 4, 'Room Nights' => 1, 'Package Type' => 'EP',
            'Room Rate' => 11500, 'Adjusted Room Rate' => 10500, 'Room Only Cost (Net)' => 10500, 'Room Only Cost (Gross)' => 11075,
            'Meal Plan Cost (Gross)' => '--', 'Package (Net)' => 10500, 'Package (Gross)' => 11075, 'Date Of Transaction' => '03 Oct, 2026',
        ])
        ->and($rows[1])->toMatchArray(['Guest Name' => 'Asha Rao', 'Company' => 'Acme Pvt Ltd', 'Room Rate' => 11500, 'Room Only Cost (Gross)' => 11787.5]);
});

it('downloads the house keeping workbook with every room\'s state for the day', function () {
    $departing = Room::factory()->create(['room_category_id' => $this->category->id, 'name' => 'SRTB -2', 'housekeeping_status' => 'Dirty']);
    $blocked = Room::factory()->create(['room_category_id' => $this->category->id, 'name' => 'SRTB -9']);
    Room::factory()->create(['room_category_id' => $this->category->id, 'name' => 'SRTB -10']);
    RoomBlock::factory()->create(['property_id' => $this->property->id, 'room_id' => $blocked->id, 'start_date' => '2026-10-03', 'end_date' => '2026-10-05']);

    $inHouse = frontOfficeBooking($this->property, $this->category, $this->room, 'CON-STAY', '2026-10-02', '2026-10-04', BookingStatus::CheckedIn);
    $inHouse->rooms()->update(['checked_in_at' => '2026-10-02 08:45:00', 'children' => 1]);
    checkOutRooms(frontOfficeBooking($this->property, $this->category, $departing, 'CON-DEP', '2026-10-01', '2026-10-03', BookingStatus::CheckedOut), '2026-10-03 04:30:00');
    frontOfficeBooking($this->property, $this->category, $departing, 'CON-ARR', '2026-10-03', '2026-10-05', BookingStatus::Confirmed);

    $response = $this->get(route('admin.reports.download', [$this->property, 'daily_housekeeping_report']).'?date=2026-10-03')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('DailyHouseKeepingReport.xlsx');

    $workbook = downloadedWorkbook($response->streamedContent());
    $allRows = sheetRows($workbook);
    // The factory property comes with its own rooms; ours are the SRTB ones.
    $rows = collect($allRows)->filter(fn (array $row): bool => str_starts_with((string) $row['Room No.'], 'SRTB'))->keyBy(fn (array $row): string => $row['Room No.']);
    $roomCount = Room::query()->whereHas('roomCategory', fn ($query) => $query->where('property_id', $this->property->id))->count();

    expect($workbook->getSheet(0)->getCell('A3')->getValue())->toBe("House Keeping ({$roomCount} Rooms, 2 Occupied)")
        ->and($allRows)->toHaveCount($roomCount)
        ->and($rows->keys()->all())->toBe(['SRTB -2', 'SRTB -6', 'SRTB -9', 'SRTB -10'])
        ->and($rows['SRTB -6'])->toMatchArray([
            'Room Status' => 'Clean', 'Arrival Date' => '02 Oct, 2026', 'Departure Date' => '04 Oct, 2026', 'Time-in' => '02 Oct, 2026 14:15',
            'Check-out' => '--', 'MUR' => null, 'Room State' => 'Occupied', 'Pax' => 5, 'Adults' => 4, 'Children' => 1, 'Infant' => '--',
        ])
        ->and($rows['SRTB -2'])->toMatchArray([
            'Room Status' => 'Dirty', 'Arrival Date' => '03 Oct, 2026', 'Time-out' => '03 Oct, 2026 10:00', 'Check-out' => 'Yes', 'Room State' => 'Checked Out / Arrival',
        ])
        ->and($rows['SRTB -9']['Room State'])->toBe('Blocked')
        ->and($rows['SRTB -10'])->toMatchArray(['Room State' => 'Vacant', 'Arrival Date' => '--', 'Pax' => '--']);
});

/** Sets booking columns directly, so the ledger sync doesn't recompute the balance. */
function setBookingQuietly(Booking $booking, array $values): void
{
    Booking::query()->whereKey($booking->id)->update($values);
}

it('downloads the account receivables workbook aged by checkout', function () {
    $agency = Agency::factory()->create(['property_id' => $this->property->id, 'name' => 'Blue Sky Travels', 'code' => 'AG001']);
    $agent = Agent::factory()->create(['agency_id' => $agency->id]);

    // 2000 billed 13 days ago (September), 3000 billed 70 days ago (July).
    setBookingQuietly(frontOfficeBooking($this->property, $this->category, $this->room, 'AG-1', '2026-09-18', '2026-09-20', BookingStatus::CheckedOut, Booking::SOURCE_TRAVEL_AGENT), ['agent_id' => $agent->id, 'outstanding' => 2000]);
    setBookingQuietly(frontOfficeBooking($this->property, $this->category, $this->room, 'AG-2', '2026-07-22', '2026-07-25', BookingStatus::CheckedOut, Booking::SOURCE_TRAVEL_AGENT), ['agent_id' => $agent->id, 'outstanding' => 3000]);
    $company = frontOfficeBooking($this->property, $this->category, $this->room, 'CO-1', '2026-10-02', '2026-10-04', BookingStatus::CheckedIn);
    setBookingQuietly($company, ['room_bill_to' => 'to_company', 'outstanding' => 500]);
    $company->guests()->attach(Guest::factory()->create(['property_id' => $this->property->id, 'company' => ['name' => 'Acme Pvt Ltd', 'gstin' => '27AAACA1234A1Z5']]));
    // Not yet arrived: left out.
    setBookingQuietly(frontOfficeBooking($this->property, $this->category, $this->room, 'AG-FUTURE', '2026-10-10', '2026-10-11', BookingStatus::Confirmed), ['agent_id' => $agent->id, 'outstanding' => 12075]);

    $response = $this->get(route('admin.reports.download', [$this->property, 'account_receivables_report']).'?date=2026-10-03')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('AccountReceivablesReport.xlsx');

    $workbook = downloadedWorkbook($response->streamedContent());
    $rows = sheetRows($workbook);

    expect($workbook->getSheet(0)->getCell('A3')->getValue())->toBe('Account Receivables (2 Accounts, 5,500.00 Outstanding)')
        ->and($rows)->toHaveCount(3)
        ->and($rows[0])->toMatchArray([
            'Corporate ID' => '27AAACA1234A1Z5', 'Corporate Account' => 'Acme Pvt Ltd', 'Total Amount' => 12075, 'Total Amount Paid' => 11575,
            'Total Outstanding Amount' => 500, '0-30 Days Outstanding' => 500, 'Current Month Outstanding' => 500,
        ])
        ->and($rows[1])->toMatchArray([
            'Corporate ID' => 'AG001', 'Corporate Account' => 'Blue Sky Travels', 'Total Amount' => 24150, 'Total Amount Paid' => 19150,
            'Total Outstanding Amount' => 5000, '0-30 Days Outstanding' => 2000, '61-90 Days Outstanding' => 3000, '31-60 Days Outstanding' => '--',
            'Previous Month Outstanding' => 2000, '2 Months Ago Outstanding' => '--', '3 Months & Before Outstanding' => 3000,
        ])
        ->and($rows[2])->toMatchArray(['Corporate ID' => 'Total', 'Corporate Account' => '--', 'Total Outstanding Amount' => 5500]);
});

/** An invoice generated at a UTC time with its taxable value and tax. */
function gstInvoice(Property $property, string $number, string $generatedAt, float $taxable, float $tax, array $overrides = []): Invoice
{
    return Invoice::create([
        'property_id' => $property->id, 'invoice_number' => $number, 'invoice_generation_date' => $generatedAt,
        'status' => InvoiceStatus::BillGenerated, 'guest_name' => 'Asha Rao', 'party_name' => 'Asha Rao',
        'invoice_amount' => $taxable + $tax, 'taxable_amount' => $taxable, 'total_tax' => $tax, 'room_nights' => 1, 'rooms_count' => 1,
        ...$overrides,
    ]);
}

it('downloads the GSTR-1 sales register in the official template layout', function () {
    $state = State::query()->create(['name' => 'Maharashtra', 'country_id' => $this->property->country_id]);
    $this->property->update(['state_id' => $state->id]);

    // October 2026 in India.
    gstInvoice($this->property, 'INVCON000001', '2026-10-01 05:00:00', 10000, 1200, ['party_name' => 'Acme Pvt Ltd', 'party_gstin' => '27aaaca1234a1z5', 'room_nights' => 2]);
    gstInvoice($this->property, 'INVCON000002', '2026-10-02 05:00:00', 5000, 250);
    gstInvoice($this->property, 'INVCON000003', '2026-10-02 06:00:00', 3000, 360, ['party_gstin' => 'NOT-A-GSTIN']);
    gstInvoice($this->property, 'INVCON000004', '2026-10-03 05:00:00', 800, 0);
    gstInvoice($this->property, 'INVCON000005', '2026-10-03 06:00:00', 2000, 240, ['status' => InvoiceStatus::Cancelled]);
    gstInvoice($this->property, 'INVCON000099', '2026-09-30 18:00:00', 9999, 1200);

    $response = $this->get(route('admin.reports.download', [$this->property, 'gstr1_sales_register_report']).'?date=2026-10-01&end_date=2026-10-31')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('GSTR1SalesRegisterReport.xlsx');

    $workbook = downloadedWorkbook($response->streamedContent());
    $table = fn (string $sheet, string $range): array => $workbook->getSheetByName($sheet)->rangeToArray($range, null, true, false);

    expect($workbook->getSheetNames())->toBe(['b2b', 'b2cl', 'b2cs', 'cdnr', 'cdnur', 'exp', 'at', 'atadj', 'exemp', 'hsn(b2b)', 'hsn(b2c)', 'docs'])
        ->and($workbook->getSheetByName('b2b')->getCell('A1')->getValue())->toBe('Summary For B2B(4)')
        ->and($table('b2b', 'A3:M5'))->toBe([
            [1, null, 1, null, 11200, null, null, null, null, null, null, 10000, 0],
            ['GSTIN/UIN of Recipient', 'Receiver Name', 'Invoice Number', 'Invoice date', 'Invoice Value', 'Place Of Supply', 'Reverse Charge', 'Applicable % of Tax Rate', 'Invoice Type', 'E-Commerce GSTIN', 'Rate', 'Taxable Value', 'Cess Amount'],
            ['27AAACA1234A1Z5', 'Acme Pvt Ltd', 'INVCON000001', '01-Oct-2026', 11200, '27-Maharashtra', 'N', null, 'Regular B2B', null, 12, 10000, 0],
        ])
        ->and($table('b2cs', 'A5:G7'))->toBe([
            ['OE', '27-Maharashtra', null, 5, 5000, 0, null],
            ['OE', '27-Maharashtra', null, 12, 3000, 0, null],
            [null, null, null, null, null, null, null],
        ])
        ->and($table('b2cs', 'E3:E3')[0][0])->toBe(8000)
        ->and($workbook->getSheetByName('b2cl')->getCell('A5')->getValue())->toBeNull()
        ->and($table('exemp', 'A6:D8'))->toBe([
            ['Intra-State supplies to registered persons', 0, 0, 0],
            ['Inter-State supplies to unregistered persons', 0, 0, 0],
            ['Intra-State supplies to unregistered persons', 0, 800, 0],
        ])
        ->and($table('hsn(b2b)', 'A5:K5')[0])->toBe([996311, 'Room or unit accommodation services', 'OTH-OTHERS', 2, 11200, 12, 10000, 0, 600, 600, 0])
        ->and($table('hsn(b2c)', 'A5:K7'))->toBe([
            [996311, 'Room or unit accommodation services', 'OTH-OTHERS', 1, 800, 0, 800, 0, 0, 0, 0],
            [996311, 'Room or unit accommodation services', 'OTH-OTHERS', 1, 5250, 5, 5000, 0, 125, 125, 0],
            [996311, 'Room or unit accommodation services', 'OTH-OTHERS', 1, 3360, 12, 3000, 0, 180, 180, 0],
        ])
        ->and($table('docs', 'A5:E5')[0])->toBe(['Invoices for outward supply', 'INVCON000001', 'INVCON000005', 5, 1]);
});

it('reports credit notes in GSTR-1 and nets them out of GSTR-3B', function () {
    $state = State::query()->create(['name' => 'Maharashtra', 'country_id' => $this->property->country_id]);
    $this->property->update(['state_id' => $state->id]);

    $registered = gstInvoice($this->property, 'INVCON000001', '2026-10-01 05:00:00', 10000, 1200, ['party_name' => 'Acme Pvt Ltd', 'party_gstin' => '27AAACA1234A1Z5']);
    $walkIn = gstInvoice($this->property, 'INVCON000002', '2026-10-02 05:00:00', 5000, 600);
    CreditNote::factory()->create(['property_id' => $this->property->id, 'invoice_id' => $registered->id, 'credit_note_number' => 'CN-0001', 'issue_date' => '2026-10-05', 'taxable_amount' => 1000, 'tax_amount' => 120, 'total' => 1120]);
    CreditNote::factory()->create(['property_id' => $this->property->id, 'invoice_id' => $walkIn->id, 'credit_note_number' => 'CN-0002', 'issue_date' => '2026-10-06', 'taxable_amount' => 500, 'tax_amount' => 60, 'total' => 560]);

    $gstr1 = downloadedWorkbook($this->get(route('admin.reports.download', [$this->property, 'gstr1_sales_register_report']).'?date=2026-10-01&end_date=2026-10-31')->assertOk()->streamedContent());
    $table = fn (string $sheet, string $range): array => $gstr1->getSheetByName($sheet)->rangeToArray($range, null, true, false);

    expect($table('cdnr', 'A3:M3')[0])->toBe([1, null, 1, null, null, null, null, null, 1120, null, null, 1000, 0])
        ->and($table('cdnr', 'A5:M5')[0])->toBe(['27AAACA1234A1Z5', 'Acme Pvt Ltd', 'CN-0001', '05-Oct-2026', 'C', '27-Maharashtra', 'N', 'Regular B2B', 1120, null, 12, 1000, 0])
        // The walk-in's note comes off B2CS: 5 000 − 500.
        ->and($table('b2cs', 'A5:G5')[0])->toBe(['OE', '27-Maharashtra', null, 12, 4500, 0, null])
        ->and($table('hsn(b2b)', 'G5:J5')[0])->toBe([9000, 0, 540, 540])
        ->and($table('docs', 'A6:E6')[0])->toBe(['Credit Note', 'CN-0001', 'CN-0002', 2, 0]);

    $sheet = downloadedWorkbook($this->get(route('admin.reports.download', [$this->property, 'gstr3b_summary_return_report']).'?date=2026-10-01&end_date=2026-10-31')->assertOk()->streamedContent())->getSheet(0);
    $rows = collect($sheet->rangeToArray('A1:I'.$sheet->getHighestRow(), null, true, false))->keyBy(fn (array $row): string => (string) $row[0]);

    // 15 000 − 1 500 taxable; 1 800 − 180 tax, half central and half state.
    expect(array_slice($rows['(a) Outward taxable supplies (other than zero rated, nil rated and exempted)'], 1, 4))->toBe([13500, 0, 810, 810]);
});

it('limits the GSTR-1 range to a quarter', function () {
    $this->get(route('admin.reports.download', [$this->property, 'gstr1_sales_register_report']).'?date=2026-01-01&end_date=2026-06-30')->assertStatus(422);
});

it('downloads the GSTR-3B summary return from the period\'s invoices', function () {
    $state = State::query()->create(['name' => 'Maharashtra', 'country_id' => $this->property->country_id]);
    $this->property->update(['state_id' => $state->id]);

    gstInvoice($this->property, 'INVCON000001', '2026-10-01 05:00:00', 10000, 1200, ['party_gstin' => '27AAACA1234A1Z5']);
    gstInvoice($this->property, 'INVCON000002', '2026-10-02 05:00:00', 5000, 250.5);
    gstInvoice($this->property, 'INVCON000003', '2026-10-03 05:00:00', 800, 0);
    gstInvoice($this->property, 'INVCON000004', '2026-10-03 06:00:00', 2000, 240, ['status' => InvoiceStatus::Cancelled]);

    $response = $this->get(route('admin.reports.download', [$this->property, 'gstr3b_summary_return_report']).'?date=2026-10-01&end_date=2026-10-31')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('GSTR3BSummaryReturnReport.xlsx');

    $sheet = downloadedWorkbook($response->streamedContent())->getSheet(0);
    $rows = collect($sheet->rangeToArray('A1:I'.$sheet->getHighestRow(), null, true, false))->keyBy(fn (array $row): string => (string) $row[0]);

    expect($sheet->getCell('A1')->getValue())->toBe('GSTR-3B Summary Return Report for CONCH BHAVAN (27-Maharashtra) - 01-Oct-2026 to 31-Oct-2026')
        ->and(array_slice($rows['(a) Outward taxable supplies (other than zero rated, nil rated and exempted)'], 0, 6))->toBe(['(a) Outward taxable supplies (other than zero rated, nil rated and exempted)', 15000, 0, 725.25, 725.25, 0])
        ->and($rows['(c) Other outward supplies (Nil rated, exempted)'][1])->toBe(800)
        ->and(array_slice($rows['Central Tax'], 0, 2))->toBe(['Central Tax', 725.25])
        ->and(array_slice($rows['State/UT Tax'], 0, 2))->toBe(['State/UT Tax', 725.25])
        ->and($rows['(5) All other ITC'][1])->toBe(0)
        ->and($rows)->toHaveKey('4. Eligible ITC');
});

it('downloads the HSN/SAC summary as the GSTR-1 HSN sheets', function () {
    gstInvoice($this->property, 'INVCON000001', '2026-10-01 05:00:00', 10000, 1200, ['party_gstin' => '27AAACA1234A1Z5', 'room_nights' => 2]);
    gstInvoice($this->property, 'INVCON000002', '2026-10-02 05:00:00', 5000, 250);
    gstInvoice($this->property, 'INVCON000003', '2026-10-03 06:00:00', 2000, 240, ['status' => InvoiceStatus::Cancelled]);

    $response = $this->get(route('admin.reports.download', [$this->property, 'hsn_sac_summary_report']).'?date=2026-10-01&end_date=2026-10-31')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('HSNSACSummaryReport.xlsx');

    $workbook = downloadedWorkbook($response->streamedContent());

    expect($workbook->getSheetNames())->toBe(['hsn(b2b)', 'hsn(b2c)'])
        ->and($workbook->getSheet(0)->rangeToArray('A3:K5', null, true, false))->toBe([
            [1, null, null, null, 11200, null, 10000, 0, 600, 600, 0],
            ['HSN', 'Description', 'UQC', 'Total Quantity', 'Total Value', 'Rate', 'Taxable Value', 'Integrated Tax Amount', 'Central Tax Amount', 'State/UT Tax Amount', 'Cess Amount'],
            [996311, 'Room or unit accommodation services', 'OTH-OTHERS', 2, 11200, 12, 10000, 0, 600, 600, 0],
        ])
        ->and($workbook->getSheet(1)->rangeToArray('A5:K6', null, true, false))->toBe([
            [996311, 'Room or unit accommodation services', 'OTH-OTHERS', 1, 5250, 5, 5000, 0, 125, 125, 0],
            [null, null, null, null, null, null, null, null, null, null, null],
        ]);
});

it('downloads the nationality revenue for the nights sold in the range', function () {
    $secondRoom = Room::factory()->create(['room_category_id' => $this->category->id, 'name' => 'SRTB -2']);

    // Two nights in range (01/10, 02/10) and one night (02/10); 03/10 is outside the range.
    frontOfficeBooking($this->property, $this->category, $this->room, 'CON-IN', '2026-10-01', '2026-10-03', BookingStatus::CheckedOut)
        ->guests()->attach(Guest::factory()->create(['property_id' => $this->property->id, 'nationality' => 'indian']));
    frontOfficeBooking($this->property, $this->category, $secondRoom, 'CON-FR', '2026-10-02', '2026-10-04', BookingStatus::CheckedIn)
        ->guests()->attach(Guest::factory()->create(['property_id' => $this->property->id, 'nationality' => 'French']));
    frontOfficeBooking($this->property, $this->category, $this->room, 'CON-CXL', '2026-10-01', '2026-10-02', BookingStatus::Cancelled);

    $response = $this->get(route('admin.reports.download', [$this->property, 'nationality_revenue_report']).'?date=2026-10-01&end_date=2026-10-02')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('NationalityRevenueReport.xlsx');

    $sheet = downloadedWorkbook($response->streamedContent())->getSheet(0);

    expect($sheet->getCell('A2')->getValue())->toBe('Nationality Revenue Report for CONCH BHAVAN - 01 Oct, 2026 to 02 Oct, 2026')
        ->and($sheet->getCell('A3')->getValue())->toBe('Nationality Revenue (2 Nationalities, 3 Room Nights)')
        ->and($sheet->rangeToArray('A4:H7', null, true, false))->toBe([
            ['Nationality', 'No. of Bookings', 'Room Nights', 'No. of Guests', 'Room Revenue (Net)', 'Tax', 'Total Revenue (Gross)', 'Revenue Share (%)'],
            ['Indian', 1, 2, 4, 23000, 575, 23575, 66.67],
            ['French', 1, 1, 4, 11500, 287.5, 11787.5, 33.33],
            ['Total', 2, 3, 8, 34500, 862.5, 35362.5, 100],
        ]);
});

it('downloads the nationality revenue as a PDF', function () {
    Pdf::fake();
    frontOfficeBooking($this->property, $this->category, $this->room, 'CON-IN', '2026-10-01', '2026-10-02', BookingStatus::Confirmed);

    $this->get(route('admin.reports.download', [$this->property, 'nationality_revenue_report']).'?date=2026-10-01&end_date=2026-10-31&type=pdf')->assertOk();

    Pdf::assertRespondedWithPdf(fn (PdfBuilder $pdf): bool => $pdf->downloadName === 'NationalityRevenueReport.pdf'
        && $pdf->contains('Nationality Revenue (1 Nationalities, 1 Room Nights)')
        && $pdf->contains('Not Specified'));
});

it('downloads the financial transaction summary from the ledger and the payments', function () {
    // The booking (and its room charges) is made before the range.
    $this->travelTo(CarbonImmutable::parse('2026-09-15 05:00:00', 'UTC'));
    $stay = frontOfficeBooking($this->property, $this->category, $this->room, 'CON-1', '2026-10-01', '2026-10-03', BookingStatus::CheckedIn)->rooms()->first();
    $this->travelTo(CarbonImmutable::parse('2026-10-03 06:00:00', 'UTC'));

    $window = SubFolio::factory()->create(['folio_id' => Folio::factory()->create(['owner_type' => (new SubBooking)->getMorphClass(), 'owner_id' => $stay->id])->id]);
    $minibar = ChargeCode::factory()->create(['code' => 'MINI', 'name' => 'Minibar', 'category' => ChargeCategory::Incidentals]);
    $laundry = ChargeCode::factory()->create(['code' => 'LAUN', 'name' => 'Laundry', 'category' => ChargeCategory::Incidentals]);
    $post = fn (ChargeCode $code, TransactionType $type, float $amount, string $at) => Transaction::factory()->create([
        'sub_folio_id' => $window->id, 'charge_code_id' => $code->id, 'transaction_type' => $type, 'amount' => $amount, 'posted_at' => $at,
    ]);

    // 01/10 to 02/10 in India: 30/09 18:30 UTC to 02/10 18:30 UTC.
    $post($minibar, TransactionType::Debit, 500, '2026-10-01 05:00:00');
    $post($minibar, TransactionType::Debit, 500, '2026-10-02 05:00:00');
    $post($laundry, TransactionType::Debit, 300, '2026-10-02 06:00:00');
    $post(ChargeCode::system(ChargeCode::PAYMENT), TransactionType::Credit, 1000, '2026-10-02 07:00:00');
    $post(ChargeCode::system(ChargeCode::TRANSFER), TransactionType::Debit, 9999, '2026-10-02 07:00:00');
    $post($minibar, TransactionType::Debit, 700, '2026-10-02 19:00:00');

    cashierPayment($this->property, 'RCPT-1', '2026-10-01 06:00:00', 1500, ['payment_mode' => PaymentMode::UPI]);
    cashierPayment($this->property, 'RCPT-2', '2026-10-02 06:00:00', 2500);
    cashierPayment($this->property, 'RF-1', '2026-10-02 08:00:00', 300, ['type' => 'refund', 'status' => PaymentStatus::Refunded]);
    cashierPayment($this->property, 'RCPT-CXL', '2026-10-02 08:00:00', 999, ['status' => PaymentStatus::Cancelled]);

    $response = $this->get(route('admin.reports.download', [$this->property, 'financial_transaction_summary']).'?date=2026-10-01&end_date=2026-10-02')->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('FinancialTransactionSummary.xlsx');

    $sheet = downloadedWorkbook($response->streamedContent())->getSheet(0);
    $rows = $sheet->rangeToArray('A1:F'.$sheet->getHighestRow(), null, true, false);
    $row = fn (string $label): array => collect($rows)->first(fn (array $cells): bool => $cells[0] === $label) ?? [];

    expect($rows[0][0])->toBe('Financial Transaction Summary for CONCH BHAVAN - 01 Oct, 2026 to 02 Oct, 2026')
        ->and(array_slice($row('Total Charges Posted'), 0, 2))->toBe(['Total Charges Posted', 1300])
        ->and(array_slice($row('Total Credits Applied'), 0, 2))->toBe(['Total Credits Applied', 1000])
        ->and(array_slice($row('Net Collection'), 0, 2))->toBe(['Net Collection', 3700])
        ->and(array_slice($row('Minibar'), 0, 5))->toBe(['Minibar', 'MINI', 'Incidentals', 2, 1000])
        ->and(array_slice($row('Laundry'), 0, 5))->toBe(['Laundry', 'LAUN', 'Incidentals', 1, 300])
        ->and(array_slice($row('Payment Received'), 0, 5))->toBe(['Payment Received', 'PAY', 'Payment', 1, 1000])
        ->and($row('Balance Transfer'))->toBe([])
        ->and($row('Cash'))->toBe(['Cash', 1, 2500, 1, 300, 2200])
        ->and($row('UPI'))->toBe(['UPI', 1, 1500, 0, 0, 1500]);
});
