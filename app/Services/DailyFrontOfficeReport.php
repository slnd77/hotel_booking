<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reports → Daily Reports ("Daily Front Office Report"): the day's arrivals,
 * stay-overs, departures, holds, enquiries and yesterday's no-shows.
 */
class DailyFrontOfficeReport extends BookingListReport
{
    public const REPORT = 'daily_reports';

    private const SECTION_COLOR = '48CA10';

    public function reportKey(): string
    {
        return self::REPORT;
    }

    public function sections(Property $property, CarbonImmutable $date): array
    {
        $day = $date->toDateString();
        $firm = array_map(fn (BookingStatus $status): string => $status->value, self::FIRM_STATUSES);
        $dayStartUtc = CarbonImmutable::parse($day, $property->timezoneName())->startOfDay()->utc();

        $sections = [
            ["Today's Confirm Check-In", fn (Builder $query) => $query->whereDate('check_in', $day)->whereIn('status', $firm)],
            ['Staying Over', fn (Builder $query) => $query->whereDate('check_in', '<', $day)->whereDate('check_out', '>', $day)->whereIn('status', $firm)],
            ["Today's Check-Out", fn (Builder $query) => $query->whereDate('check_out', $day)->whereIn('status', $firm)],
            ['Hold Expiring Today', fn (Builder $query) => $query->where('status', BookingStatus::OnHold->value)
                ->where('hold_release_at', '>=', $dayStartUtc)->where('hold_release_at', '<', $dayStartUtc->addDay())],
            ["Today's Hold Check-In", fn (Builder $query) => $query->whereDate('check_in', $day)->where('status', BookingStatus::OnHold->value)],
            ['Enquiry Check-In Today', fn (Builder $query) => $query->whereDate('check_in', $day)->where('status', BookingStatus::Enquiry->value)],
            ['Yesterday No Show Booking', fn (Builder $query) => $query->whereDate('check_in', $date->subDay()->toDateString())->where('status', BookingStatus::NoShow->value)],
        ];

        return array_map(fn (array $section): array => [
            'title' => $section[0],
            'color' => self::SECTION_COLOR,
            'bookings' => $this->bookings($property, $section[1]),
        ], $sections);
    }

    protected function title(string $heading, Property $property, CarbonImmutable $date, CarbonInterface $downloadedAt): string
    {
        return sprintf('%s for %s - %s  Downloaded At %s', $heading, $property->name, $date->format('M, d Y'), $property->localTime($downloadedAt)->format('d M, Y H:i'));
    }
}
