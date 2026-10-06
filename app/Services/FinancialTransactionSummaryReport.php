<?php

namespace App\Services;

use App\Enums\ChargeCategory;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\Booking;
use App\Models\CompanyProfile;
use App\Models\Payment;
use App\Models\Property;
use App\Models\SubBooking;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Reports → Financial Transaction Summary: the money that moved through the property's
 * folios in the date range (its local days) — charges posted and credits applied, by charge
 * code, from the ledger — and the receipts and refunds taken, by payment mode. Balance
 * transfers between folio windows net to zero and are left out.
 */
class FinancialTransactionSummaryReport
{
    public const REPORT = 'financial_transaction_summary';

    /** Longest range one download covers. */
    public const MAX_DAYS = 92;

    public function __construct(private ReportSettings $settings) {}

    /**
     * @return array{title: string, tables: list<array{title: string, headers: list<string>, rows: list<list<mixed>>}>}
     */
    public function report(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        [$start, $end] = $this->window($property, $from, $to);
        $entries = $this->ledgerEntries($property, $start, $end);
        [$debits, $credits] = $entries->partition(fn (Transaction $entry): bool => $entry->transaction_type === TransactionType::Debit);

        $chargeRows = $this->byChargeCode($debits);
        $creditRows = $this->byChargeCode($credits);
        $totalCharges = round((float) $debits->sum('amount'), 2);
        $totalCredits = round((float) $credits->sum('amount'), 2);

        $payments = $this->payments($property, $start, $end);
        [$refunds, $receipts] = $payments->partition(fn (Payment $payment): bool => $payment->type === 'refund');
        $modeRows = $payments
            ->groupBy(fn (Payment $payment): string => $payment->payment_mode?->label() ?? 'Others')
            ->map(function (Collection $group, string $mode): array {
                [$modeRefunds, $modeReceipts] = $group->partition(fn (Payment $payment): bool => $payment->type === 'refund');
                $received = round((float) $modeReceipts->sum('total_txn_amount'), 2);
                $refunded = round((float) $modeRefunds->sum('total_txn_amount'), 2);

                return [$mode, $modeReceipts->count(), $received, $modeRefunds->count(), $refunded, round($received - $refunded, 2)];
            })
            ->sortKeys()
            ->values()
            ->all();
        $received = round((float) $receipts->sum('total_txn_amount'), 2);
        $refunded = round((float) $refunds->sum('total_txn_amount'), 2);

        return [
            'title' => sprintf('%s for %s - %s to %s', $this->settings->get($property)['reports'][self::REPORT]['heading'], $property->name, $from->format('d M, Y'), $to->format('d M, Y')),
            'tables' => [
                [
                    'title' => 'Summary',
                    'headers' => ['Description', 'Amount'],
                    'rows' => [
                        ['Total Charges Posted', $totalCharges],
                        ['Total Credits Applied', $totalCredits],
                        ['Net Folio Movement (Charges - Credits)', round($totalCharges - $totalCredits, 2)],
                        ['Receipts Collected', $received],
                        ['Refunds Issued', $refunded],
                        ['Net Collection', round($received - $refunded, 2)],
                    ],
                ],
                [
                    'title' => 'Charges Posted',
                    'headers' => ['Charge', 'Code', 'Category', 'No. of Transactions', 'Amount'],
                    'rows' => [...$chargeRows, ['Total', '', '', $debits->count(), $totalCharges]],
                ],
                [
                    'title' => 'Credits Applied (payments, discounts, adjustments)',
                    'headers' => ['Credit', 'Code', 'Category', 'No. of Transactions', 'Amount'],
                    'rows' => [...$creditRows, ['Total', '', '', $credits->count(), $totalCredits]],
                ],
                [
                    'title' => 'Collections by Payment Mode',
                    'headers' => ['Payment Mode', 'No. of Receipts', 'Amount Received', 'No. of Refunds', 'Amount Refunded', 'Net Collected'],
                    'rows' => [...$modeRows, ['Total', $receipts->count(), $received, $refunds->count(), $refunded, round($received - $refunded, 2)]],
                ],
            ],
        ];
    }

    public function spreadsheet(Property $property, CarbonImmutable $from, CarbonImmutable $to): Spreadsheet
    {
        $report = $this->report($property, $from, $to);

        return ReportSheet::stackedWorkbook('Financial Summary', $report['title'], $report['tables']);
    }

    public function fileName(Property $property): string
    {
        return Str::studly($this->settings->get($property)['reports'][self::REPORT]['heading']).'.xlsx';
    }

    /**
     * The range as UTC instants: the first day's local midnight to the day after the last.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            CarbonImmutable::parse($from->toDateString(), $property->timezoneName())->startOfDay()->utc(),
            CarbonImmutable::parse($to->toDateString(), $property->timezoneName())->addDay()->startOfDay()->utc(),
        ];
    }

    /**
     * Ledger entries posted in the window to the property's folios (a room's, a booking's
     * master bill or a company's), balance transfers left out.
     *
     * @return Collection<int, Transaction>
     */
    private function ledgerEntries(Property $property, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return Transaction::query()
            ->where('posted_at', '>=', $start)
            ->where('posted_at', '<', $end)
            ->whereHas('subFolio.folio', fn (Builder $folio) => $folio->whereHasMorph(
                'owner',
                [SubBooking::class, Booking::class, CompanyProfile::class],
                fn (Builder $owner, string $type) => $type === SubBooking::class
                    ? $owner->whereHas('booking', fn (Builder $booking) => $booking->where('property_id', $property->id))
                    : $owner->where('property_id', $property->id),
            ))
            ->whereHas('chargeCode', fn (Builder $code) => $code->where('category', '!=', ChargeCategory::Transfer->value))
            ->with('chargeCode')
            ->get();
    }

    /**
     * One row per charge code: name, code, category, count and amount, largest first.
     *
     * @param  Collection<int, Transaction>  $entries
     * @return list<list<mixed>>
     */
    private function byChargeCode(Collection $entries): array
    {
        return array_values($entries
            ->groupBy('charge_code_id')
            ->map(function (Collection $group): array {
                $code = $group->first()->chargeCode;

                return [$code->name ?? 'Other', $code->code ?? '--', $code?->category ? Str::headline($code->category->value) : '--', $group->count(), round((float) $group->sum('amount'), 2)];
            })
            ->sortByDesc(4)
            ->all());
    }

    /**
     * Receipts collected (received, or since refunded) and refunds issued in the window.
     *
     * @return Collection<int, Payment>
     */
    private function payments(Property $property, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return Payment::query()
            ->where('property_id', $property->id)
            ->where('payment_transaction_date', '>=', $start)
            ->where('payment_transaction_date', '<', $end)
            ->where(fn (Builder $query) => $query
                ->where('type', 'refund')
                ->orWhereIn('status', [PaymentStatus::Received->value, PaymentStatus::Refunded->value]))
            ->get();
    }
}
