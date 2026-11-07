<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\BookingLedgerSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookings:sync-ledger {--booking=* : Only these booking ids} {--check : Report drift without writing anything}')]
#[Description('Backfill ledger folios from existing bookings and receipts, and report where the ledger balance differs from the stored outstanding amount')]
class SyncBookingLedger extends Command
{
    public function handle(BookingLedgerSync $sync): int
    {
        $query = Booking::query()
            ->when($this->option('booking'), fn ($query, array $ids) => $query->whereKey($ids))
            ->orderBy('id');

        $drifting = [];
        $synced = 0;

        $query->each(function (Booking $booking) use ($sync, &$drifting, &$synced): void {
            if (! $this->option('check')) {
                $sync->sync($booking);
                $synced++;
            }

            // Quotes have no ledger, so there's nothing to compare.
            if (in_array($booking->status, BookingStatus::enquiryStatuses(), true)) {
                return;
            }

            $drift = $sync->drift($booking->fresh());

            if (abs($drift) >= 0.01) {
                $drifting[] = [
                    $booking->id,
                    $booking->booking_ref_id,
                    $booking->status->label(),
                    number_format((float) $booking->outstanding, 2),
                    number_format($booking->outstandingBalance(), 2),
                    number_format($drift, 2),
                ];
            }
        });

        if (! $this->option('check')) {
            $this->info("Synced {$synced} booking(s).");
        }

        if ($drifting === []) {
            $this->info('Every ledger balance matches the stored outstanding amount.');

            return self::SUCCESS;
        }

        $this->warn(count($drifting).' booking(s) where the ledger balance differs from the stored outstanding amount:');
        $this->table(['ID', 'Reference', 'Status', 'Stored outstanding', 'Ledger balance', 'Difference'], $drifting);

        return self::FAILURE;
    }
}
