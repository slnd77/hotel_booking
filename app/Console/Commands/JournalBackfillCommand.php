<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\Transaction;
use App\Services\GeneralLedger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('ledger:journal-backfill {--property= : Only this property id}')]
#[Description('Journal the folio entries written before the general ledger existed (each entry once; safe to run again), then compare each property\'s Guest Ledger account with its folio balances')]
class JournalBackfillCommand extends Command
{
    public function handle(GeneralLedger $generalLedger): int
    {
        $propertyId = $this->option('property') ? (int) $this->option('property') : null;
        $query = ($propertyId ? $generalLedger->propertyTransactions($propertyId) : Transaction::query())
            ->whereNotIn('id', JournalLine::query()->whereNotNull('transaction_id')->select('transaction_id'));

        $journalled = 0;
        $skipped = 0;
        $failed = 0;
        $properties = [];

        $query->chunkById(500, function ($transactions) use ($generalLedger, &$journalled, &$skipped, &$failed, &$properties): void {
            foreach ($transactions as $transaction) {
                try {
                    $entry = $generalLedger->journalTransaction($transaction);
                } catch (Throwable $exception) {
                    $failed++;
                    $this->error("Transaction {$transaction->id}: {$exception->getMessage()}");

                    continue;
                }

                if ($entry === null) {
                    $skipped++;

                    continue;
                }

                $journalled++;
                $properties[$entry->property_id] = true;
            }
        });

        $this->info("Journalled {$journalled} folio entr(ies); {$skipped} need no journal (transfers between windows, or no property).".($failed ? " {$failed} failed." : ''));

        $bookings = 0;
        Booking::query()->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))->with('property')
            ->chunkById(200, function ($chunk) use ($generalLedger, &$bookings, &$properties): void {
                foreach ($chunk as $booking) {
                    DB::transaction(fn () => $generalLedger->trueUpBooking($booking));
                    $properties[$booking->property_id] = true;
                    $bookings++;
                }
            });

        Payment::query()->when($propertyId, fn ($query) => $query->where('property_id', $propertyId))
            ->chunkById(500, function ($chunk) use ($generalLedger): void {
                foreach ($chunk as $payment) {
                    DB::transaction(fn () => $generalLedger->trueUpReceipt($payment));
                }
            });

        $this->info("Accruals brought up to date for {$bookings} booking(s) and the property's receipts.");

        $rows = collect($propertyId ? [$propertyId] : array_keys($properties))->map(function (int $id) use ($generalLedger): array {
            $control = $generalLedger->receivableControl($id);

            return [$id, number_format($control['gl_balance'], 2), number_format($control['deposits_held'], 2), number_format($control['folio_balance'], 2), number_format($control['difference'], 2), $control['unjournalled']];
        });

        if ($rows->isNotEmpty()) {
            $this->table(['Property', 'Guest Ledger (GL)', 'Of which advance deposits', 'Folio balances', 'Difference', 'Unjournalled entries'], $rows->all());
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
