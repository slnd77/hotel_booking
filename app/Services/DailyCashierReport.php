<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\CashierShift;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Spatie\LaravelPdf\Enums\Format;
use Spatie\LaravelPdf\PdfBuilder;

use function Spatie\LaravelPdf\Support\pdf;

/**
 * Reports → Daily Cashier Report ("Property Cashier Report"): the receipts
 * collected on the business date (the property's local day), with a total row, and the
 * refunds issued that day. The receipts sheet shows the columns picked under "Select
 * Columns"; a staff member narrows both sheets to what they collected or issued.
 */
class DailyCashierReport
{
    public const REPORT = 'daily_cashier_report';

    private const EMPTY = '--';

    /**
     * The receipts sheet's columns in sheet order: Select Columns key => header.
     *
     * @var array<string, string>
     */
    private const RECEIPT_COLUMNS = [
        'receipt_number' => 'Receipt Number.', 'guest_name' => 'Guest Name', 'txn_date' => 'Txn Date',
        'reference_no_1' => 'Reference No. 1', 'reference_no_2' => 'Reference No. 2', 'mode' => 'Mode',
        'total_amount' => 'Total Amount', 'total_tagged_amount' => 'Total Tagged Amount',
        'total_pg_commission_amount' => 'Total PG Commission Amount (If applicable)', 'notes' => 'Notes',
        'collection_source' => 'Collection Source', 'paid_by' => 'Paid By', 'party_name' => 'Party Name',
        'collected_by_staff' => 'Collected By Staff', 'currency' => 'Currency', 'currency_amount' => 'Currency Amount',
    ];

    /** Receipt columns that are summed on the total row. */
    private const TOTALLED_COLUMNS = ['total_amount', 'total_tagged_amount', 'total_pg_commission_amount', 'currency_amount'];

    private const REFUND_HEADERS = ['Txn Date', 'Refund Number.', 'Mode', 'Total Amount', 'Issued To', 'Reference No. 1', 'Reference No. 2', 'Refund Status', 'Notes'];

    private const SHIFT_HEADERS = ['Cashier', 'Opened', 'Closed', 'Opening Float', 'Cash Received', 'Cash Refunded', 'Expected Cash', 'Cash Counted', 'Variance', 'Handed Over To', 'Notes'];

    public function __construct(private ReportSettings $settings, private CashierShifts $shifts) {}

    /**
     * The two sheets: receipts (with the total row last) and refunds.
     *
     * @return array{title: string, sheets: list<array{title: string, headers: list<string>, rows: list<list<mixed>>}>}
     */
    public function report(Property $property, CarbonImmutable $date, ?User $staff = null): array
    {
        $hidden = $this->settings->get($property)['reports'][self::REPORT]['hidden_columns'];
        $columns = array_keys(array_diff_key(self::RECEIPT_COLUMNS, array_flip($hidden)));
        $currency = $property->currency?->code;
        $payments = $this->payments($property, $date, $staff);
        [$refunds, $receipts] = $payments->partition(fn (Payment $payment): bool => $payment->type === 'refund');

        $receiptRows = $receipts->map(function (Payment $payment) use ($property, $columns, $currency): array {
            $values = $this->receiptRow($property, $payment, $currency);

            return array_map(fn (string $key): mixed => $values[$key], $columns);
        })->values()->all();

        $totalRow = array_map(fn (string $key): mixed => match (true) {
            // No commission recorded on any receipt: nothing to total.
            $key === 'total_pg_commission_amount' && $receipts->whereNotNull('pg_commission_amount')->isEmpty() => self::EMPTY,
            in_array($key, self::TOTALLED_COLUMNS, true) => $this->amount((float) $receipts->sum(match ($key) {
                'total_tagged_amount' => 'tagged_amount',
                'total_pg_commission_amount' => 'pg_commission_amount',
                default => 'total_txn_amount',
            })),
            default => self::EMPTY,
        }, $columns);
        $totalRow[0] = 'Total';

        return [
            'title' => sprintf('%s for %s - %s', $this->settings->get($property)['reports'][self::REPORT]['heading'], $property->name, $date->format('M, d Y')).($staff ? " ({$staff->name})" : ''),
            'sheets' => [
                [
                    'title' => 'Receipts',
                    'headers' => array_map(fn (string $key): string => self::RECEIPT_COLUMNS[$key], $columns),
                    'rows' => [...$receiptRows, $totalRow],
                ],
                [
                    'title' => 'Refund',
                    'headers' => self::REFUND_HEADERS,
                    'rows' => $refunds->map(fn (Payment $payment): array => $this->refundRow($property, $payment))->values()->all(),
                ],
                [
                    'title' => 'Shifts',
                    'headers' => self::SHIFT_HEADERS,
                    'rows' => $this->shifts->forDay($property, $date->toDateString(), $staff)->map(fn (CashierShift $shift): array => $this->shiftRow($property, $shift))->values()->all(),
                ],
            ],
        ];
    }

    /**
     * The workbook: headers on row 1 of each sheet.
     */
    public function spreadsheet(Property $property, CarbonImmutable $date, ?User $staff = null): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        foreach ($this->report($property, $date, $staff)['sheets'] as $section) {
            $sheet = $spreadsheet->createSheet()->setTitle($section['title']);
            $lastColumn = Coordinate::stringFromColumnIndex(count($section['headers']));
            $lastRow = 1 + count($section['rows']);

            $sheet->fromArray($section['headers'], null, 'A1');
            $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->getColor()->setRGB('0000FF');

            if ($section['rows'] !== []) {
                $sheet->fromArray($section['rows'], null, 'A2', true);
            }

            $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);

            foreach (range(1, count($section['headers'])) as $columnIndex) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * Both sheets as tables in a landscape A4 PDF.
     */
    public function pdf(Property $property, CarbonImmutable $date, ?User $staff = null): PdfBuilder
    {
        return pdf()
            ->view('prints.reports.tables', $this->report($property, $date, $staff))
            ->format(Format::A4)
            ->landscape()
            ->margins(6, 6, 6, 6, 'mm')
            ->name($this->fileName($property, 'pdf'));
    }

    /**
     * "PropertyCashierReport - CONCH BHAVAN.xlsx", the file name for the report.
     */
    public function fileName(Property $property, string $extension = 'xlsx'): string
    {
        return 'PropertyCashierReport - '.str_replace(['/', '\\'], '-', $property->name).'.'.$extension;
    }

    /**
     * The day's receipts that were collected (received, or since refunded) and its refunds,
     * by transaction time in the property's local day.
     *
     * @return Collection<int, Payment>
     */
    private function payments(Property $property, CarbonImmutable $date, ?User $staff): Collection
    {
        $dayStart = CarbonImmutable::parse($date->toDateString(), $property->timezoneName())->startOfDay()->utc();

        return Payment::query()
            ->where('property_id', $property->id)
            ->whereBetween('payment_transaction_date', [$dayStart, $dayStart->addDay()->subSecond()])
            ->where(fn (Builder $query) => $query
                ->where('type', 'refund')
                ->orWhereIn('status', [PaymentStatus::Received->value, PaymentStatus::Refunded->value]))
            ->when($staff, fn (Builder $query) => $query->where('collected_by_staff', $staff->name))
            ->with('booking.agent.agency')
            ->orderBy('payment_transaction_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * A cashier shift open on the day; an open shift shows what it has taken so far.
     *
     * @return list<mixed>
     */
    private function shiftRow(Property $property, CashierShift $shift): array
    {
        $summary = $shift->isOpen() ? $this->shifts->summary($shift) : null;

        return [
            $this->text($shift->user?->name),
            $property->localTime($shift->opened_at)->format('d M, Y H:i'),
            $shift->closed_at ? $property->localTime($shift->closed_at)->format('d M, Y H:i') : 'Open',
            $this->amount((float) $shift->opening_float),
            $this->amount($summary['cash_receipts'] ?? (float) $shift->cash_receipts),
            $this->amount($summary['cash_refunds'] ?? (float) $shift->cash_refunds),
            $this->amount($summary['expected_cash'] ?? (float) $shift->expected_cash),
            $shift->isOpen() ? self::EMPTY : $this->amount((float) $shift->cash_counted),
            $shift->isOpen() ? self::EMPTY : $this->amount((float) $shift->variance),
            $this->text($shift->handedOverTo?->name),
            $this->text($shift->closing_notes),
        ];
    }

    /**
     * One receipt's cells, keyed like RECEIPT_COLUMNS.
     *
     * @return array<string, mixed>
     */
    private function receiptRow(Property $property, Payment $payment, ?string $currency): array
    {
        return [
            'receipt_number' => $this->text($payment->receipt_number),
            'guest_name' => $this->text($payment->guest_name),
            'txn_date' => $property->localTime($payment->payment_transaction_date)->format('d M, Y H:i'),
            'reference_no_1' => $this->text($this->referenceNumber($payment)),
            'reference_no_2' => $this->text($payment->pg_txn_id),
            'mode' => $this->text($payment->payment_mode?->label()),
            'total_amount' => $this->amount((float) $payment->total_txn_amount),
            'total_tagged_amount' => $this->amount((float) $payment->tagged_amount),
            // What the gateway kept, for online receipts that record it.
            'total_pg_commission_amount' => $payment->pg_commission_amount === null ? self::EMPTY : $this->amount((float) $payment->pg_commission_amount),
            'notes' => $this->text($payment->notes),
            'collection_source' => $this->text($payment->collection_source),
            'paid_by' => $this->text($payment->payee_name),
            'party_name' => $this->text($payment->booking?->agent?->agency?->name),
            'collected_by_staff' => $this->text($payment->collected_by_staff),
            // The currency it was paid in, and the amount in it (the property's own when none).
            'currency' => $this->text($payment->currency_code ?? $currency),
            'currency_amount' => $this->amount((float) ($payment->currency_amount ?? $payment->total_txn_amount)),
        ];
    }

    /**
     * One refund's cells, in REFUND_HEADERS order.
     *
     * @return list<mixed>
     */
    private function refundRow(Property $property, Payment $payment): array
    {
        return [
            $property->localTime($payment->payment_transaction_date)->format('d M, Y H:i'),
            $this->text($payment->receipt_number),
            $this->text($payment->payment_mode?->label()),
            $this->amount((float) $payment->total_txn_amount),
            $this->text($payment->payee_name),
            $this->text($this->referenceNumber($payment)),
            $this->text($payment->pg_txn_id),
            $this->text($payment->status?->label()),
            $this->text($payment->notes),
        ];
    }

    /**
     * The payment's own reference: the one typed in, else the bank transfer's or the OTA's.
     */
    private function referenceNumber(Payment $payment): ?string
    {
        return collect([$payment->payment_ref_id, $payment->neft_ref_id, $payment->imps_ref_id, $payment->rtgs_ref_id, $payment->ota_booking_ref_id])
            ->first(fn (?string $reference): bool => filled($reference));
    }

    private function text(?string $value): string
    {
        return filled($value) ? $value : self::EMPTY;
    }

    /**
     * Numbers stay numeric for Excel, like the sample (its empty total row reads 0).
     */
    private function amount(float $value): int|float
    {
        return floor($value) == $value ? (int) $value : round($value, 2);
    }
}
