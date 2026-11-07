<?php

namespace App\Services;

use App\Models\Property;
use App\Models\SubBooking;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\PdfBuilder;

use function Spatie\LaravelPdf\Support\pdf;

/**
 * Reports → Nationality Revenue Report: the room revenue of the nights sold in the date
 * range, by the nationality of the booking's guest — bookings, room nights, guests, net
 * revenue, tax and gross revenue, and each nationality's share — with a total row.
 */
class NationalityRevenueReport
{
    public const REPORT = 'nationality_revenue_report';

    /** Longest range one download covers. */
    public const MAX_DAYS = 92;

    private const NOT_SPECIFIED = 'Not Specified';

    private const HEADERS = ['Nationality', 'No. of Bookings', 'Room Nights', 'No. of Guests', 'Room Revenue (Net)', 'Tax', 'Total Revenue (Gross)', 'Revenue Share (%)'];

    public function __construct(private ReportSettings $settings, private NightStays $nightStays) {}

    /**
     * @return array{title: string, summary: string, headers: list<string>, rows: list<list<mixed>>}
     */
    public function report(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $nights = $this->nights($property, $from, $to);
        $totalGross = (float) $nights->sum('gross');

        $rows = $nights
            ->groupBy('nationality')
            ->map(fn (Collection $group, string $nationality): array => [
                'nationality' => $nationality,
                'bookings' => $group->pluck('booking_id')->unique()->count(),
                'room_nights' => $group->count(),
                'guests' => (int) $group->unique('stay_id')->sum('guests'),
                'net' => round((float) $group->sum('net'), 2),
                'tax' => round((float) $group->sum('tax'), 2),
                'gross' => round((float) $group->sum('gross'), 2),
            ])
            ->sortByDesc('gross')
            ->map(fn (array $row): array => [...array_values($row), $this->share($row['gross'], $totalGross)])
            ->values()
            ->all();

        if ($rows !== []) {
            $rows[] = [
                'Total', $nights->pluck('booking_id')->unique()->count(), $nights->count(), (int) $nights->unique('stay_id')->sum('guests'),
                round((float) $nights->sum('net'), 2), round((float) $nights->sum('tax'), 2), round($totalGross, 2), 100,
            ];
        }

        return [
            'title' => sprintf('%s for %s - %s to %s', $this->settings->get($property)['reports'][self::REPORT]['heading'], $property->name, $from->format('d M, Y'), $to->format('d M, Y')),
            'summary' => sprintf('Nationality Revenue (%d Nationalities, %d Room Nights)', max(0, count($rows) - 1), $nights->count()),
            'headers' => self::HEADERS,
            'rows' => array_map(fn (array $row): array => array_map(fn (mixed $value): mixed => is_float($value) && floor($value) == $value ? (int) $value : $value, $row), $rows),
        ];
    }

    public function spreadsheet(Property $property, CarbonImmutable $from, CarbonImmutable $to): Spreadsheet
    {
        return ReportSheet::workbook('Nationality Revenue', $this->report($property, $from, $to));
    }

    /**
     * The same table as a landscape A4 PDF.
     */
    public function pdf(Property $property, CarbonImmutable $from, CarbonImmutable $to): PdfBuilder
    {
        $report = $this->report($property, $from, $to);

        return pdf()
            ->view('prints.reports.tables', [
                'title' => $report['title'],
                'sheets' => [['title' => $report['summary'], 'headers' => $report['headers'], 'rows' => $report['rows']]],
            ])
            ->format(Format::A4)
            ->landscape()
            ->margins(6, 6, 6, 6, 'mm')
            ->name($this->fileName($property, 'pdf'));
    }

    public function fileName(Property $property, string $extension = 'xlsx'): string
    {
        return Str::studly($this->settings->get($property)['reports'][self::REPORT]['heading']).'.'.$extension;
    }

    /**
     * Each room night sold in the range with its guest's nationality and its price.
     *
     * @return Collection<int, array{booking_id: int, stay_id: int, nationality: string, guests: int, net: float, tax: float, gross: float}>
     */
    private function nights(Property $property, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $nights = collect();

        foreach (CarbonPeriod::create($from, $to) as $night) {
            foreach ($this->nightStays->soldFor($property, CarbonImmutable::parse($night)) as $stay) {
                $price = $this->nightStays->nightPrice($stay);

                $nights->push([
                    'booking_id' => $stay->booking_id,
                    'stay_id' => $stay->id,
                    'nationality' => $this->nationality($stay),
                    'guests' => (int) $stay->adults + (int) $stay->children + (int) $stay->infants,
                    'net' => $price['net'],
                    'tax' => $price['tax'],
                    'gross' => $price['gross'],
                ]);
            }
        }

        return $nights;
    }

    private function nationality(SubBooking $stay): string
    {
        $nationality = trim((string) $stay->booking->guests->first()?->nationality);

        return $nationality !== '' ? Str::title($nationality) : self::NOT_SPECIFIED;
    }

    private function share(float $amount, float $total): float
    {
        return $total > 0 ? round($amount / $total * 100, 2) : 0.0;
    }
}
