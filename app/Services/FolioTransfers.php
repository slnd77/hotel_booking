<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Booking;
use App\Models\ChargeCode;
use App\Models\Folio;
use App\Models\SubBooking;
use App\Models\SubFolio;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a window's balance — an amount due or a credit — to another window of the same
 * booking (another room's folio or the master folio). The two entries cancel out, so the
 * booking's total balance doesn't change; the ledger sync leaves them alone (they're
 * neither room-bill codes nor receipts).
 */
class FolioTransfers
{
    public function __construct(private Ledger $ledger) {}

    /**
     * @throws ValidationException when the windows or amount don't allow the transfer
     */
    public function transfer(Booking $booking, SubFolio $from, SubFolio $to, float $amount): void
    {
        $amount = round($amount, 2);
        $windowIds = SubFolio::query()->whereIn('folio_id', $booking->allFoliosQuery()->select('id'))->pluck('id');

        if (! $windowIds->contains($from->id) || ! $windowIds->contains($to->id)) {
            throw ValidationException::withMessages(['to_sub_folio_id' => 'Both folios must belong to this booking.']);
        }

        if ($from->is($to)) {
            throw ValidationException::withMessages(['to_sub_folio_id' => 'Choose a different folio to transfer to.']);
        }

        $balance = round($from->balance(), 2);

        if ($balance === 0.0) {
            throw ValidationException::withMessages(['amount' => 'This folio is settled; there is nothing to transfer.']);
        }

        if ($amount <= 0 || $amount > abs($balance)) {
            throw ValidationException::withMessages(['amount' => 'Transfer between 0.01 and '.number_format(abs($balance), 2).'.']);
        }

        $isDue = $balance > 0;
        $code = ChargeCode::system(ChargeCode::TRANSFER);
        $fromLabel = $this->label($from);
        $toLabel = $this->label($to);

        DB::transaction(function () use ($from, $to, $amount, $isDue, $code, $fromLabel, $toLabel): void {
            // An amount due leaves as a credit and arrives as a debit; a credit the other way round.
            $this->ledger->postEntry($from, $isDue ? TransactionType::Credit : TransactionType::Debit, $amount, $code, ($isDue ? 'Amount due' : 'Credit')." transferred to {$toLabel}");
            $this->ledger->postEntry($to, $isDue ? TransactionType::Debit : TransactionType::Credit, $amount, $code, ($isDue ? 'Amount due' : 'Credit')." transferred from {$fromLabel}");
        });
    }

    /**
     * "Room 101 · Deluxe" for a room's folio, "Master folio" for the booking's first one,
     * "Folio #12" for one added later; " — Window B" when the folio has several windows.
     */
    public function label(SubFolio $window): string
    {
        $folio = $window->folio;
        $owner = $folio->owner;
        $suffix = $folio->subFolios()->count() > 1 ? " — {$window->window_name}" : '';

        if (! $owner instanceof SubBooking) {
            $isMaster = Folio::query()->whereMorphedTo('owner', $owner)->min('id') === $folio->id;

            return ($isMaster ? 'Master folio' : "Folio #{$folio->id}").$suffix;
        }

        return trim(($owner->inventoryUnit->name ?? 'Unassigned room').' · '.($owner->roomCategory->name ?? ''), ' ·').$suffix;
    }
}
