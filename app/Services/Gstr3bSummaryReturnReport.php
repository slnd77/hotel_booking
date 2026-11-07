<?php

namespace App\Services;

use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Reports → GSTR-3B Summary Return Report: the official GSTR-3B tables for the date range,
 * from the same invoices as the GSTR-1 Sales Register.
 *
 * Only outward supplies are recorded, less the credit notes issued in the range: taxed room sales fill 3.1(a) (intra-state, so CGST +
 * SGST), untaxed ones 3.1(c), and 6.1 shows the tax payable. Purchases and input tax credit
 * aren't kept, so tables 4 and 5 and the ITC / cash columns of 6.1 are left at zero for
 * the accountant to complete; rooms are never inter-state, so 3.2 stays empty.
 */
class Gstr3bSummaryReturnReport
{
    public const REPORT = 'gstr3b_summary_return_report';

    public const MAX_DAYS = Gstr1SalesRegisterReport::MAX_DAYS;

    public function __construct(private ReportSettings $settings, private Gstr1SalesRegisterReport $gstr1) {}

    /**
     * The return's tables in order: title, column headers and rows (label first).
     *
     * @return array{title: string, tables: list<array{title: string, headers: list<string>, rows: list<list<mixed>>}>}
     */
    public function report(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $lines = $this->gstr1->lines($property, $from, $to)
            ->concat(Gstr1SalesRegisterReport::negated($this->gstr1->noteLines($property, $from, $to)));
        [$taxed, $untaxed] = $lines->partition(fn (array $line): bool => $line['rate'] > 0);

        $taxable = round((float) $taxed->sum('taxable'), 2);
        $central = round((float) $taxed->sum(fn (array $line): float => round($line['tax'] / 2, 2)), 2);
        $state = round((float) $taxed->sum('tax') - $central, 2);
        $exempt = round((float) $untaxed->sum('taxable'), 2);

        $taxHeads = ['Integrated Tax', 'Central Tax', 'State/UT Tax', 'Cess'];
        $zeroTaxes = [0, 0, 0, 0];

        return [
            'title' => sprintf('%s for %s (%s) - %s to %s', $this->settings->get($property)['reports'][self::REPORT]['heading'], $property->name, $this->gstr1->placeOfSupply($property) ?: '--', $from->format('d-M-Y'), $to->format('d-M-Y')),
            'tables' => [
                [
                    'title' => '3.1 Details of Outward Supplies and inward supplies liable to reverse charge',
                    'headers' => ['Nature of Supplies', 'Total Taxable value', ...$taxHeads],
                    'rows' => [
                        ['(a) Outward taxable supplies (other than zero rated, nil rated and exempted)', $taxable, 0, $central, $state, 0],
                        ['(b) Outward taxable supplies (zero rated)', 0, 0, 0, 0, 0],
                        ['(c) Other outward supplies (Nil rated, exempted)', $exempt, 0, 0, 0, 0],
                        ['(d) Inward supplies (liable to reverse charge)', 0, 0, 0, 0, 0],
                        ['(e) Non-GST outward supplies', 0, 0, 0, 0, 0],
                    ],
                ],
                [
                    'title' => '3.1.1 Details of Supplies notified under section 9(5) of the CGST Act, 2017',
                    'headers' => ['Description', 'Total Taxable value', ...$taxHeads],
                    'rows' => [
                        ['(i) Taxable supplies on which electronic commerce operator pays tax u/s 9(5)', 0, 0, 0, 0, 0],
                        ['(ii) Taxable supplies made by the registered person through electronic commerce operator, on which electronic commerce operator is required to pay tax u/s 9(5)', 0, 0, 0, 0, 0],
                    ],
                ],
                [
                    'title' => '3.2 Of the supplies shown in 3.1 (a) above, details of inter-State supplies made to unregistered persons, composition taxable persons and UIN holders',
                    'headers' => ['Nature of Supplies', 'Place of Supply (State/UT)', 'Total Taxable value', 'Amount of Integrated Tax'],
                    'rows' => [
                        ['Supplies made to Unregistered Persons', '', 0, 0],
                        ['Supplies made to Composition Taxable Persons', '', 0, 0],
                        ['Supplies made to UIN holders', '', 0, 0],
                    ],
                ],
                [
                    'title' => '4. Eligible ITC',
                    'headers' => ['Details', ...$taxHeads],
                    'rows' => [
                        ['(A) ITC Available (whether in full or part)', '', '', '', ''],
                        ['(1) Import of goods', ...$zeroTaxes],
                        ['(2) Import of services', ...$zeroTaxes],
                        ['(3) Inward supplies liable to reverse charge (other than 1 & 2 above)', ...$zeroTaxes],
                        ['(4) Inward supplies from ISD', ...$zeroTaxes],
                        ['(5) All other ITC', ...$zeroTaxes],
                        ['(B) ITC Reversed', '', '', '', ''],
                        ['(1) As per rules 38, 42 & 43 of CGST Rules and section 17(5)', ...$zeroTaxes],
                        ['(2) Others', ...$zeroTaxes],
                        ['(C) Net ITC Available (A) - (B)', ...$zeroTaxes],
                        ['(D) Other Details', '', '', '', ''],
                        ['(1) ITC reclaimed which was reversed under Table 4(B)(2) in earlier tax period', ...$zeroTaxes],
                        ['(2) Ineligible ITC under section 16(4) & ITC restricted due to PoS rules', ...$zeroTaxes],
                    ],
                ],
                [
                    'title' => '5. Values of exempt, nil-rated and non-GST inward supplies',
                    'headers' => ['Nature of supplies', 'Inter-State supplies', 'Intra-State supplies'],
                    'rows' => [
                        ['From a supplier under composition scheme, Exempt and Nil rated supply', 0, 0],
                        ['Non GST supply', 0, 0],
                    ],
                ],
                [
                    'title' => '5.1 Interest & late fee payable',
                    'headers' => ['Description', ...$taxHeads],
                    'rows' => [
                        ['Interest', ...$zeroTaxes],
                        ['Late Fee', '', 0, 0, ''],
                    ],
                ],
                [
                    'title' => '6.1 Payment of tax',
                    'headers' => ['Description', 'Tax payable', 'Paid through ITC - Integrated Tax', 'Paid through ITC - Central Tax', 'Paid through ITC - State/UT Tax', 'Paid through ITC - Cess', 'Tax paid in cash', 'Interest paid in cash', 'Late Fee paid in cash'],
                    'rows' => [
                        ['Integrated Tax', 0, 0, 0, 0, '', 0, 0, ''],
                        ['Central Tax', $central, 0, 0, '', '', 0, 0, 0],
                        ['State/UT Tax', $state, 0, '', 0, '', 0, 0, 0],
                        ['Cess', 0, '', '', '', 0, 0, 0, ''],
                    ],
                ],
            ],
        ];
    }

    /**
     * One sheet: the title, then each table with its title bar and header row.
     */
    public function spreadsheet(Property $property, CarbonImmutable $from, CarbonImmutable $to): Spreadsheet
    {
        $report = $this->report($property, $from, $to);

        return ReportSheet::stackedWorkbook('GSTR-3B', $report['title'], $report['tables'], 70);
    }

    public function fileName(Property $property): string
    {
        return Str::studly($this->settings->get($property)['reports'][self::REPORT]['heading']).'.xlsx';
    }
}
