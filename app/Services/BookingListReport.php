<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Property;
use App\Models\SubBooking;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\PdfBuilder;

use function Spatie\LaravelPdf\Support\pdf;

/**
 * A booking list report for one business date (Daily Front Office, Daily
 * Sales…): a sheet per section, bookings grouped by source, in the columns picked under
 * "Select Columns", then the tax columns. Subclasses say which bookings each section holds.
 */
abstract class BookingListReport
{
    protected const EMPTY = '--';

    /**
     * The download's columns in sheet order: Select Columns key => sheet header.
     *
     * @var array<string, string>
     */
    private const COLUMNS = [
        's_no' => 'S.No.', 'reservation_no' => 'Reservation No.', 'check_in' => 'Check In', 'check_out' => 'Check Out',
        'source_type' => 'Source Type', 'status' => 'Status', 'current_status' => 'Current Status', 'booking_type' => 'Booking Type',
        'reference_no' => 'Reference No.', 'pay_at_hotel' => 'Pay at Hotel', 'rooms' => 'Rooms', 'room_numbers' => 'Room Numbers',
        'pax' => 'No. of Pax', 'meal_plans' => 'Meal Plans', 'total_adults' => 'Total Adults', 'total_children' => 'Total Children',
        'total_infants' => 'Total Infants', 'guest_name' => 'Guest Name', 'guest_email' => 'Guest E-mail Id', 'guest_contact' => 'Guest Contact No.',
        'guest_nationality' => 'Guest Nationality', 'agency_name' => 'Agency Name', 'agent_name' => 'Agent Name', 'agent_email' => 'Agent E-mail Id',
        'agent_contact' => 'Agent Contact No.', 'net_tariff_per_night' => 'Net Price Per Night', 'net_tariff' => 'Net Tariff', 'discount_amount' => 'Discount Amount',
        'total_taxes' => 'Total Taxes', 'gross_tariff_per_night' => 'Gross Price Per Night', 'gross_tariff' => 'Gross Tariff', 'add_on_tariff' => 'Add On Tariff',
        'outlet_tariff' => 'Outlet Tariff', 'advance_amount' => 'Advance Amount', 'net_payable' => 'Net Payable', 'created_on' => 'Created On',
        'created_by' => 'Created By', 'last_modified_on' => 'Last Modified On', 'last_modified_by' => 'Last Modified By', 'sales_executive' => 'Sales Executive',
        'payment_notes' => 'Payment Notes', 'special_instruction' => 'Special Instruction', 'internal_notes' => 'Internal Notes', 'loyalty_card_type' => 'Loyalty Card Type',
    ];

    /** Booking statuses that count as a firm booking. */
    protected const FIRM_STATUSES = [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::CheckedOut];

    public function __construct(protected ReportSettings $settings, protected BookingPrints $prints) {}

    /**
     * The report's key in ReportSettings::REPORTS.
     */
    abstract public function reportKey(): string;

    /**
     * The report's sections for the business date: title, bar colour and bookings.
     *
     * @return list<array{title: string, color: string, bookings: Collection<int, Booking>}>
     */
    abstract public function sections(Property $property, CarbonImmutable $date): array;

    /**
     * The line above every section, from the report's heading (Edit on the Reports page).
     */
    abstract protected function title(string $heading, Property $property, CarbonImmutable $date, CarbonInterface $downloadedAt): string;

    /**
     * Everything both downloads show: the title, the headers (only the columns not hidden
     * under Select Columns, then the tax columns) and each section's rows by source.
     *
     * @return array{title: string, headers: list<string>, sections: list<array{title: string, color: string, summary: string, groups: array<string, list<list<mixed>>>}>}
     */
    public function report(Property $property, CarbonImmutable $date, ?CarbonInterface $downloadedAt = null): array
    {
        $settings = $this->settings->get($property)['reports'][$this->reportKey()];
        $columns = array_keys(array_diff_key(self::COLUMNS, array_flip($settings['hidden_columns'])));
        $taxNames = $this->prints->taxComponentNames($property);
        $sections = [];

        foreach ($this->sections($property, $date) as $section) {
            $roomCount = $section['bookings']->sum(fn (Booking $booking): int => $booking->rooms->count());
            $groups = [];

            foreach ($section['bookings']->groupBy(fn (Booking $booking): string => $this->sourceLabel($booking)) as $source => $bookings) {
                $groups[$source] = array_values($bookings->values()->map(function (Booking $booking, int $index) use ($property, $columns, $taxNames): array {
                    $values = $this->row($property, $booking, $index + 1);
                    $taxes = $this->prints->taxByComponent($booking, $taxNames);

                    return [
                        ...array_map(fn (string $key): mixed => $values[$key], $columns),
                        self::EMPTY,
                        ...array_map(fn (string $name): mixed => $this->amount($taxes[$name] ?? 0), $taxNames),
                    ];
                })->all());
            }

            $sections[] = [
                'title' => $section['title'],
                'color' => $section['color'],
                'summary' => "{$section['title']} ({$section['bookings']->count()} Bookings, {$roomCount} Rooms)",
                'groups' => $groups,
            ];
        }

        return [
            'title' => $this->title($settings['heading'], $property, $date, $downloadedAt ?? now()),
            'headers' => [...array_map(fn (string $key): string => self::COLUMNS[$key], $columns), 'Default Tax Choice', ...$taxNames],
            'sections' => $sections,
        ];
    }

    /**
     * The workbook: a sheet per section.
     */
    public function spreadsheet(Property $property, CarbonImmutable $date, ?CarbonInterface $downloadedAt = null): Spreadsheet
    {
        $report = $this->report($property, $date, $downloadedAt);
        $headers = $report['headers'];
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));

        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        foreach ($report['sections'] as $section) {
            $sheet = $spreadsheet->createSheet();
            // Sheet titles can't contain these and are capped at 31 characters.
            $sheet->setTitle(Str::limit(str_replace(['\\', '/', '?', '*', '[', ']', ':'], '', $section['title']), 31, ''));
            $sheet->getRowDimension(1)->setRowHeight(50);

            $sheet->setCellValue('A2', $report['title'])->mergeCells("A2:{$lastColumn}2");
            $sheet->getStyle('A2')->getFont()->setSize(15);

            $sheet->setCellValue('A3', $section['summary'])->mergeCells("A3:{$lastColumn}3");
            $sheet->getStyle('A3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($section['color']);

            if ($section['groups'] === []) {
                continue;
            }

            $sheet->fromArray($headers, null, 'A4');
            $sheet->getStyle("A4:{$lastColumn}4")->getFont()->setBold(true)->getColor()->setRGB('0000FF');
            $sheet->getStyle("A4:{$lastColumn}4")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $row = 5;

            foreach ($section['groups'] as $source => $rows) {
                $sheet->setCellValue("A{$row}", $source)->mergeCells("A{$row}:{$lastColumn}{$row}");
                $sheet->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E3E3E3');
                $row++;

                foreach ($rows as $cells) {
                    $sheet->fromArray($cells, null, "A{$row}", true);
                    $row++;
                }
            }

            $sheet->getStyle("A5:{$lastColumn}".($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
            $this->groupRowsLeftAligned($sheet, 5, $row - 1);

            foreach (range(1, count($headers)) as $columnIndex) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * The same report as a landscape A3 PDF: one table per section.
     */
    public function pdf(Property $property, CarbonImmutable $date, ?CarbonInterface $downloadedAt = null): PdfBuilder
    {
        return pdf()
            ->view('prints.reports.booking-list', $this->report($property, $date, $downloadedAt))
            ->format(Format::A3)
            ->landscape()
            ->margins(6, 6, 6, 6, 'mm')
            ->name($this->fileName($property, 'pdf'));
    }

    /**
     * "Daily Front Office Report" → "DailyFrontOfficeReport.xlsx", the file name for the report.
     */
    public function fileName(Property $property, string $extension = 'xlsx'): string
    {
        return Str::studly($this->settings->get($property)['reports'][$this->reportKey()]['heading']).'.'.$extension;
    }

    /**
     * One booking's cells, keyed like COLUMNS.
     *
     * @return array<string, mixed>
     */
    public function row(Property $property, Booking $booking, int $serial): array
    {
        $guest = $this->prints->guest($booking->guests->first());
        $rooms = $booking->rooms;
        $nights = max(1, BookingPricing::nights($booking->check_in->toDateString(), $booking->check_out->toDateString()));
        $net = round((float) $booking->room_total - (float) $booking->discount, 2);
        $gross = round($net + (float) $booking->taxes, 2);
        $outstanding = round((float) $booking->outstanding, 2);
        $paymentNotes = $booking->payments->pluck('notes')->filter(fn (?string $notes): bool => filled($notes))->implode("\n\n");

        return [
            's_no' => $serial,
            'reservation_no' => $booking->booking_ref_id,
            'check_in' => $booking->check_in->format('d M, Y'),
            'check_out' => $booking->check_out->format('d M, Y'),
            'source_type' => $this->sourceLabel($booking),
            'status' => $this->statusLabel($booking),
            'current_status' => $this->currentStatus($rooms),
            'booking_type' => $booking->booking_type ?: 'Others',
            'reference_no' => self::EMPTY,
            'pay_at_hotel' => $booking->pay_at_hotel ? 'Yes' : self::EMPTY,
            'rooms' => $this->countedList($rooms->map(fn (SubBooking $room): string => $room->roomCategory->name ?? 'Room')),
            'room_numbers' => $rooms->map(fn (SubBooking $room): ?string => $room->room?->name)->filter()->implode(',') ?: self::EMPTY,
            'pax' => $this->amount($rooms->sum(fn (SubBooking $room): int => (int) $room->adults + (int) $room->children + (int) $room->infants)),
            'meal_plans' => $this->countedList($rooms->map(fn (SubBooking $room): string => $room->meal_plan ?: 'EP')),
            'total_adults' => $this->amount($rooms->sum('adults')),
            'total_children' => $this->amount($rooms->sum('children')),
            'total_infants' => $this->amount($rooms->sum('infants')),
            'guest_name' => $guest['name'] ?? self::EMPTY,
            'guest_email' => $guest['email'] ?? self::EMPTY,
            'guest_contact' => $guest['phone'] ?? self::EMPTY,
            'guest_nationality' => $booking->guests->first()?->nationality ?: self::EMPTY,
            'agency_name' => $booking->agent?->agency->name ?? self::EMPTY,
            'agent_name' => $booking->agent->contact_name ?? self::EMPTY,
            'agent_email' => $booking->agent?->emails[0] ?? self::EMPTY,
            'agent_contact' => $booking->agent?->phone ?: self::EMPTY,
            'net_tariff_per_night' => $this->amount(round($net / $nights, 2)),
            'net_tariff' => $this->amount($net),
            'discount_amount' => $this->amount((float) $booking->discount),
            'total_taxes' => $this->amount((float) $booking->taxes),
            'gross_tariff_per_night' => $this->amount(round($gross / $nights, 2)),
            'gross_tariff' => $this->amount($gross),
            'add_on_tariff' => self::EMPTY,
            'outlet_tariff' => self::EMPTY,
            'advance_amount' => $this->amount(round(max(0, $gross - $outstanding), 2)),
            'net_payable' => $this->amount($outstanding),
            'created_on' => $booking->created_at ? $property->localTime($booking->created_at)->format('d M, Y') : self::EMPTY,
            'created_by' => $booking->createdBy->name ?? self::EMPTY,
            'last_modified_on' => $booking->updated_at ? $property->localTime($booking->updated_at)->format('d M, Y') : self::EMPTY,
            'last_modified_by' => $booking->updatedBy->name ?? self::EMPTY,
            'sales_executive' => self::EMPTY,
            'payment_notes' => $paymentNotes !== '' ? $paymentNotes : self::EMPTY,
            'special_instruction' => filled($booking->special_instructions) ? $booking->special_instructions : self::EMPTY,
            'internal_notes' => filled($booking->internal_notes) ? $booking->internal_notes : self::EMPTY,
            'loyalty_card_type' => self::EMPTY,
        ];
    }

    /**
     * @param  callable(Builder<Booking>): mixed  $scope
     * @return Collection<int, Booking>
     */
    protected function bookings(Property $property, callable $scope): Collection
    {
        $query = Booking::query()
            ->where('property_id', $property->id)
            ->with(['property', 'rooms.roomCategory', 'rooms.room', 'guests', 'payments', 'agent.agency', 'createdBy:id,name', 'updatedBy:id,name'])
            ->orderBy('booking_ref_id');

        $scope($query);

        return $query->get();
    }

    private function sourceLabel(Booking $booking): string
    {
        return Booking::SOURCES[$booking->source] ?? Str::headline((string) $booking->source ?: 'Direct');
    }

    /**
     * Checked in / out is the stay's state (Current Status), so the booking itself reads Confirmed.
     */
    private function statusLabel(Booking $booking): string
    {
        return match ($booking->status) {
            BookingStatus::CheckedIn, BookingStatus::CheckedOut => BookingStatus::Confirmed->label(),
            BookingStatus::OnHold => 'On-hold',
            default => $booking->status->label(),
        };
    }

    /**
     * @param  Collection<int, SubBooking>  $rooms
     */
    private function currentStatus(Collection $rooms): string
    {
        return match (true) {
            $rooms->isNotEmpty() && $rooms->every(fn (SubBooking $room): bool => $room->checked_out_at !== null) => 'Checked Out',
            $rooms->contains(fn (SubBooking $room): bool => $room->checked_in_at !== null) => 'Checked In',
            default => 'No Stay',
        };
    }

    /**
     * ["Deluxe", "Deluxe", "Suite"] → "Deluxe X 2, Suite X 1".
     *
     * @template TName of string
     *
     * @param  Collection<int, TName>  $names
     */
    private function countedList(Collection $names): string
    {
        return $names->countBy()->map(fn (int $count, string $name): string => "{$name} X {$count}")->implode(', ') ?: self::EMPTY;
    }

    /**
     * Numbers stay numeric for Excel; zero shows as "--".
     */
    private function amount(int|float $value): int|float|string
    {
        if (round((float) $value, 2) == 0.0) {
            return self::EMPTY;
        }

        return floor((float) $value) == (float) $value ? (int) $value : round((float) $value, 2);
    }

    /**
     * The source group rows ("Direct", "Agent") are left-aligned labels across the sheet.
     */
    private function groupRowsLeftAligned(Worksheet $sheet, int $fromRow, int $toRow): void
    {
        foreach (range($fromRow, $toRow) as $row) {
            if ($sheet->getCell("A{$row}")->isMergeRangeValueCell()) {
                $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            }
        }
    }
}
