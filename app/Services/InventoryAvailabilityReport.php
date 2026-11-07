<?php

namespace App\Services;

use App\Models\Property;
use App\Models\RoomCategory;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * Reports → Inventory Availability Report ("Category Wise Availability" sheet):
 * a row per night in the range with the rooms still free in each category, and the total.
 * Free means not taken by a stay and not blocked (RoomInventory).
 */
class InventoryAvailabilityReport
{
    public const REPORT = 'inventory_availability_report';

    /** Longest range one download covers. */
    public const MAX_DAYS = 92;

    public function __construct(private RoomInventory $inventory) {}

    /**
     * The room categories as columns, then a row per night.
     *
     * @return array{title: string, headers: list<string>, rows: list<list<int|string>>}
     */
    public function report(Property $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $categories = RoomCategory::query()
            ->where('property_id', $property->id)
            ->withCount('rooms')
            ->orderBy('display_order')
            ->get(['id', 'name']);
        // Rooms, or beds for a dormitory.
        $roomsCount = $this->inventory->capacityByCategory($property->id)->only($categories->pluck('id')->all());
        $rows = [];

        foreach (CarbonPeriod::create($from, $to) as $night) {
            $free = $this->inventory->availabilityByCategory($property->id, $roomsCount, $night->toDateString(), $night->copy()->addDay()->toDateString());
            $counts = $categories->map(fn (RoomCategory $category): int => (int) ($free[$category->id] ?? 0))->all();

            $rows[] = [$night->toDateString(), ...$counts, array_sum($counts)];
        }

        return [
            'title' => sprintf('%s - Category Wise Availability - %s - %s', $property->name, $from->format('d-M-Y'), $to->format('d-M-Y')),
            'headers' => ['', ...$categories->map(fn (RoomCategory $category): string => mb_strtoupper($category->name))->all(), 'TOTAL'],
            'rows' => $rows,
        ];
    }

    public function spreadsheet(Property $property, CarbonImmutable $from, CarbonImmutable $to): Spreadsheet
    {
        $report = $this->report($property, $from, $to);
        $lastColumn = Coordinate::stringFromColumnIndex(count($report['headers']));

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        // Sheet titles are capped at 31 characters and can't contain these.
        $sheet->setTitle(mb_substr(str_replace(['\\', '/', '?', '*', '[', ']', ':'], '', $property->name), 0, 31) ?: 'Availability');

        $sheet->setCellValue('A2', $report['title'])->mergeCells("A2:{$lastColumn}2");
        $sheet->getStyle('A2')->getFont()->setSize(15);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $sheet->fromArray($report['headers'], null, 'A3');
        $sheet->getStyle("B3:{$lastColumn}3")->getFont()->setBold(true)->getColor()->setRGB('0000FF');

        if ($report['rows'] !== []) {
            $sheet->fromArray($report['rows'], null, 'A4', true);
            $sheet->getStyle("B3:{$lastColumn}".(3 + count($report['rows'])))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        foreach (range(1, count($report['headers'])) as $columnIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
        }

        return $spreadsheet;
    }

    public function fileName(): string
    {
        return 'InventoryAvailability.xlsx';
    }
}
