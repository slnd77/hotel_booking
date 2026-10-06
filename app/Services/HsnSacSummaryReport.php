<?php

namespace App\Services;

use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Reports → HSN/SAC Summary Report: GSTR-1 table 12 on its own — the range's invoices summed
 * per HSN/SAC and rate, to registered (B2B) and unregistered (B2C) buyers, in the official
 * template's hsn(b2b) and hsn(b2c) sheets, from the same figures as the GSTR-1 download.
 */
class HsnSacSummaryReport
{
    public const REPORT = 'hsn_sac_summary_report';

    public const MAX_DAYS = Gstr1SalesRegisterReport::MAX_DAYS;

    private const SHEETS = ['hsn(b2b)', 'hsn(b2c)'];

    public function __construct(private ReportSettings $settings, private Gstr1SalesRegisterReport $gstr1) {}

    public function spreadsheet(Property $property, CarbonImmutable $from, CarbonImmutable $to): Spreadsheet
    {
        return $this->gstr1->spreadsheet($property, $from, $to, self::SHEETS);
    }

    public function fileName(Property $property): string
    {
        return Str::studly($this->settings->get($property)['reports'][self::REPORT]['heading']).'.xlsx';
    }
}
