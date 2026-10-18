<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\CityLedgerAccount;
use App\Models\CityLedgerEntry;
use App\Models\Guest;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * A guest's company paying the bill: the guest's company is a City Ledger account (Add/Update
 * Company); a booking with "Bill To Guest Company" carries that account. The company's share
 * (the room bill, the extras, or both) moves to the account when the booking checks out, the
 * invoice is made out to the company and falls due after the account's payment terms. The
 * account's credit limit is checked when the booking is saved and again at checkout.
 */
class CompanyBilling
{
    public function __construct(private CityLedger $cityLedger) {}

    /**
     * The account a booking bills, from its billing preferences and guests: the first guest
     * whose company is a City Ledger account of the booking's property.
     *
     * @param  list<int>  $guestIds  the booking's guests, primary guest first
     */
    public function accountFor(int $propertyId, bool $billRoom, bool $billExtras, array $guestIds): ?CityLedgerAccount
    {
        if ((! $billRoom && ! $billExtras) || $guestIds === []) {
            return null;
        }

        $accountIds = Guest::query()->whereIn('id', $guestIds)->whereNotNull('city_ledger_account_id')->pluck('city_ledger_account_id', 'id');

        foreach ($guestIds as $guestId) {
            $account = isset($accountIds[$guestId])
                ? CityLedgerAccount::query()->where('property_id', $propertyId)->where('is_active', true)->whereKey($accountIds[$guestId])->first()
                : null;

            if ($account !== null) {
                return $account;
            }
        }

        return null;
    }

    /**
     * What the company takes on: the room bill (the booking's total), what was charged on top
     * of it (extras: add-ons, POS, other charges), or both.
     */
    public function companyShare(Booking $booking): float
    {
        $roomBill = round((float) $booking->total, 2);
        $charged = round((float) Transaction::query()
            ->whereHas('subFolio', fn (Builder $query) => $query->whereIn('folio_id', $booking->allFoliosQuery()->select('id')))
            ->where('transaction_type', TransactionType::Debit)
            ->sum('amount'), 2);
        $extras = round(max(0.0, $charged - $roomBill), 2);

        return round(($booking->bill_room_to_guest_company ? $roomBill : 0.0) + ($booking->bill_extras_to_guest_company ? $extras : 0.0), 2);
    }

    /**
     * What is still to move to the company: its share less what is already on the account for
     * this booking, never more than the booking owes.
     */
    public function dueToCompany(Booking $booking): float
    {
        if ($booking->city_ledger_account_id === null) {
            return 0.0;
        }

        $billed = (float) CityLedgerEntry::query()
            ->where('city_ledger_account_id', $booking->city_ledger_account_id)
            ->where('booking_id', $booking->id)
            ->where('entry_type', CityLedgerEntry::CHARGE)
            ->sum('amount');

        return round(max(0.0, min($booking->outstandingBalance(), $this->companyShare($booking) - $billed)), 2);
    }

    /**
     * Refuses a booking whose company share would take the account over its credit limit.
     *
     * @throws ValidationException
     */
    public function ensureCredit(CityLedgerAccount $account, float $amount, string $field = 'bill_room_to_guest_company'): void
    {
        if ($account->credit_limit === null || $amount <= 0) {
            return;
        }

        $owed = $account->balance();

        if ($owed + $amount > (float) $account->credit_limit + 0.005) {
            throw ValidationException::withMessages([$field => "{$account->name} would go over its credit limit of ".number_format((float) $account->credit_limit, 2)
                .' (it owes '.number_format($owed, 2).', this bill adds '.number_format($amount, 2).').']);
        }
    }

    /**
     * At checkout: the company's share moves to its account. A refusal (credit limit, closed
     * account) leaves the bill on the folio and is logged for the front desk.
     */
    public function billOnCheckout(Booking $booking, ?User $user): ?CityLedgerEntry
    {
        $booking->loadMissing('cityLedgerAccount');
        $account = $booking->cityLedgerAccount;
        $amount = $this->dueToCompany($booking);

        if ($account === null || $amount <= 0) {
            return null;
        }

        try {
            return $this->cityLedger->transferBooking($account, $booking, $amount, $user);
        } catch (ValidationException $exception) {
            if ($user !== null) {
                ActivityLog::create([
                    'user_id' => $user->id,
                    'property_id' => $booking->property_id,
                    'booking_id' => $booking->id,
                    'guest_id' => $booking->guest_id,
                    'action' => "Booking {$booking->booking_ref_id}: could not bill {$account->name} at checkout: ".collect($exception->errors())->flatten()->first(),
                    'date' => now(),
                ]);
            }

            return null;
        }
    }

    /**
     * What each booking still owes the account, oldest charges paid first (the same FIFO as
     * the ageing).
     *
     * @return array<int, float> booking id => open amount
     */
    public function openByBooking(CityLedgerAccount $account): array
    {
        $entries = $account->entries()->orderBy('entry_date')->orderBy('id')->get(['id', 'amount', 'booking_id', 'entry_date']);
        $credit = round(-(float) $entries->where('amount', '<', 0)->sum('amount'), 2);
        $open = [];

        foreach ($entries->where('amount', '>', 0) as $charge) {
            $settled = min((float) $charge->amount, $credit);
            $credit = round($credit - $settled, 2);
            $left = round((float) $charge->amount - $settled, 2);

            if ($charge->booking_id !== null && $left > 0) {
                $open[$charge->booking_id] = round(($open[$charge->booking_id] ?? 0.0) + $left, 2);
            }
        }

        return $open;
    }

    /**
     * Open company amounts for many bookings at once, grouped by their accounts: pass the
     * bookings, or their invoices (which carry the booking's account).
     *
     * @param  Collection<int, Booking>|Collection<int, Invoice>|Collection<int, object{id: int, city_ledger_account_id: int|null}>  $bookings
     * @return array<int, float> booking id => open amount
     */
    public function openForBookings(Collection $bookings): array
    {
        $open = [];

        foreach ($bookings->pluck('city_ledger_account_id')->filter()->unique() as $accountId) {
            $account = CityLedgerAccount::query()->whereKey($accountId)->first();
            $open += $account !== null ? $this->openByBooking($account) : [];
        }

        return $open;
    }
}
