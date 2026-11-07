<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reports → Daily Sales Reports ("Daily Sales Report"): what the front desk sold
 * and changed on the business date (the property's local day): new confirmations and
 * holds, bookings modified that day, cancellations and new enquiries.
 */
class DailySalesReport extends BookingListReport
{
    public const REPORT = 'daily_sales_reports';

    public function reportKey(): string
    {
        return self::REPORT;
    }

    public function sections(Property $property, CarbonImmutable $date): array
    {
        $dayStart = CarbonImmutable::parse($date->toDateString(), $property->timezoneName())->startOfDay()->utc();
        $dayEnd = $dayStart->addDay();
        $firm = array_map(fn (BookingStatus $status): string => $status->value, self::FIRM_STATUSES);
        $createdToday = fn (Builder $query) => $query->where('created_at', '>=', $dayStart)->where('created_at', '<', $dayEnd);
        $changedToday = fn (Builder $query) => $query->where('updated_at', '>=', $dayStart)->where('updated_at', '<', $dayEnd);

        $sections = [
            ['New Confirmed Booking', '669966', fn (Builder $query) => $createdToday($query)->whereIn('status', $firm)],
            ['New Room Holdings', 'FF3D03', fn (Builder $query) => $createdToday($query)->where('status', BookingStatus::OnHold->value)],
            // Changed today but made before today (today's new bookings are listed above).
            ['Modified Confirmation & Hold Rooms', 'EDB61C', fn (Builder $query) => $changedToday($query)
                ->where('created_at', '<', $dayStart)
                ->whereIn('status', [...$firm, BookingStatus::OnHold->value])],
            // A cancelled booking isn't changed again, so one last changed today was cancelled today.
            ['Rooms Cancelled', '2E2800', fn (Builder $query) => $changedToday($query)->where('status', BookingStatus::Cancelled->value)],
            ['Enquiry Created Today', '00ACEB', fn (Builder $query) => $createdToday($query)->where('status', BookingStatus::Enquiry->value)],
        ];

        return array_map(fn (array $section): array => [
            'title' => $section[0],
            'color' => $section[1],
            'bookings' => $this->bookings($property, $section[2]),
        ], $sections);
    }

    protected function title(string $heading, Property $property, CarbonImmutable $date, CarbonInterface $downloadedAt): string
    {
        return sprintf('%s for %s - %s', $heading, $property->name, $date->format('M, d Y'));
    }
}
