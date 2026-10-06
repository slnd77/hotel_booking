<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Reports → GSTR-1 Sales Register Report: the property's invoices for the date range in the
 * official GSTR-1 Excel template (GST Offline Tool) — a sheet per table (b2b, b2cl, b2cs, cdnr,
 * cdnur, exp, at, atadj, exemp, hsn(b2b), hsn(b2c), docs), each with its summary rows (1-3),
 * its column headers (row 4) and its rows.
 *
 * Room accommodation is supplied where the property is, so every invoice is intra-state
 * (CGST + SGST) with the property's state as the place of supply: inter-state tables (b2cl,
 * exp) stay empty. Credit notes issued in the range go to cdnr when the recipient is
 * registered; for unregistered guests they reduce b2cs (an intra-state B2C note is adjusted
 * there, cdnur is only for B2CL and exports). Both reduce the HSN summary and are listed under
 * docs. Debit notes and advance receipts aren't recorded, so at and atadj stay empty.
 *
 * @phpstan-type Gstr1Line array{gstin: ?string, receiver: string, number: string, date: string, value: float, taxable: float, tax: float, rate: int, nights: int}
 */
class Gstr1SalesRegisterReport
{
    public const REPORT = 'gstr1_sales_register_report';

    /** Longest range one download covers (a quarterly return). */
    public const MAX_DAYS = 92;

    /** SAC for room or unit accommodation services. */
    public const ACCOMMODATION_SAC = '996311';

    /** GST slab rates an invoice's effective rate is matched to. */
    private const RATES = [0, 5, 12, 18, 28];

    /** GSTIN format: 2-digit state code, PAN, entity number, Z, check character. */
    private const GSTIN_PATTERN = '/^\d{2}[A-Z]{5}\d{4}[A-Z][A-Z\d]Z[A-Z\d]$/';

    /**
     * GST state codes by state name (the place of supply is written "27-Maharashtra").
     *
     * @var array<string, string>
     */
    public const STATE_CODES = [
        'Jammu and Kashmir' => '01', 'Himachal Pradesh' => '02', 'Punjab' => '03', 'Chandigarh' => '04', 'Uttarakhand' => '05',
        'Haryana' => '06', 'Delhi' => '07', 'Rajasthan' => '08', 'Uttar Pradesh' => '09', 'Bihar' => '10', 'Sikkim' => '11',
        'Arunachal Pradesh' => '12', 'Nagaland' => '13', 'Manipur' => '14', 'Mizoram' => '15', 'Tripura' => '16', 'Meghalaya' => '17',
        'Assam' => '18', 'West Bengal' => '19', 'Jharkhand' => '20', 'Odisha' => '21', 'Chhattisgarh' => '22', 'Madhya Pradesh' => '23',
        'Gujarat' => '24', 'Dadra and Nagar Haveli and Daman and Diu' => '26', 'Maharashtra' => '27', 'Karnataka' => '29', 'Goa' => '30',
        'Lakshadweep' => '31', 'Kerala' => '32', 'Tamil Nadu' => '33', 'Puducherry' => '34', 'Andaman and Nicobar Islands' => '35',
        'Telangana' => '36', 'Andhra Pradesh' => '37', 'Ladakh' => '38',
    ];

    /**
     * Each table's summary labels (row 2) and column headers (row 4), as in the template.
     *
     * @var array<string, array{title: string, summary: list<string>, headers: list<string>}>
     */
    private const TABLES = [
        'b2b' => ['title' => 'Summary For B2B(4)', 'summary' => ['No. of Recipients', '', 'No. of Invoices', '', 'Total Invoice Value', '', '', '', '', '', '', 'Total Taxable Value', 'Total Cess'],
            'headers' => ['GSTIN/UIN of Recipient', 'Receiver Name', 'Invoice Number', 'Invoice date', 'Invoice Value', 'Place Of Supply', 'Reverse Charge', 'Applicable % of Tax Rate', 'Invoice Type', 'E-Commerce GSTIN', 'Rate', 'Taxable Value', 'Cess Amount']],
        'b2cl' => ['title' => 'Summary For B2CL(5)', 'summary' => ['No. of Invoices', '', 'Total Inv Value', '', '', '', 'Total Taxable Value', 'Total Cess', ''],
            'headers' => ['Invoice Number', 'Invoice date', 'Invoice Value', 'Place Of Supply', 'Applicable % of Tax Rate', 'Rate', 'Taxable Value', 'Cess Amount', 'E-Commerce GSTIN']],
        'b2cs' => ['title' => 'Summary For B2CS(7)', 'summary' => ['', '', '', '', 'Total Taxable  Value', 'Total Cess', ''],
            'headers' => ['Type', 'Place Of Supply', 'Applicable % of Tax Rate', 'Rate', 'Taxable Value', 'Cess Amount', 'E-Commerce GSTIN']],
        'cdnr' => ['title' => 'Summary For CDNR(9B)', 'summary' => ['No. of Recipients', '', 'No. of Notes', '', '', '', '', '', 'Total Note Value', '', '', 'Total Taxable Value', 'Total Cess'],
            'headers' => ['GSTIN/UIN of Recipient', 'Receiver Name', 'Note Number', 'Note Date', 'Note Type', 'Place Of Supply', 'Reverse Charge', 'Note Supply Type', 'Note Value', 'Applicable % of Tax Rate', 'Rate', 'Taxable Value', 'Cess Amount']],
        'cdnur' => ['title' => 'Summary For CDNUR(9B)', 'summary' => ['', 'No. of Notes/Vouchers', '', '', '', 'Total Note Value', '', '', 'Total Taxable Value', 'Total Cess'],
            'headers' => ['UR Type', 'Note Number', 'Note Date', 'Note Type', 'Place Of Supply', 'Note Value', 'Applicable % of Tax Rate', 'Rate', 'Taxable Value', 'Cess Amount']],
        'exp' => ['title' => 'Summary For EXP(6)', 'summary' => ['', 'No. of Invoices', '', 'Total Invoice Value', '', 'No. of Shipping Bill', '', '', 'Total Taxable Value', 'Total Cess'],
            'headers' => ['Export Type', 'Invoice Number', 'Invoice date', 'Invoice Value', 'Port Code', 'Shipping Bill Number', 'Shipping Bill Date', 'Rate', 'Taxable Value', 'Cess Amount']],
        'at' => ['title' => 'Summary For Advance Received (11B)', 'summary' => ['', '', '', 'Total Advance Received', 'Total Cess'],
            'headers' => ['Place Of Supply', 'Applicable % of Tax Rate', 'Rate', 'Gross Advance Received', 'Cess Amount']],
        'atadj' => ['title' => 'Summary For Advance Adjusted (11B)', 'summary' => ['', '', '', 'Total Advance Adjusted', 'Total Cess'],
            'headers' => ['Place Of Supply', 'Applicable % of Tax Rate', 'Rate', 'Gross Advance Adjusted', 'Cess Amount']],
        'exemp' => ['title' => 'Summary For Nil rated, exempted and non GST outward supplies (8)', 'summary' => ['', 'Total Nil Rated Supplies', 'Total Exempted Supplies', 'Total Non-GST Supplies'],
            'headers' => ['Description', 'Nil Rated Supplies', 'Exempted(other than nil rated/non GST supply)', 'Non-GST Supplies']],
        'hsn(b2b)' => ['title' => 'Summary For HSN(12) - B2B', 'summary' => ['No. of HSN', '', '', '', 'Total Value', '', 'Total Taxable Value', 'Total Integrated Tax', 'Total Central Tax', 'Total State/UT Tax', 'Total Cess'],
            'headers' => ['HSN', 'Description', 'UQC', 'Total Quantity', 'Total Value', 'Rate', 'Taxable Value', 'Integrated Tax Amount', 'Central Tax Amount', 'State/UT Tax Amount', 'Cess Amount']],
        'hsn(b2c)' => ['title' => 'Summary For HSN(12) - B2C', 'summary' => ['No. of HSN', '', '', '', 'Total Value', '', 'Total Taxable Value', 'Total Integrated Tax', 'Total Central Tax', 'Total State/UT Tax', 'Total Cess'],
            'headers' => ['HSN', 'Description', 'UQC', 'Total Quantity', 'Total Value', 'Rate', 'Taxable Value', 'Integrated Tax Amount', 'Central Tax Amount', 'State/UT Tax Amount', 'Cess Amount']],
        'docs' => ['title' => 'Summary of documents issued during the tax period (13)', 'summary' => ['', '', '', 'Total Number', 'Total Cancelled'],
            'headers' => ['Nature of Document', 'Sr. No. From', 'Sr. No. To', 'Total Number', 'Cancelled']],
    ];

    /** Invoice statuses that mean the invoice was cancelled (counted under docs only). */
    private const CANCELLED_STATUSES = [InvoiceStatus::Cancelled, InvoiceStatus::Void];

    public function __construct(private ReportSettings $settings) {}

    /**
     * Each table's rows and its summary values (row 3), keyed by sheet name.
     *
     * @return array<string, array{summary: list<mixed>, rows: list<list<mixed>>}>
     */
    public function report(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $invoices = $this->invoices($property, $from, $to);
        $cancelled = $invoices->filter(fn (Invoice $invoice): bool => in_array($invoice->status, self::CANCELLED_STATUSES, true));
        $placeOfSupply = $this->placeOfSupply($property);

        $lines = $this->lines($property, $from, $to, $invoices);
        $creditNotes = $this->creditNotes($property, $from, $to);
        $notes = $this->noteLines($property, $from, $to, $creditNotes);
        [$taxed, $untaxed] = $lines->partition(fn (array $line): bool => $line['rate'] > 0);
        [$registered, $unregistered] = $taxed->partition(fn (array $line): bool => $line['gstin'] !== null);
        [$registeredNotes, $unregisteredNotes] = $notes->partition(fn (array $line): bool => $line['gstin'] !== null);

        // A note reduces what it credits: summed in as a negative line.
        $unregistered = $unregistered->concat(self::negated($unregisteredNotes->where('rate', '>', 0)));
        $untaxed = $untaxed->concat(self::negated($notes->where('rate', 0)));
        $registeredWithNotes = $registered->concat(self::negated($registeredNotes->where('rate', '>', 0)));

        $cdnr = array_values($registeredNotes->map(fn (array $line): array => [
            $line['gstin'], $line['receiver'], $line['number'], $line['date'], 'C', $placeOfSupply, 'N', 'Regular B2B', $line['value'], '', $line['rate'], $line['taxable'], 0,
        ])->all());

        $b2b = array_values($registered->map(fn (array $line): array => [
            $line['gstin'], $line['receiver'], $line['number'], $line['date'], $line['value'], $placeOfSupply, 'N', '', 'Regular B2B', '', $line['rate'], $line['taxable'], 0,
        ])->all());

        $b2cs = array_values($unregistered->groupBy('rate')->map(fn (Collection $group, int|string $rate): array => [
            'OE', $placeOfSupply, '', (int) $rate, round((float) $group->sum('taxable'), 2), 0, '',
        ])->sortBy(3)->all());

        $exempt = fn (bool $isRegistered): float => round((float) $untaxed->filter(fn (array $line): bool => ($line['gstin'] !== null) === $isRegistered)->sum('taxable'), 2);
        $exemp = [
            ['Inter-State supplies to registered persons', 0, 0, 0],
            ['Intra-State supplies to registered persons', 0, $exempt(true), 0],
            ['Inter-State supplies to unregistered persons', 0, 0, 0],
            ['Intra-State supplies to unregistered persons', 0, $exempt(false), 0],
        ];

        $hsnB2b = $this->hsnRows($registeredWithNotes->concat($untaxed->filter(fn (array $line): bool => $line['gstin'] !== null)));
        $hsnB2c = $this->hsnRows($unregistered->concat($untaxed->filter(fn (array $line): bool => $line['gstin'] === null)));
        $docs = [...$this->documentRows($invoices, $cancelled), ...$this->creditNoteDocumentRows($creditNotes)];

        $tables = [
            'b2b' => ['summary' => [$registered->pluck('gstin')->unique()->count(), '', count($b2b), '', $this->sum($b2b, 4), '', '', '', '', '', '', $this->sum($b2b, 11), 0], 'rows' => $b2b],
            'b2cl' => ['summary' => [0, '', 0, '', '', '', 0, 0, ''], 'rows' => []],
            'b2cs' => ['summary' => ['', '', '', '', $this->sum($b2cs, 4), 0, ''], 'rows' => $b2cs],
            'cdnr' => ['summary' => [$registeredNotes->pluck('gstin')->unique()->count(), '', count($cdnr), '', '', '', '', '', $this->sum($cdnr, 8), '', '', $this->sum($cdnr, 11), 0], 'rows' => $cdnr],
            'cdnur' => ['summary' => ['', 0, '', '', '', 0, '', '', 0, 0], 'rows' => []],
            'exp' => ['summary' => ['', 0, '', 0, '', 0, '', '', 0, 0], 'rows' => []],
            'at' => ['summary' => ['', '', '', 0, 0], 'rows' => []],
            'atadj' => ['summary' => ['', '', '', 0, 0], 'rows' => []],
            'exemp' => ['summary' => ['', 0, $this->sum($exemp, 2), 0], 'rows' => $exemp],
            'hsn(b2b)' => ['summary' => $this->hsnSummary($hsnB2b), 'rows' => $hsnB2b],
            'hsn(b2c)' => ['summary' => $this->hsnSummary($hsnB2c), 'rows' => $hsnB2c],
            'docs' => ['summary' => ['', '', '', $this->sum($docs, 3), $this->sum($docs, 4)], 'rows' => $docs],
        ];

        // Whole amounts go in as integers, like the template's own figures.
        return array_map(fn (array $table): array => [
            'summary' => array_map($this->number(...), $table['summary']),
            'rows' => array_map(fn (array $row): array => array_map($this->number(...), $row), $table['rows']),
        ], $tables);
    }

    private function number(mixed $value): mixed
    {
        return is_float($value) && floor($value) == $value ? (int) $value : $value;
    }

    /**
     * The template workbook, with every table or only the ones named (e.g. the HSN summary).
     *
     * @param  list<string>|null  $only  sheet names to keep
     */
    public function spreadsheet(Property $property, CarbonImmutable $from, CarbonImmutable $to, ?array $only = null): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $tables = $this->report($property, $from, $to);

        foreach ($only === null ? $tables : array_intersect_key($tables, array_flip($only)) as $name => $table) {
            $layout = self::TABLES[$name];
            $sheet = $spreadsheet->createSheet()->setTitle($name);
            $lastColumn = Coordinate::stringFromColumnIndex(count($layout['headers']));

            $sheet->setCellValue('A1', $layout['title']);
            $sheet->fromArray($layout['summary'], null, 'A2', true);
            $sheet->fromArray($table['summary'], null, 'A3', true);
            $sheet->fromArray($layout['headers'], null, 'A4', true);

            // The template's blue summary rows and peach header row.
            $sheet->getStyle("A1:{$lastColumn}2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0070C0');
            $sheet->getStyle("A1:{$lastColumn}2")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle("A4:{$lastColumn}4")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8CBAD');
            $sheet->getStyle("A4:{$lastColumn}4")->getFont()->setBold(true);

            if ($table['rows'] !== []) {
                $sheet->fromArray($table['rows'], null, 'A5', true);
            }

            foreach (range(1, count($layout['headers'])) as $columnIndex) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
            }
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    public function fileName(Property $property): string
    {
        return Str::studly($this->settings->get($property)['reports'][self::REPORT]['heading']).'.xlsx';
    }

    /**
     * The range's issued (not cancelled) invoices as GSTR-1 figures — also what GSTR-3B sums.
     *
     * @param  Collection<int, Invoice>|null  $invoices  the range's invoices, when already loaded
     * @return Collection<int, array{gstin: ?string, receiver: string, number: string, date: string, value: float, taxable: float, tax: float, rate: int, nights: int}>
     */
    public function lines(Property $property, CarbonImmutable $from, CarbonImmutable $to, ?Collection $invoices = null): Collection
    {
        return ($invoices ?? $this->invoices($property, $from, $to))
            ->reject(fn (Invoice $invoice): bool => in_array($invoice->status, self::CANCELLED_STATUSES, true))
            ->map(fn (Invoice $invoice): array => $this->line($property, $invoice))
            ->values();
    }

    /**
     * The range's credit notes as GSTR-1 figures: the recipient and rate of the invoice each
     * one credits, in positive amounts (see negated()).
     *
     * @param  Collection<int, CreditNote>|null  $creditNotes  the range's notes, when already loaded
     * @return Collection<int, array{gstin: ?string, receiver: string, number: string, date: string, value: float, taxable: float, tax: float, rate: int, nights: int}>
     */
    public function noteLines(Property $property, CarbonImmutable $from, CarbonImmutable $to, ?Collection $creditNotes = null): Collection
    {
        return ($creditNotes ?? $this->creditNotes($property, $from, $to))
            ->filter(fn (CreditNote $note): bool => $note->invoice !== null)
            ->map(fn (CreditNote $note): array => [
                ...$this->line($property, $note->invoice),
                'number' => (string) $note->credit_note_number,
                'date' => $note->issue_date->format('d-M-Y'),
                'value' => round((float) $note->total, 2),
                'taxable' => round((float) $note->taxable_amount, 2),
                'tax' => round((float) $note->tax_amount, 2),
                'nights' => 0,
            ])
            ->values();
    }

    /**
     * Lines turned negative, to be summed in as reductions.
     *
     * @param  Collection<int, Gstr1Line>  $lines
     * @return Collection<int, Gstr1Line>
     */
    public static function negated(Collection $lines): Collection
    {
        return $lines->map(fn (array $line): array => [...$line, 'value' => -$line['value'], 'taxable' => -$line['taxable'], 'tax' => -$line['tax']]);
    }

    /**
     * The property's credit notes issued in the range, by number.
     *
     * @return Collection<int, CreditNote>
     */
    private function creditNotes(Property $property, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return CreditNote::query()
            ->where('property_id', $property->id)
            ->whereDate('issue_date', '>=', $from->toDateString())
            ->whereDate('issue_date', '<=', $to->toDateString())
            ->with('invoice.booking.guests')
            ->get()
            ->sortBy('credit_note_number', SORT_NATURAL)
            ->values();
    }

    /**
     * Table 13's credit note series (by number prefix).
     *
     * @param  Collection<int, CreditNote>  $creditNotes
     * @return list<list<mixed>>
     */
    private function creditNoteDocumentRows(Collection $creditNotes): array
    {
        return array_values($creditNotes
            ->groupBy(fn (CreditNote $note): string => (string) preg_replace('/\d+$/', '', (string) $note->credit_note_number))
            ->map(fn (Collection $series): array => ['Credit Note', $series->first()->credit_note_number, $series->last()->credit_note_number, $series->count(), 0])
            ->all());
    }

    /**
     * "27-Maharashtra": the property's state, where its rooms are supplied.
     */
    public function placeOfSupply(Property $property): string
    {
        $state = $property->state?->name;

        if (! $state) {
            return '';
        }

        $code = collect(self::STATE_CODES)->first(fn (string $code, string $name): bool => strcasecmp($name, $state) === 0);

        return $code ? "{$code}-{$state}" : $state;
    }

    /**
     * The property's invoices generated in the range (its local days), by invoice number.
     *
     * @return Collection<int, Invoice>
     */
    private function invoices(Property $property, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $start = CarbonImmutable::parse($from->toDateString(), $property->timezoneName())->startOfDay()->utc();
        $end = CarbonImmutable::parse($to->toDateString(), $property->timezoneName())->addDay()->startOfDay()->utc();

        return Invoice::query()
            ->where('property_id', $property->id)
            ->where('invoice_generation_date', '>=', $start)
            ->where('invoice_generation_date', '<', $end)
            ->with('booking.guests')
            ->get()
            ->sortBy('invoice_number', SORT_NATURAL)
            ->values();
    }

    /**
     * An invoice's GSTR-1 figures. The recipient is registered when the invoice, or the
     * guest's company it's billed to, has a valid GSTIN. The rate is the invoice's tax over
     * its taxable value, matched to the nearest GST slab.
     *
     * @return array{gstin: ?string, receiver: string, number: string, date: string, value: float, taxable: float, tax: float, rate: int, nights: int}
     */
    private function line(Property $property, Invoice $invoice): array
    {
        $company = $invoice->booking?->guests->first()?->company;
        $gstin = collect([$invoice->party_gstin, $company['gstin'] ?? null])
            ->map(fn (?string $value): string => Str::upper(trim((string) $value)))
            ->first(fn (string $value): bool => preg_match(self::GSTIN_PATTERN, $value) === 1);
        $taxable = round((float) $invoice->taxable_amount, 2);
        $tax = round((float) $invoice->total_tax, 2);
        $effectiveRate = $taxable > 0 ? $tax / $taxable * 100 : 0.0;

        return [
            'gstin' => $gstin,
            'receiver' => filled($company['name'] ?? null) && $gstin !== null ? (string) $company['name'] : (string) ($invoice->party_name ?: $invoice->guest_name),
            'number' => (string) $invoice->invoice_number,
            'date' => $property->localTime($invoice->invoice_generation_date)->format('d-M-Y'),
            'value' => round((float) $invoice->invoice_amount, 2),
            'taxable' => $taxable,
            'tax' => $tax,
            'rate' => $tax > 0 ? collect(self::RATES)->sortBy(fn (int $rate): float => abs($rate - $effectiveRate))->first() : 0,
            'nights' => max(1, (int) $invoice->room_nights) * max(1, (int) $invoice->rooms_count),
        ];
    }

    /**
     * HSN summary rows, one per rate: room nights as the quantity, tax split CGST / SGST.
     *
     * @param  Collection<int, Gstr1Line>  $lines
     * @return list<list<mixed>>
     */
    private function hsnRows(Collection $lines): array
    {
        return array_values($lines->groupBy('rate')->map(function (Collection $group, int|string $rate): array {
            $tax = round((float) $group->sum('tax'), 2);
            $central = round($tax / 2, 2);

            return [
                self::ACCOMMODATION_SAC, 'Room or unit accommodation services', 'OTH-OTHERS', (int) $group->sum('nights'),
                round((float) $group->sum(fn (array $line): float => $line['value']), 2), (int) $rate, round((float) $group->sum(fn (array $line): float => $line['taxable']), 2), 0, $central, round($tax - $central, 2), 0,
            ];
        })->sortBy(5)->all());
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @return list<mixed>
     */
    private function hsnSummary(array $rows): array
    {
        return [count($rows), '', '', '', $this->sum($rows, 4), '', $this->sum($rows, 6), $this->sum($rows, 7), $this->sum($rows, 8), $this->sum($rows, 9), 0];
    }

    /**
     * Table 13: each invoice series (its number prefix) with its first and last number,
     * how many were issued and how many of them were cancelled.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @param  Collection<int, Invoice>  $cancelled
     * @return list<list<mixed>>
     */
    private function documentRows(Collection $invoices, Collection $cancelled): array
    {
        $cancelledIds = $cancelled->pluck('id')->flip();

        return array_values($invoices
            ->groupBy(fn (Invoice $invoice): string => (string) preg_replace('/\d+$/', '', (string) $invoice->invoice_number))
            ->map(fn (Collection $series): array => [
                'Invoices for outward supply',
                $series->first()->invoice_number,
                $series->last()->invoice_number,
                $series->count(),
                $series->filter(fn (Invoice $invoice): bool => $cancelledIds->has($invoice->id))->count(),
            ])
            ->all());
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function sum(array $rows, int $column): float|int
    {
        $total = round(array_sum(array_map(fn (array $row): float => (float) $row[$column], $rows)), 2);

        return floor($total) == $total ? (int) $total : $total;
    }
}
