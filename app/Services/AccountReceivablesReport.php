<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Reports → Account Receivables Report: what each corporate account still owes as of the
 * date — the agency of an agent's bookings, or the guest's company when the room is billed
 * "To Company" — with the outstanding aged by the booking's checkout (its bill date).
 */
class AccountReceivablesReport
{
    public const REPORT = 'account_receivables_report';

    private const EMPTY = '--';

    /** The amount columns, summed on the total row. */
    private const AMOUNT_COLUMNS = [
        'total_amount', 'total_amount_paid', 'total_outstanding_amount',
        'days_0_30_outstanding', 'days_31_60_outstanding', 'days_61_90_outstanding', 'days_90_plus_outstanding',
        'current_month_outstanding', 'previous_month_outstanding', 'two_months_ago_outstanding', 'three_months_and_before_outstanding',
    ];

    public function __construct(private ReportSettings $settings) {}

    /**
     * @return array{title: string, summary: string, headers: list<string>, rows: list<list<mixed>>}
     */
    public function report(Property $property, CarbonImmutable $date, ?CarbonInterface $downloadedAt = null): array
    {
        $settings = $this->settings->get($property)['reports'][self::REPORT];
        $columns = ReportSheet::visibleColumns(self::REPORT, $settings['hidden_columns']);
        $accounts = $this->accounts($property, $date);

        $rows = array_values($accounts->map(fn (array $account): array => array_map(
            fn (string $key): mixed => in_array($key, self::AMOUNT_COLUMNS, true) ? ReportSheet::amount($account[$key]) : $account[$key],
            $columns,
        ))->all());

        if ($rows !== []) {
            $total = array_map(fn (string $key): mixed => in_array($key, self::AMOUNT_COLUMNS, true) ? ReportSheet::amount(round($accounts->sum($key), 2)) : self::EMPTY, $columns);
            $total[0] = 'Total';
            $rows[] = $total;
        }

        return [
            'title' => sprintf('%s for %s - As on %s  Downloaded At %s', $settings['heading'], $property->name, $date->format('M, d Y'), $property->localTime($downloadedAt ?? now())->format('d M, Y H:i')),
            'summary' => sprintf('Account Receivables (%d Accounts, %s Outstanding)', $accounts->count(), number_format((float) $accounts->sum('total_outstanding_amount'), 2)),
            'headers' => array_map(fn (string $key): string => ReportSettings::COLUMNS[self::REPORT][$key], $columns),
            'rows' => $rows,
        ];
    }

    public function spreadsheet(Property $property, CarbonImmutable $date, ?CarbonInterface $downloadedAt = null): Spreadsheet
    {
        return ReportSheet::workbook('Account Receivables', $this->report($property, $date, $downloadedAt));
    }

    public function fileName(Property $property): string
    {
        return Str::studly($this->settings->get($property)['reports'][self::REPORT]['heading']).'.xlsx';
    }

    /**
     * Corporate accounts with something outstanding, by name: their bookings' totals and
     * the outstanding split by age (days since checkout) and by checkout month.
     *
     * @return Collection<int, array{corporate_id: string, corporate_account: string, total_amount: float, total_amount_paid: float, total_outstanding_amount: float, days_0_30_outstanding: float, days_31_60_outstanding: float, days_61_90_outstanding: float, days_90_plus_outstanding: float, current_month_outstanding: float, previous_month_outstanding: float, two_months_ago_outstanding: float, three_months_and_before_outstanding: float}>
     */
    private function accounts(Property $property, CarbonImmutable $date): Collection
    {
        $statuses = array_map(fn ($status): string => $status->value, NightStays::FIRM_BOOKING_STATUSES);

        return Booking::query()
            ->where('property_id', $property->id)
            ->whereIn('status', $statuses)
            ->whereDate('check_in', '<=', $date->toDateString())
            ->where(fn ($query) => $query->whereNotNull('agent_id')->orWhere('room_bill_to', 'to_company'))
            ->with(['agent.agency', 'guests'])
            ->get()
            ->map(fn (Booking $booking): ?array => ($account = $this->accountFor($booking)) ? [...$account, 'booking' => $booking] : null)
            ->filter()
            ->groupBy('key')
            ->map(function (Collection $entries) use ($date): array {
                $first = $entries->first();
                $account = [
                    'corporate_id' => $first['id'],
                    'corporate_account' => $first['name'],
                    ...array_fill_keys(self::AMOUNT_COLUMNS, 0.0),
                ];

                foreach ($entries as $entry) {
                    $booking = $entry['booking'];
                    $outstanding = round(max(0, (float) $booking->outstanding), 2);
                    $billedOn = CarbonImmutable::parse($booking->check_out->toDateString());
                    $age = (int) $billedOn->diffInDays($date, false);
                    $monthsAgo = ($date->year - $billedOn->year) * 12 + $date->month - $billedOn->month;

                    $account['total_amount'] += (float) $booking->total;
                    $account['total_amount_paid'] += max(0, (float) $booking->total - $outstanding);
                    $account['total_outstanding_amount'] += $outstanding;
                    $account[match (true) {
                        $age <= 30 => 'days_0_30_outstanding',
                        $age <= 60 => 'days_31_60_outstanding',
                        $age <= 90 => 'days_61_90_outstanding',
                        default => 'days_90_plus_outstanding',
                    }] += $outstanding;
                    $account[match (true) {
                        $monthsAgo <= 0 => 'current_month_outstanding',
                        $monthsAgo === 1 => 'previous_month_outstanding',
                        $monthsAgo === 2 => 'two_months_ago_outstanding',
                        default => 'three_months_and_before_outstanding',
                    }] += $outstanding;
                }

                return array_map(fn (mixed $value): mixed => is_float($value) ? round($value, 2) : $value, $account);
            })
            ->filter(fn (array $account): bool => $account['total_outstanding_amount'] > 0)
            ->sortBy('corporate_account', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * Who the booking's bill goes to: the guest's company when billed "To Company",
     * otherwise the agent's agency.
     *
     * @return array{key: string, id: string, name: string}|null
     */
    private function accountFor(Booking $booking): ?array
    {
        $company = $booking->guests->first()?->company;

        if ($booking->room_bill_to === 'to_company' && filled($company['name'] ?? null)) {
            return [
                'key' => 'company:'.Str::lower(trim($company['name'])),
                'id' => filled($company['gstin'] ?? null) ? (string) $company['gstin'] : self::EMPTY,
                'name' => trim($company['name']),
            ];
        }

        $agency = $booking->agent?->agency;

        return $agency ? ['key' => 'agency:'.$agency->id, 'id' => (string) ($agency->code ?: self::EMPTY), 'name' => $agency->name] : null;
    }
}
