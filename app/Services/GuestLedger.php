<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\ChargeCode;
use App\Models\Guest;
use App\Models\GuestLedgerAccount;
use App\Models\SubFolio;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Guest ledgers: a booking's credit moves onto the guest's ledger at the property, and a
 * later booking of the guest takes it back off. Each move is a pair of balance transfers
 * (one on the booking's master folio, one on the ledger's folio) that cancel out, so no
 * money is created or lost and the ledger sync leaves them alone.
 */
class GuestLedger
{
    public function __construct(private Ledger $ledger, private BookingLedgerSync $ledgerSync) {}

    public function account(int $propertyId, Guest $guest, ?User $user = null): GuestLedgerAccount
    {
        return GuestLedgerAccount::query()->firstOrCreate(
            ['property_id' => $propertyId, 'guest_id' => $guest->id],
            ['created_by' => $user?->id],
        );
    }

    /**
     * What the property holds for the guest (0 when there is no ledger yet).
     */
    public function balance(int $propertyId, ?Guest $guest): float
    {
        $account = $guest ? GuestLedgerAccount::query()->where('property_id', $propertyId)->where('guest_id', $guest->id)->first() : null;

        return $account ? $this->accountBalance($account) : 0.0;
    }

    public function accountBalance(GuestLedgerAccount $account): float
    {
        return round(max(0.0, -Transaction::balanceOf($this->entriesQuery($account))), 2);
    }

    /**
     * The ledger's entries, latest first: each move in or out.
     *
     * @return list<array{id: int, at: string, description: string|null, credit: float, debit: float}>
     */
    public function statement(GuestLedgerAccount $account, int $limit = 20): array
    {
        return array_values($this->entriesQuery($account)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Transaction $entry): array => [
                'id' => $entry->id,
                'at' => $entry->posted_at->toIso8601ZuluString(),
                'description' => $entry->description,
                'credit' => $entry->transaction_type === TransactionType::Credit ? (float) $entry->amount : 0.0,
                'debit' => $entry->transaction_type === TransactionType::Debit ? (float) $entry->amount : 0.0,
            ])
            ->all());
    }

    /**
     * Moves a booking's credit onto its guest's ledger.
     *
     * @throws ValidationException when the booking has no guest or not that much credit
     */
    public function depositFromBooking(Booking $booking, float $amount, User $user): float
    {
        $guest = $this->guestOf($booking);
        $amount = round($amount, 2);
        $credit = round(max(0.0, -$booking->outstandingBalance()), 2);

        if ($amount <= 0 || $amount > $credit) {
            throw ValidationException::withMessages(['amount' => 'At most '.number_format($credit, 2).' of credit can move to the guest ledger.']);
        }

        $account = $this->account($booking->property_id, $guest, $user);

        DB::transaction(function () use ($booking, $account, $amount): void {
            $code = ChargeCode::system(ChargeCode::TRANSFER);
            $this->ledger->postEntry($this->bookingWindow($booking), TransactionType::Debit, $amount, $code, 'Credit moved to the guest ledger');
            $this->ledger->postEntry($this->accountWindow($account), TransactionType::Credit, $amount, $code, "Credit from booking {$booking->booking_ref_id}");
        });

        $this->ledgerSync->sync($booking);
        $this->log($booking, $user, number_format($amount, 2)." of credit moved to the guest ledger of {$this->name($guest)}");

        return $amount;
    }

    /**
     * Uses the guest's ledger balance on a booking, up to what the booking owes.
     *
     * @throws ValidationException when the ledger or the booking doesn't allow that much
     */
    public function applyToBooking(Booking $booking, float $amount, User $user): float
    {
        $guest = $this->guestOf($booking);
        $amount = round($amount, 2);
        $available = $this->balance($booking->property_id, $guest);
        $owed = round(max(0.0, $booking->outstandingBalance()), 2);
        $max = min($available, $owed);

        if ($amount <= 0 || $amount > $max) {
            throw ValidationException::withMessages(['amount' => 'Use between 0.01 and '.number_format($max, 2).' (ledger balance '.number_format($available, 2).', owed '.number_format($owed, 2).').']);
        }

        $account = $this->account($booking->property_id, $guest, $user);

        DB::transaction(function () use ($booking, $account, $amount): void {
            $code = ChargeCode::system(ChargeCode::TRANSFER);
            $this->ledger->postEntry($this->accountWindow($account), TransactionType::Debit, $amount, $code, "Used on booking {$booking->booking_ref_id}");
            $this->ledger->postEntry($this->bookingWindow($booking), TransactionType::Credit, $amount, $code, 'Paid from the guest ledger');
        });

        $this->ledgerSync->sync($booking);
        $this->log($booking, $user, number_format($amount, 2)." used from the guest ledger of {$this->name($guest)}");

        return $amount;
    }

    /**
     * @return Builder<Transaction>
     */
    private function entriesQuery(GuestLedgerAccount $account)
    {
        return Transaction::query()->whereHas('subFolio.folio', fn ($query) => $query->whereMorphedTo('owner', $account));
    }

    private function guestOf(Booking $booking): Guest
    {
        return $booking->primaryGuest ?? throw ValidationException::withMessages(['amount' => 'Add a guest to the booking first: the ledger belongs to the guest.']);
    }

    private function bookingWindow(Booking $booking): SubFolio
    {
        return $this->ledger->defaultWindowFor($booking, $booking->property->currency?->code);
    }

    private function accountWindow(GuestLedgerAccount $account): SubFolio
    {
        return $this->ledger->defaultWindowFor($account, $account->property->currency?->code);
    }

    private function name(Guest $guest): string
    {
        return trim("{$guest->first_name} {$guest->last_name}");
    }

    private function log(Booking $booking, User $user, string $action): void
    {
        ActivityLog::create([
            'user_id' => $user->id,
            'property_id' => $booking->property_id,
            'booking_id' => $booking->id,
            'guest_id' => $booking->guest_id,
            'action' => "Booking {$booking->booking_ref_id}: {$action}",
            'date' => now(),
        ]);
    }
}
