<?php

namespace App\Services;

use App\Enums\SubBookingStatus;
use App\Models\City;
use App\Models\Country;
use App\Models\Property;
use App\Models\SubBooking;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\PdfBuilder;

use function Spatie\LaravelPdf\Support\pdf;

/**
 * Reports → Occupied Rooms Reports: every room sold for the night of the business date
 * (a firm booking whose stay covers that night), with its guest, stay and that night's
 * price, in the columns picked under "Select Columns".
 */
class OccupiedRoomsReport
{
    public const REPORT = 'occupied_rooms_reports';

    private const EMPTY = '--';

    private const SECTION_COLOR = '48CA10';

    public function __construct(private ReportSettings $settings, private Housekeeping $housekeeping, private NightStays $nightStays) {}

    /**
     * The title, the headers (the columns not hidden under Select Columns) and a row per
     * room, in the booking-list PDF's shape (one section, one unlabelled group).
     *
     * @return array{title: string, headers: list<string>, sections: list<array{title: string, color: string, summary: string, groups: array<string, list<list<mixed>>>}>}
     */
    public function report(Property $property, CarbonImmutable $date, ?CarbonInterface $downloadedAt = null): array
    {
        $settings = $this->settings->get($property)['reports'][self::REPORT];
        $catalogue = ReportSettings::COLUMNS[self::REPORT];
        $columns = array_keys(array_diff_key($catalogue, array_flip($settings['hidden_columns'])));
        $stays = $this->nightStays->soldFor($property, $date);
        $locations = $this->locationNames($stays);
        $housekeepingStatus = $this->housekeeping->statuses($property)->first()['name'] ?? self::EMPTY;

        $rows = $stays->map(function (SubBooking $stay) use ($property, $columns, $locations, $housekeepingStatus): array {
            $values = $this->row($property, $stay, $locations, $housekeepingStatus);

            return array_map(fn (string $key): mixed => $values[$key], $columns);
        })->all();

        return [
            'title' => sprintf('%s for %s - %s  Downloaded At %s', $settings['heading'], $property->name, $date->format('M, d Y'), $property->localTime($downloadedAt ?? now())->format('d M, Y H:i')),
            'headers' => array_map(fn (string $key): string => $catalogue[$key], $columns),
            'sections' => [[
                'title' => 'Occupied Rooms',
                'color' => self::SECTION_COLOR,
                'summary' => sprintf('Occupied Rooms (%d Rooms, %d Bookings)', $stays->count(), $stays->pluck('booking_id')->unique()->count()),
                'groups' => $rows === [] ? [] : ['' => $rows],
            ]],
        ];
    }

    public function spreadsheet(Property $property, CarbonImmutable $date, ?CarbonInterface $downloadedAt = null): Spreadsheet
    {
        $report = $this->report($property, $date, $downloadedAt);
        $section = $report['sections'][0];

        return ReportSheet::workbook($section['title'], [
            'title' => $report['title'],
            'summary' => $section['summary'],
            'headers' => $report['headers'],
            'rows' => $section['groups'][''] ?? [],
        ]);
    }

    /**
     * The same report as a landscape A3 PDF.
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

    public function fileName(Property $property, string $extension = 'xlsx'): string
    {
        return Str::studly($this->settings->get($property)['reports'][self::REPORT]['heading']).'.'.$extension;
    }

    /**
     * One room's cells, keyed like ReportSettings::COLUMNS. Prices are for the one night:
     * the room's rate, its share of the booking's discount and taxes, and the total.
     *
     * @param  array{cities: Collection<int, string>, countries: Collection<int, string>}  $locations
     * @return array<string, mixed>
     */
    private function row(Property $property, SubBooking $stay, array $locations, string $housekeepingStatus): array
    {
        $booking = $stay->booking;
        $guest = $booking->guests->first();
        $email = collect(is_array($guest?->emails) ? $guest->emails : [])
            ->map(fn ($entry) => is_array($entry) ? ($entry['email'] ?? null) : $entry)
            ->first(fn ($entry): bool => filled($entry));
        $address = is_array($guest?->address) ? $guest->address : [];

        $price = $this->nightStays->nightPrice($stay);

        return [
            'room_no' => $stay->room?->name ?? self::EMPTY,
            'room_category' => $stay->roomCategory?->name ?? self::EMPTY,
            'guest_name' => $guest ? trim(($guest->title ? $guest->title.' ' : '').$guest->first_name.' '.$guest->last_name) : self::EMPTY,
            'folio_number' => $stay->folio?->id ?? self::EMPTY,
            'room_status' => $stay->room ? ($stay->room->housekeeping_status ?: $housekeepingStatus) : self::EMPTY,
            'arrival_date' => $stay->check_in_date->format('d M, Y'),
            'departure_date' => $stay->check_out_date->format('d M, Y'),
            'total_adults' => $this->amount((int) $stay->adults),
            'total_children' => $this->amount((int) $stay->children),
            'total_infants' => $this->amount((int) $stay->infants),
            'meal_plan' => $stay->meal_plan ?: 'EP',
            'rate_plan' => filled($booking->rate_plan) ? $booking->rate_plan : self::EMPTY,
            'room_cost' => $this->amount($price['rate']),
            // Meal plans aren't priced apart from the room rate.
            'meal_cost' => self::EMPTY,
            'discount' => $this->amount($price['discount']),
            'total_tax' => $this->amount($price['tax']),
            'total_price' => $this->amount($price['gross']),
            'city' => $locations['cities'][$address['city_id'] ?? 0] ?? self::EMPTY,
            'country' => $locations['countries'][$address['country_id'] ?? 0] ?? self::EMPTY,
            'nationality' => $guest?->nationality ?: self::EMPTY,
            'agency_name' => $booking->agent?->agency?->name ?? self::EMPTY,
            'guest_email_id' => $email ?? self::EMPTY,
            'guest_phone_no' => filled($guest?->phone) ? trim(($guest->phone_country_code ?? '').' '.$guest->phone) : self::EMPTY,
            'current_status' => match ($stay->status) {
                SubBookingStatus::CheckedIn => 'Checked In',
                SubBookingStatus::CheckedOut => 'Checked Out',
                default => 'No Stay',
            },
            'check_in_time' => $stay->checked_in_at ? $property->localTime($stay->checked_in_at)->format('d M, Y H:i') : self::EMPTY,
            'check_out_time' => $stay->checked_out_at ? $property->localTime($stay->checked_out_at)->format('d M, Y H:i') : self::EMPTY,
            'internal_notes' => filled($booking->internal_notes) ? $booking->internal_notes : self::EMPTY,
            'special_instructions' => filled($booking->special_instructions) ? $booking->special_instructions : self::EMPTY,
        ];
    }

    /**
     * The guests' city and country names, looked up once for the whole report.
     *
     * @param  Collection<int, SubBooking>  $stays
     * @return array{cities: Collection<int, string>, countries: Collection<int, string>}
     */
    private function locationNames(Collection $stays): array
    {
        $addresses = $stays->map(fn (SubBooking $stay): array => is_array($stay->booking->guests->first()?->address) ? $stay->booking->guests->first()->address : []);

        return [
            'cities' => City::query()->whereIn('id', $addresses->pluck('city_id')->filter()->unique())->pluck('name', 'id'),
            'countries' => Country::query()->whereIn('id', $addresses->pluck('country_id')->filter()->unique())->pluck('name', 'id'),
        ];
    }

    private function amount(int|float $value): int|float|string
    {
        return ReportSheet::amount($value);
    }
}
