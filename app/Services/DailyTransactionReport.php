<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Property;
use App\Models\SubBooking;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Reports → Daily Transaction Report: the room revenue the business date's night posts —
 * a row per room sold for that night with its rate, its share of the booking's discount
 * and taxes, and the net and gross cost.
 */
class DailyTransactionReport
{
    public const REPORT = 'daily_transaction_report';

    private const EMPTY = '--';

    public function __construct(private ReportSettings $settings, private NightStays $nightStays, private BookingPrints $prints) {}

    /**
     * @return array{title: string, summary: string, headers: list<string>, rows: list<list<mixed>>}
     */
    public function report(Property $property, CarbonImmutable $date, ?CarbonInterface $downloadedAt = null): array
    {
        $settings = $this->settings->get($property)['reports'][self::REPORT];
        $columns = ReportSheet::visibleColumns(self::REPORT, $settings['hidden_columns']);
        $stays = $this->nightStays->soldFor($property, $date);

        $rows = $stays->map(function (SubBooking $stay) use ($columns, $date): array {
            $values = $this->row($stay, $date);

            return array_map(fn (string $key): mixed => $values[$key], $columns);
        })->all();

        return [
            'title' => sprintf('%s for %s - %s  Downloaded At %s', $settings['heading'], $property->name, $date->format('M, d Y'), $property->localTime($downloadedAt ?? now())->format('d M, Y H:i')),
            'summary' => sprintf('Daily Transaction (%d Rooms, %d Bookings)', $stays->count(), $stays->pluck('booking_id')->unique()->count()),
            'headers' => array_map(fn (string $key): string => ReportSettings::COLUMNS[self::REPORT][$key], $columns),
            'rows' => $rows,
        ];
    }

    public function spreadsheet(Property $property, CarbonImmutable $date, ?CarbonInterface $downloadedAt = null): Spreadsheet
    {
        return ReportSheet::workbook('Daily Transaction', $this->report($property, $date, $downloadedAt));
    }

    public function fileName(Property $property): string
    {
        return Str::studly($this->settings->get($property)['reports'][self::REPORT]['heading']).'.xlsx';
    }

    /**
     * One room's night, keyed like ReportSettings::COLUMNS. Meal plans aren't priced apart
     * from the room rate, so the package is the room-only cost.
     *
     * @return array<string, mixed>
     */
    private function row(SubBooking $stay, CarbonImmutable $date): array
    {
        $booking = $stay->booking;
        $guest = $booking->guests->first();
        $price = $this->nightStays->nightPrice($stay);

        return [
            'folio_no' => $stay->folio?->id ?? self::EMPTY,
            'room_no' => $stay->room?->name ?? self::EMPTY,
            'guest_name' => $this->prints->guest($guest)['name'] ?? self::EMPTY,
            'pax' => ReportSheet::amount((int) $stay->adults + (int) $stay->children + (int) $stay->infants),
            'room_nights' => 1,
            'package_type' => $stay->meal_plan ?: 'EP',
            'type_of_reservation' => Booking::SOURCES[$booking->source] ?? Str::headline((string) $booking->source ?: 'Direct'),
            'company' => $booking->agent?->agency?->name ?? (filled($guest?->company['name'] ?? null) ? $guest->company['name'] : self::EMPTY),
            'room_rate' => ReportSheet::amount($price['rate']),
            'adjusted_room_rate' => ReportSheet::amount($price['net']),
            'room_only_cost_gross' => ReportSheet::amount($price['gross']),
            'meal_plan_cost_gross' => self::EMPTY,
            'room_only_cost_net' => ReportSheet::amount($price['net']),
            'meal_plan_cost_net' => self::EMPTY,
            'package_net' => ReportSheet::amount($price['net']),
            'package_gross' => ReportSheet::amount($price['gross']),
            'date_of_transaction' => $date->format('d M, Y'),
        ];
    }
}
