<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Property;
use App\Models\SubBooking;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Reports → CheckedOut Folios Modification Report: checked-out bookings changed on the
 * business date (the property's local day) after their last room checked out — the
 * booking edited, or a charge, payment or adjustment posted to its folios.
 */
class CheckedOutFoliosModificationReport
{
    public const REPORT = 'checked_out_folios_modification_report';

    private const EMPTY = '--';

    /** Changes this soon after the checkout are the checkout itself, not a later modification. */
    private const CHECKOUT_GRACE_SECONDS = 60;

    public function __construct(private ReportSettings $settings) {}

    /**
     * @return array{title: string, summary: string, headers: list<string>, rows: list<list<mixed>>}
     */
    public function report(Property $property, CarbonImmutable $date, ?CarbonInterface $downloadedAt = null): array
    {
        $settings = $this->settings->get($property)['reports'][self::REPORT];
        $catalogue = ReportSettings::COLUMNS[self::REPORT];
        $columns = ReportSheet::visibleColumns(self::REPORT, $settings['hidden_columns']);
        $modified = $this->modifiedBookings($property, $date);

        $rows = $modified->values()->map(function (array $entry, int $index) use ($property, $columns): array {
            $values = $this->row($property, $entry['booking'], $entry['checked_out_at'], $entry['modified_at'], $index + 1);

            return array_map(fn (string $key): mixed => $values[$key], $columns);
        })->all();

        return [
            'title' => sprintf('%s for %s - %s  Downloaded At %s', $settings['heading'], $property->name, $date->format('M, d Y'), $property->localTime($downloadedAt ?? now())->format('d M, Y H:i')),
            'summary' => sprintf('Checked Out Folios Modified (%d Bookings)', count($rows)),
            'headers' => array_map(fn (string $key): string => $catalogue[$key], $columns),
            'rows' => $rows,
        ];
    }

    public function spreadsheet(Property $property, CarbonImmutable $date, ?CarbonInterface $downloadedAt = null): Spreadsheet
    {
        return ReportSheet::workbook('Modified Folios', $this->report($property, $date, $downloadedAt));
    }

    public function fileName(Property $property): string
    {
        return Str::studly($this->settings->get($property)['reports'][self::REPORT]['heading']).'.xlsx';
    }

    /**
     * Checked-out bookings with a change on the date after their last checkout, with that
     * checkout and the latest such change, by reservation number.
     *
     * @return Collection<int, array{booking: Booking, checked_out_at: CarbonInterface, modified_at: CarbonInterface}>
     */
    private function modifiedBookings(Property $property, CarbonImmutable $date): Collection
    {
        $dayStart = CarbonImmutable::parse($date->toDateString(), $property->timezoneName())->startOfDay()->utc();
        $dayEnd = $dayStart->addDay();

        $postings = Transaction::query()
            ->where('posted_at', '>=', $dayStart)
            ->where('posted_at', '<', $dayEnd)
            ->with('subFolio.folio.owner')
            ->get(['id', 'sub_folio_id', 'posted_at']);

        // Folios belong to a room (SubBooking) or to the booking (master bill).
        $postedAtByBooking = $postings->groupBy(function (Transaction $transaction): ?int {
            $owner = $transaction->subFolio?->folio?->owner;

            return match (true) {
                $owner instanceof SubBooking => $owner->booking_id,
                $owner instanceof Booking => $owner->id,
                default => null,
            };
        })->map(fn (Collection $transactions): Collection => $transactions->pluck('posted_at'));

        return Booking::query()
            ->where('property_id', $property->id)
            ->where('status', BookingStatus::CheckedOut->value)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('updated_at', '>=', $dayStart)->where('updated_at', '<', $dayEnd))
                ->orWhereIn('id', $postedAtByBooking->keys()->filter()))
            ->with(['rooms.room', 'updatedBy:id,name'])
            ->orderBy('booking_ref_id')
            ->get()
            ->map(function (Booking $booking) use ($postedAtByBooking, $dayStart, $dayEnd): ?array {
                $checkedOutAt = $booking->rooms->pluck('checked_out_at')->filter()->max();

                if ($checkedOutAt === null) {
                    return null;
                }

                $after = $checkedOutAt->copy()->addSeconds(self::CHECKOUT_GRACE_SECONDS);
                $changes = collect($postedAtByBooking->get($booking->id, []))
                    ->push($booking->updated_at)
                    ->filter(fn (?CarbonInterface $at): bool => $at !== null && $at->gt($after) && $at->gte($dayStart) && $at->lt($dayEnd));

                return $changes->isEmpty() ? null : ['booking' => $booking, 'checked_out_at' => $checkedOutAt, 'modified_at' => $changes->max()];
            })
            ->filter()
            ->values();
    }

    /**
     * One booking's cells, keyed like ReportSettings::COLUMNS.
     *
     * @return array<string, mixed>
     */
    private function row(Property $property, Booking $booking, CarbonInterface $checkedOutAt, CarbonInterface $modifiedAt, int $serial): array
    {
        return [
            's_no' => $serial,
            'reservation_no' => $booking->booking_ref_id,
            'reference_no' => self::EMPTY,
            'arrival_date' => $booking->check_in->format('d M, Y'),
            'departure_date' => $booking->check_out->format('d M, Y'),
            'checkout_date' => $property->localTime($checkedOutAt)->format('d M, Y H:i'),
            'last_modified_date' => $property->localTime($modifiedAt)->format('d M, Y H:i'),
            'created_date' => $booking->created_at ? $property->localTime($booking->created_at)->format('d M, Y') : self::EMPTY,
            'modified_by' => $booking->updatedBy?->name ?? self::EMPTY,
            'room_number' => $booking->rooms->map(fn (SubBooking $room): ?string => $room->room?->name)->filter()->implode(',') ?: self::EMPTY,
        ];
    }
}
