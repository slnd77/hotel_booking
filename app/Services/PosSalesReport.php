<?php

namespace App\Services;

use App\Enums\OutletBillStatus;
use App\Enums\PaymentStatus;
use App\Models\Currency;
use App\Models\OutletBill;
use App\Models\OutletBillItem;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Reports → POS Sales Report: the outlets' bills over a range of business dates — sales by
 * outlet, how they were settled (charged to rooms, or paid by mode), item-wise sales, every
 * bill, and the voids. Open and void bills aren't sales; they're counted apart.
 */
class PosSalesReport
{
    public const REPORT = 'pos_sales_report';

    /** Longest range one download covers. */
    public const MAX_DAYS = 92;

    public function __construct(private ReportSettings $settings) {}

    /**
     * The figures, for the POS reports page, the API, the night audit and the Excel file.
     *
     * @return array{currency: string, from: string, to: string, totals: array<string, int|float>, outlets: list<array<string, mixed>>, settlements: list<array{label: string, count: int, amount: float}>, items: list<array<string, mixed>>, bills: list<array<string, mixed>>}
     */
    public function summary(Property $property, CarbonImmutable $from, CarbonImmutable $to, ?int $outletId = null): array
    {
        $bills = OutletBill::query()
            ->where('property_id', $property->id)
            ->whereDate('business_date', '>=', $from->toDateString())
            ->whereDate('business_date', '<=', $to->toDateString())
            ->when($outletId, fn ($query, int $id) => $query->where('outlet_id', $id))
            ->with(['outlet:id,name', 'items', 'payment', 'booking:id,booking_ref_id', 'subBooking.inventoryUnit:id,name'])
            ->orderBy('business_date')
            ->orderBy('id')
            ->get();

        $sales = $bills->whereIn('status', [OutletBillStatus::Posted, OutletBillStatus::Settled]);
        $payments = $sales->pluck('payment')->filter()->where('status', PaymentStatus::Received);
        $posted = $sales->where('status', OutletBillStatus::Posted);
        $toRoom = round($posted->sum(fn (OutletBill $bill): float => (float) $bill->total - (float) ($bill->payment?->status === PaymentStatus::Received ? $bill->payment->total_txn_amount : 0)), 2);

        return [
            'currency' => Currency::forProperty($property)['code'],
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => [
                ...$this->totals($sales),
                'charged_to_rooms' => $toRoom,
                'collected' => round((float) $payments->sum('total_txn_amount'), 2),
                'open_bills' => $bills->where('status', OutletBillStatus::Open)->count(),
                'open_amount' => round((float) $bills->where('status', OutletBillStatus::Open)->sum('total'), 2),
                'void_bills' => $bills->where('status', OutletBillStatus::Void)->count(),
                'void_amount' => round((float) $bills->where('status', OutletBillStatus::Void)->sum('total'), 2),
            ],
            'outlets' => array_values($bills->groupBy('outlet_id')->map(fn (Collection $outletBills): array => [
                'outlet' => $outletBills->first()->outlet->name,
                ...$this->totals($outletBills->whereIn('status', [OutletBillStatus::Posted, OutletBillStatus::Settled])),
                'void_bills' => $outletBills->where('status', OutletBillStatus::Void)->count(),
            ])->sortBy('outlet')->all()),
            'settlements' => [
                ['label' => 'Charged to rooms', 'count' => $posted->count(), 'amount' => $toRoom],
                ...$payments->groupBy(fn ($payment): string => $payment->payment_mode->value ?? 'Others')
                    ->map(fn (Collection $group, string $mode): array => ['label' => $mode, 'count' => $group->count(), 'amount' => round((float) $group->sum('total_txn_amount'), 2)])
                    ->sortKeys()
                    ->values()
                    ->all(),
            ],
            'items' => array_values($sales->flatMap(fn (OutletBill $bill): Collection => $bill->items->map(fn (OutletBillItem $line): array => [
                'outlet' => $bill->outlet->name,
                'name' => $line->name,
                'quantity' => $line->quantity,
                'amount' => (float) $line->amount,
            ]))
                ->groupBy(fn (array $line): string => "{$line['outlet']}|{$line['name']}")
                ->map(fn (Collection $lines): array => [
                    'outlet' => $lines->first()['outlet'],
                    'name' => $lines->first()['name'],
                    'quantity' => (int) $lines->sum('quantity'),
                    'amount' => round((float) $lines->sum('amount'), 2),
                ])
                ->sortByDesc('amount')
                ->all()),
            'bills' => array_values($bills->map(fn (OutletBill $bill): array => [
                'id' => $bill->id,
                'business_date' => $bill->business_date->toDateString(),
                'outlet' => $bill->outlet->name,
                'bill_number' => $bill->bill_number,
                'status' => $bill->status->value,
                'status_label' => $bill->status->label(),
                'guest_name' => $bill->guest_name,
                'room' => $bill->subBooking?->inventoryUnit?->name,
                'booking_ref_id' => $bill->booking?->booking_ref_id,
                'sub_total' => (float) $bill->sub_total,
                'discount' => (float) $bill->discount,
                'tax_total' => (float) $bill->tax_total,
                'total' => (float) $bill->total,
                'payment_mode' => $bill->payment?->payment_mode?->value,
                'void_reason' => $bill->void_reason,
            ])->all()),
        ];
    }

    /**
     * @return array{title: string, tables: list<array{title: string, headers: list<string>, rows: list<list<mixed>>}>}
     */
    public function report(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $summary = $this->summary($property, $from, $to);
        $totals = $summary['totals'];

        return [
            'title' => sprintf('%s for %s - %s to %s', $this->settings->get($property)['reports'][self::REPORT]['heading'], $property->name, $from->format('d M, Y'), $to->format('d M, Y')),
            'tables' => [
                [
                    'title' => "Summary ({$summary['currency']})",
                    'headers' => ['Description', 'Amount'],
                    'rows' => [
                        ['Bills', $totals['bills']],
                        ['Gross Sales', $totals['sub_total']],
                        ['Discount', $totals['discount']],
                        ['Net Sales', $totals['net_sales']],
                        ['Tax', $totals['tax_total']],
                        ['Total', $totals['total']],
                        ['Charged to Rooms', $totals['charged_to_rooms']],
                        ['Collected at Outlets', $totals['collected']],
                        ['Open Bills', $totals['open_bills']],
                        ['Open Bills Amount', $totals['open_amount']],
                        ['Void Bills', $totals['void_bills']],
                        ['Void Bills Amount', $totals['void_amount']],
                    ],
                ],
                [
                    'title' => 'Sales by Outlet',
                    'headers' => ['Outlet', 'Bills', 'Gross Sales', 'Discount', 'Net Sales', 'Tax', 'Total', 'Void Bills'],
                    'rows' => array_map(fn (array $row): array => [$row['outlet'], $row['bills'], $row['sub_total'], $row['discount'], $row['net_sales'], $row['tax_total'], $row['total'], $row['void_bills']], $summary['outlets']),
                ],
                [
                    'title' => 'Settlement',
                    'headers' => ['Settled By', 'Bills', 'Amount'],
                    'rows' => array_map(fn (array $row): array => [$row['label'], $row['count'], $row['amount']], $summary['settlements']),
                ],
                [
                    'title' => 'Item-wise Sales',
                    'headers' => ['Outlet', 'Item', 'Quantity', 'Amount'],
                    'rows' => array_map(fn (array $row): array => [$row['outlet'], $row['name'], $row['quantity'], $row['amount']], $summary['items']),
                ],
                [
                    'title' => 'Bills',
                    'headers' => ['Business Date', 'Outlet', 'Bill No.', 'Status', 'Guest', 'Room', 'Booking', 'Gross', 'Discount', 'Tax', 'Total', 'Paid By', 'Void Reason'],
                    'rows' => array_map(fn (array $bill): array => [
                        CarbonImmutable::parse($bill['business_date'])->format('d M, Y'),
                        $bill['outlet'],
                        $bill['bill_number'],
                        $bill['status_label'],
                        $bill['guest_name'] ?? '--',
                        $bill['room'] ?? '--',
                        $bill['booking_ref_id'] ?? '--',
                        $bill['sub_total'],
                        $bill['discount'],
                        $bill['tax_total'],
                        $bill['total'],
                        $bill['payment_mode'] ?? '--',
                        $bill['void_reason'] ?? '--',
                    ], $summary['bills']),
                ],
            ],
        ];
    }

    public function spreadsheet(Property $property, CarbonImmutable $from, CarbonImmutable $to): Spreadsheet
    {
        $report = $this->report($property, $from, $to);

        return ReportSheet::stackedWorkbook('POS Sales', $report['title'], $report['tables'], 24);
    }

    public function fileName(Property $property): string
    {
        return Str::studly($this->settings->get($property)['reports'][self::REPORT]['heading']).'.xlsx';
    }

    /**
     * @param  Collection<int, OutletBill>  $sales
     * @return array{bills: int, sub_total: float, discount: float, net_sales: float, tax_total: float, total: float}
     */
    private function totals(Collection $sales): array
    {
        $subTotal = round((float) $sales->sum('sub_total'), 2);
        $discount = round((float) $sales->sum('discount'), 2);

        return [
            'bills' => $sales->count(),
            'sub_total' => $subTotal,
            'discount' => $discount,
            'net_sales' => round($subTotal - $discount, 2),
            'tax_total' => round((float) $sales->sum('tax_total'), 2),
            'total' => round((float) $sales->sum('total'), 2),
        ];
    }
}
