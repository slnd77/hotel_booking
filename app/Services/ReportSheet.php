<?php

namespace App\Services;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * The one-sheet report layout (Occupied Rooms, Daily Transaction, House Keeping…): the
 * title on row 2, a coloured summary bar on row 3, bold blue headers on row 4, then rows.
 */
class ReportSheet
{
    public const SUMMARY_COLOR = '48CA10';

    /**
     * @param  array{title: string, summary: string, headers: list<string>, rows: list<list<mixed>>}  $report
     */
    public static function workbook(string $sheetTitle, array $report): Spreadsheet
    {
        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($report['headers'])));

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        // Sheet titles can't contain these and are capped at 31 characters.
        $sheet = $spreadsheet->getActiveSheet()->setTitle(Str::limit(str_replace(['\\', '/', '?', '*', '[', ']', ':'], '', $sheetTitle), 31, ''));
        $sheet->getRowDimension(1)->setRowHeight(50);

        $sheet->setCellValue('A2', $report['title'])->mergeCells("A2:{$lastColumn}2");
        $sheet->getStyle('A2')->getFont()->setSize(15);

        $sheet->setCellValue('A3', $report['summary'])->mergeCells("A3:{$lastColumn}3");
        $sheet->getStyle('A3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::SUMMARY_COLOR);

        $sheet->fromArray($report['headers'], null, 'A4');
        $sheet->getStyle("A4:{$lastColumn}4")->getFont()->setBold(true)->getColor()->setRGB('0000FF');
        $sheet->getStyle("A4:{$lastColumn}4")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        if ($report['rows'] !== []) {
            $sheet->fromArray($report['rows'], null, 'A5', true);
            $sheet->getStyle("A5:{$lastColumn}".(4 + count($report['rows'])))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
        }

        foreach (range(1, count($report['headers'])) as $columnIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
        }

        return $spreadsheet;
    }

    /**
     * Several tables down one sheet (GSTR-3B, Financial Transaction Summary): the title on
     * row 1, then each table's title bar, header row and rows, a blank row between tables.
     *
     * @param  list<array{title: string, headers: list<string>, rows: list<list<mixed>>}>  $tables
     */
    public static function stackedWorkbook(string $sheetTitle, string $title, array $tables, int $firstColumnWidth = 40): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(11);
        $sheet = $spreadsheet->getActiveSheet()->setTitle(Str::limit(str_replace(['\\', '/', '?', '*', '[', ']', ':'], '', $sheetTitle), 31, ''));
        $widest = max(1, ...array_map(fn (array $table): int => count($table['headers']), $tables));

        $sheet->setCellValue('A1', $title)->mergeCells('A1:'.Coordinate::stringFromColumnIndex($widest).'1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $row = 3;

        foreach ($tables as $table) {
            $lastColumn = Coordinate::stringFromColumnIndex(count($table['headers']));

            $sheet->setCellValue("A{$row}", $table['title'])->mergeCells("A{$row}:{$lastColumn}{$row}");
            $sheet->getStyle("A{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0070C0');
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $row++;

            $sheet->fromArray($table['headers'], null, "A{$row}", true);
            $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F8CBAD');
            $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getFont()->setBold(true);
            $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
            $row++;

            foreach ($table['rows'] as $cells) {
                $sheet->fromArray(array_map(fn (mixed $value): mixed => is_float($value) && floor($value) == $value ? (int) $value : $value, $cells), null, "A{$row}", true);
                $row++;
            }

            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth($firstColumnWidth);
        $sheet->getStyle("A3:A{$row}")->getAlignment()->setWrapText(true);

        foreach (range(2, $widest) as $columnIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setWidth(18);
        }

        return $spreadsheet;
    }

    /**
     * The columns not hidden under Select Columns, in catalogue order.
     *
     * @param  list<string>  $hidden
     * @return list<string>
     */
    public static function visibleColumns(string $report, array $hidden): array
    {
        return array_keys(array_diff_key(ReportSettings::COLUMNS[$report], array_flip($hidden)));
    }

    /**
     * Numbers stay numeric for Excel; zero shows as "--".
     */
    public static function amount(int|float $value): int|float|string
    {
        if (round((float) $value, 2) == 0.0) {
            return '--';
        }

        return floor((float) $value) == (float) $value ? (int) $value : round((float) $value, 2);
    }
}
