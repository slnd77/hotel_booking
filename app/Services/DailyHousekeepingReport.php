<?php

namespace App\Services;

use App\Enums\SubBookingStatus;
use App\Models\Property;
use App\Models\Room;
use App\Models\RoomBlock;
use App\Models\SubBooking;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Reports → Daily House Keeping Report: every room on the business date with its
 * housekeeping status, the stay in it (in house or arriving) or leaving it, and blank
 * MUR / DND / Follow-up / Remarks columns for the housekeeper to fill in on the round.
 */
class DailyHousekeepingReport
{
    public const REPORT = 'daily_housekeeping_report';

    private const EMPTY = '--';

    /** Columns left blank for the housekeeper. */
    private const HANDWRITTEN_COLUMNS = ['mur', 'dnd', 'follow_up', 'remarks'];

    public function __construct(private ReportSettings $settings, private NightStays $nightStays, private Housekeeping $housekeeping) {}

    /**
     * @return array{title: string, summary: string, headers: list<string>, rows: list<list<mixed>>}
     */
    public function report(Property $property, CarbonImmutable $date, ?CarbonInterface $downloadedAt = null): array
    {
        $settings = $this->settings->get($property)['reports'][self::REPORT];
        $columns = ReportSheet::visibleColumns(self::REPORT, $settings['hidden_columns']);
        $defaultStatus = $this->housekeeping->statuses($property)->first()['name'] ?? self::EMPTY;
        $staysByRoom = $this->nightStays->touching($property, $date)->filter(fn (SubBooking $stay): bool => $stay->room !== null)->groupBy(fn (SubBooking $stay): int => $stay->room->id);
        $blockedRoomIds = RoomBlock::query()->where('property_id', $property->id)->overlapping($date->toDateString(), $date->addDay()->toDateString())->pluck('room_id')->flip();

        $rooms = Room::query()
            ->whereHas('roomCategory', fn ($query) => $query->where('property_id', $property->id))
            ->with('roomCategory')
            ->get()
            ->sortBy([
                fn (Room $a, Room $b): int => ((int) $a->roomCategory?->display_order) <=> ((int) $b->roomCategory?->display_order),
                fn (Room $a, Room $b): int => strnatcasecmp($a->name, $b->name),
            ]);

        $rows = array_values($rooms->map(function (Room $room) use ($property, $date, $columns, $defaultStatus, $staysByRoom, $blockedRoomIds): array {
            $values = $this->row($property, $date, $room, $staysByRoom->get($room->id, collect()), $blockedRoomIds->has($room->id), $defaultStatus);

            return array_map(fn (string $key): mixed => $values[$key], $columns);
        })->all());

        return [
            'title' => sprintf('%s for %s - %s  Downloaded At %s', $settings['heading'], $property->name, $date->format('M, d Y'), $property->localTime($downloadedAt ?? now())->format('d M, Y H:i')),
            'summary' => sprintf('House Keeping (%d Rooms, %d Occupied)', $rooms->count(), $staysByRoom->filter(fn (Collection $stays): bool => $stays->contains(fn (SubBooking $stay): bool => $this->inHouse($stay, $date)))->count()),
            'headers' => array_map(fn (string $key): string => ReportSettings::COLUMNS[self::REPORT][$key], $columns),
            'rows' => $rows,
        ];
    }

    public function spreadsheet(Property $property, CarbonImmutable $date, ?CarbonInterface $downloadedAt = null): Spreadsheet
    {
        return ReportSheet::workbook('House Keeping', $this->report($property, $date, $downloadedAt));
    }

    public function fileName(Property $property): string
    {
        return Str::studly($this->settings->get($property)['reports'][self::REPORT]['heading']).'.xlsx';
    }

    /**
     * One room's cells, keyed like ReportSettings::COLUMNS. The stay shown is the one in
     * the room that night (in house or arriving), else the one leaving it that day.
     *
     * @param  Collection<int, SubBooking>  $stays
     * @return array<string, mixed>
     */
    private function row(Property $property, CarbonImmutable $date, Room $room, Collection $stays, bool $blocked, string $defaultStatus): array
    {
        $day = $date->toDateString();
        $staying = $stays->first(fn (SubBooking $stay): bool => $this->inHouse($stay, $date));
        $leaving = $stays->first(fn (SubBooking $stay): bool => $stay->check_out_date->toDateString() === $day);
        $stay = $staying ?? $leaving;

        return [
            'room_no' => $room->name,
            'room_category' => $room->roomCategory->name ?? self::EMPTY,
            'room_status' => $room->housekeeping_status ?: $defaultStatus,
            'arrival_date' => $stay?->check_in_date->format('d M, Y') ?? self::EMPTY,
            'departure_date' => $stay?->check_out_date->format('d M, Y') ?? self::EMPTY,
            'time_in' => $staying?->checked_in_at ? $property->localTime($staying->checked_in_at)->format('d M, Y H:i') : self::EMPTY,
            'time_out' => $leaving?->checked_out_at ? $property->localTime($leaving->checked_out_at)->format('d M, Y H:i') : self::EMPTY,
            'check_out' => $leaving ? 'Yes' : self::EMPTY,
            ...array_fill_keys(self::HANDWRITTEN_COLUMNS, ''),
            'room_state' => $this->roomState($date, $staying, $leaving, $blocked),
            'pax' => ReportSheet::amount($stay ? (int) $stay->adults + (int) $stay->children + (int) $stay->infants : 0),
            'adults' => ReportSheet::amount((int) $stay?->adults),
            'children' => ReportSheet::amount((int) $stay?->children),
            'infant' => ReportSheet::amount((int) $stay?->infants),
        ];
    }

    /**
     * Occupied (checked in), Arrival (due in today), Due Out / Checked Out (leaving today),
     * Blocked, or Vacant.
     */
    private function roomState(CarbonImmutable $date, ?SubBooking $staying, ?SubBooking $leaving, bool $blocked): string
    {
        $states = [];

        if ($leaving) {
            $states[] = $leaving->status === SubBookingStatus::CheckedOut ? 'Checked Out' : 'Due Out';
        }

        if ($staying) {
            $states[] = match (true) {
                $staying->status === SubBookingStatus::CheckedIn => 'Occupied',
                $staying->check_in_date->toDateString() === $date->toDateString() => 'Arrival',
                default => 'Reserved',
            };
        }

        if ($states === []) {
            return $blocked ? 'Blocked' : 'Vacant';
        }

        return implode(' / ', $states);
    }

    /**
     * In the room the night of the date (arriving that day or staying over).
     */
    private function inHouse(SubBooking $stay, CarbonImmutable $date): bool
    {
        return $stay->check_in_date->toDateString() <= $date->toDateString() && $stay->check_out_date->toDateString() > $date->toDateString();
    }
}
