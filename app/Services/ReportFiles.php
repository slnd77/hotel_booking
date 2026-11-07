<?php

namespace App\Services;

use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Builds a report's Excel or PDF file: for the Reports page download, and for the e-mailed
 * report (scheduled or "E-mail now"), which the queue worker builds.
 */
class ReportFiles
{
    /**
     * Reports whose files are built, and the file types each offers.
     *
     * @var array<string, list<string>>
     */
    public const AVAILABLE = [
        DailyFrontOfficeReport::REPORT => ['excel', 'pdf'],
        DailySalesReport::REPORT => ['excel', 'pdf'],
        InventoryAvailabilityReport::REPORT => ['excel'],
        OccupiedRoomsReport::REPORT => ['excel', 'pdf'],
        DailyCashierReport::REPORT => ['excel', 'pdf'],
        CheckedOutFoliosModificationReport::REPORT => ['excel'],
        DailyTransactionReport::REPORT => ['excel'],
        DailyHousekeepingReport::REPORT => ['excel'],
        AccountReceivablesReport::REPORT => ['excel'],
        Gstr1SalesRegisterReport::REPORT => ['excel'],
        Gstr3bSummaryReturnReport::REPORT => ['excel'],
        HsnSacSummaryReport::REPORT => ['excel'],
        NationalityRevenueReport::REPORT => ['excel', 'pdf'],
        FinancialTransactionSummaryReport::REPORT => ['excel'],
        PosSalesReport::REPORT => ['excel'],
    ];

    /**
     * Operational reports for days still open (or ahead): Restrict Reports leaves them alone.
     *
     * @var list<string>
     */
    public const OPERATIONAL_REPORTS = [InventoryAvailabilityReport::REPORT, DailyHousekeepingReport::REPORT];

    /**
     * The date-range reports, each capped at its MAX_DAYS.
     *
     * @var array<string, class-string<InventoryAvailabilityReport|Gstr1SalesRegisterReport|Gstr3bSummaryReturnReport|HsnSacSummaryReport|NationalityRevenueReport|FinancialTransactionSummaryReport|PosSalesReport>>
     */
    public const RANGE_REPORTS = [
        InventoryAvailabilityReport::REPORT => InventoryAvailabilityReport::class,
        Gstr1SalesRegisterReport::REPORT => Gstr1SalesRegisterReport::class,
        Gstr3bSummaryReturnReport::REPORT => Gstr3bSummaryReturnReport::class,
        HsnSacSummaryReport::REPORT => HsnSacSummaryReport::class,
        NationalityRevenueReport::REPORT => NationalityRevenueReport::class,
        FinancialTransactionSummaryReport::REPORT => FinancialTransactionSummaryReport::class,
        PosSalesReport::REPORT => PosSalesReport::class,
    ];

    /**
     * The single-date reports with only an Excel file (one sheet).
     *
     * @var array<string, class-string<CheckedOutFoliosModificationReport|DailyTransactionReport|DailyHousekeepingReport|AccountReceivablesReport>>
     */
    private const SHEET_REPORTS = [
        CheckedOutFoliosModificationReport::REPORT => CheckedOutFoliosModificationReport::class,
        DailyTransactionReport::REPORT => DailyTransactionReport::class,
        DailyHousekeepingReport::REPORT => DailyHousekeepingReport::class,
        AccountReceivablesReport::REPORT => AccountReceivablesReport::class,
    ];

    /**
     * The single-date reports with an Excel and a PDF file.
     *
     * @var array<string, class-string<BookingListReport|OccupiedRoomsReport>>
     */
    private const DATED_REPORTS = [
        DailyFrontOfficeReport::REPORT => DailyFrontOfficeReport::class,
        DailySalesReport::REPORT => DailySalesReport::class,
        OccupiedRoomsReport::REPORT => OccupiedRoomsReport::class,
    ];

    /**
     * @return list<string>
     */
    public function types(string $report): array
    {
        return self::AVAILABLE[$report] ?? [];
    }

    public function isRange(string $report): bool
    {
        return isset(self::RANGE_REPORTS[$report]);
    }

    /**
     * Night audit → Restrict Reports: a dated report (not an operational one) only covers
     * business dates the night audit has closed.
     *
     * @throws ValidationException
     */
    public function ensureReportable(Property $property, string $report, CarbonImmutable $date, ?CarbonImmutable $endDate = null): void
    {
        if (! $this->isRestrictable($report)) {
            return;
        }

        app(NightAuditSettings::class)->ensureReportable($property, $date, $this->isRange($report) ? ($endDate ?? $date) : null);
    }

    public function isRestrictable(string $report): bool
    {
        return (ReportSettings::REPORTS[$report]['dates'] ?? ReportSettings::DATES_NONE) !== ReportSettings::DATES_NONE
            && ! in_array($report, self::OPERATIONAL_REPORTS, true);
    }

    /**
     * The report as a spreadsheet or a PDF, with its file name. A range report runs from $date
     * to $endDate (the same day when null).
     *
     * @return array{0: Spreadsheet|PdfBuilder, 1: string}
     *
     * @throws ValidationException when the range is longer than the report allows, or reaches past the
     *                             last closed business date with Restrict Reports on
     */
    public function make(Property $property, string $report, string $type, CarbonImmutable $date, ?CarbonImmutable $endDate = null, ?User $staff = null): array
    {
        $this->ensureReportable($property, $report, $date, $endDate);

        if (isset(self::SHEET_REPORTS[$report])) {
            $sheetReport = app(self::SHEET_REPORTS[$report]);

            return [$sheetReport->spreadsheet($property, $date), $sheetReport->fileName($property)];
        }

        if ($report === DailyCashierReport::REPORT) {
            $cashier = app(DailyCashierReport::class);

            return $type === 'pdf'
                ? $this->withPdfName($cashier->pdf($property, $date, $staff))
                : [$cashier->spreadsheet($property, $date, $staff), $cashier->fileName($property)];
        }

        if (isset(self::RANGE_REPORTS[$report])) {
            $endDate ??= $date;
            $rangeReport = app(self::RANGE_REPORTS[$report]);

            if ($date->diffInDays($endDate) >= $rangeReport::MAX_DAYS) {
                throw ValidationException::withMessages(['end_date' => 'Pick a range of '.$rangeReport::MAX_DAYS.' days or fewer.']);
            }

            if ($rangeReport instanceof NationalityRevenueReport && $type === 'pdf') {
                return $this->withPdfName($rangeReport->pdf($property, $date, $endDate));
            }

            return [$rangeReport->spreadsheet($property, $date, $endDate), $rangeReport->fileName($property)];
        }

        $datedReport = app(self::DATED_REPORTS[$report]);

        return $type === 'pdf'
            ? $this->withPdfName($datedReport->pdf($property, $date))
            : [$datedReport->spreadsheet($property, $date), $datedReport->fileName($property)];
    }

    /**
     * The file's contents, for an e-mail attachment.
     */
    public function contents(Spreadsheet|PdfBuilder $file): string
    {
        if ($file instanceof PdfBuilder) {
            return $file->generatePdfContent();
        }

        ob_start();

        try {
            (new Xlsx($file))->save('php://output');
        } finally {
            $contents = (string) ob_get_clean();
        }

        return $contents;
    }

    /**
     * Headings like "HSN/SAC Summary Report" can't put a slash in the file name.
     */
    public function safeFileName(string $fileName): string
    {
        return str_replace(['/', '\\'], '', $fileName);
    }

    /**
     * @return array{0: PdfBuilder, 1: string}
     */
    private function withPdfName(PdfBuilder $pdf): array
    {
        return [$pdf, $pdf->downloadName];
    }
}
