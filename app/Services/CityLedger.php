<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\ChargeCode;
use App\Models\CityLedgerAccount;
use App\Models\CityLedgerEntry;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The city ledger: bills moved off guests' folios onto company and agency accounts, and what
 * those accounts pay. Transferring a booking's balance credits its folio (so the guest owes
 * nothing) and charges the account, due after its payment terms; the account's credit limit
 * caps what it may owe. Payments are receipts like any other (Payments database, cashier
 * report). Ageing settles credits against the oldest charges first.
 */
class CityLedger
{
    /** Ageing buckets by days past due. */
    public const BUCKETS = ['current' => 'Not yet due', 'days_1_30' => '1–30 days', 'days_31_60' => '31–60 days', 'days_61_90' => '61–90 days', 'days_90_plus' => '90+ days'];

    public function __construct(private Ledger $ledger, private BookingLedgerSync $ledgerSync) {}

    /**
     * Moves what the booking owes (or part of it) to the account.
     *
     * @throws ValidationException when the booking owes less, the account is closed or over its limit
     */
    public function transferBooking(CityLedgerAccount $account, Booking $booking, ?float $amount, ?User $user): CityLedgerEntry
    {
        $entry = DB::transaction(function () use ($account, $booking, $amount, $user): CityLedgerEntry {
            $account = CityLedgerAccount::query()->lockForUpdate()->with('property.currency')->findOrFail($account->id);
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
            $owed = round(max(0.0, $booking->outstandingBalance()), 2);
            $amount = round($amount ?? $owed, 2);

            if ($booking->property_id !== $account->property_id || in_array($booking->status, BookingStatus::enquiryStatuses(), true)) {
                throw ValidationException::withMessages(['booking_ref_id' => 'This booking can\'t be billed to this account.']);
            }

            if (! $account->is_active) {
                throw ValidationException::withMessages(['booking_ref_id' => 'This account is closed.']);
            }

            if ($amount <= 0 || $amount > $owed) {
                throw ValidationException::withMessages(['amount' => $owed > 0 ? 'At most '.number_format($owed, 2).' is owed on this booking.' : 'This booking owes nothing.']);
            }

            if ($account->credit_limit !== null && $account->balance() + $amount > (float) $account->credit_limit) {
                throw ValidationException::withMessages(['amount' => 'This would take the account over its credit limit of '.number_format((float) $account->credit_limit, 2).' (it owes '.number_format($account->balance(), 2).').']);
            }

            $transaction = $this->ledger->postEntry(
                $this->ledger->defaultWindowFor($booking, $account->property->currency?->code),
                TransactionType::Credit,
                $amount,
                ChargeCode::system(ChargeCode::CITY_LEDGER),
                "Billed to {$account->name} (city ledger)",
            );
            $today = CarbonImmutable::parse($account->property->today());

            return $account->entries()->create([
                'property_id' => $account->property_id,
                'entry_type' => CityLedgerEntry::CHARGE,
                'entry_date' => $today,
                'due_date' => $today->addDays($account->payment_terms_days),
                'amount' => $amount,
                'booking_id' => $booking->id,
                'transaction_id' => $transaction->id,
                'reference' => $booking->booking_ref_id,
                'description' => 'Bill of booking '.$booking->booking_ref_id,
                'created_by' => $user?->id,
            ]);
        });

        $this->ledgerSync->sync($booking);
        $this->log($account, $user, "Booking {$booking->booking_ref_id}: ".number_format((float) $entry->amount, 2)." billed to city ledger account {$account->name}.");

        return $entry;
    }

    /**
     * Money received from the account: a receipt, and a credit on the account.
     */
    public function recordPayment(CityLedgerAccount $account, float $amount, PaymentMode $mode, ?string $reference, User $user): CityLedgerEntry
    {
        $entry = DB::transaction(function () use ($account, $amount, $mode, $reference, $user): CityLedgerEntry {
            $account = CityLedgerAccount::query()->lockForUpdate()->with('property')->findOrFail($account->id);
            $amount = round($amount, 2);

            $payment = Payment::create([
                'property_id' => $account->property_id,
                'type' => 'receipt',
                'receipt_number' => Payment::nextReceiptNumber($account->property_id),
                'guest_name' => $account->name,
                'payee_name' => $account->name,
                'payment_mode' => $mode,
                'total_txn_amount' => $amount,
                'tagged_amount' => 0,
                'untagged_amount' => $amount,
                'payment_creation_date' => now(),
                'payment_transaction_date' => now(),
                'status' => PaymentStatus::Received,
                'payment_ref_id' => $reference,
                'collection_source' => 'City Ledger',
                'booking_source' => 'City Ledger',
                'collected_by_staff' => $user->name,
                'notes' => "City ledger payment from {$account->name}",
            ]);

            $generalLedger = app(GeneralLedger::class);
            $generalLedger->post($account->property_id, [
                [$generalLedger->paymentModeAccount($account->property_id, $mode), $amount, 0.0],
                [$generalLedger->account($account->property_id, 'city_ledger_ar'), 0.0, $amount],
            ], "City ledger payment from {$account->name}", $payment);

            return $account->entries()->create([
                'property_id' => $account->property_id,
                'entry_type' => CityLedgerEntry::PAYMENT,
                'entry_date' => $account->property->today(),
                'amount' => -$amount,
                'payment_id' => $payment->id,
                'reference' => $reference ?: $payment->receipt_number,
                'description' => 'Payment received ('.$mode->label().')',
                'created_by' => $user->id,
            ]);
        });

        $this->log($account, $user, 'Payment of '.number_format($amount, 2)." received from city ledger account {$account->name}.");

        return $entry;
    }

    /**
     * A manual correction: positive adds to what the account owes, negative is a credit
     * (write-off, discount agreed with the company).
     */
    public function adjust(CityLedgerAccount $account, float $amount, string $description, User $user): CityLedgerEntry
    {
        $entry = DB::transaction(fn (): CityLedgerEntry => $this->postAdjustment($account, $amount, $description, $user));

        $this->log($account, $user, 'Adjustment of '.number_format($amount, 2)." on city ledger account {$account->name}: {$description}");

        return $entry;
    }

    /**
     * A debit adds to Other Revenue; a credit is booked to Discounts & Allowances.
     */
    private function postAdjustment(CityLedgerAccount $account, float $amount, string $description, User $user): CityLedgerEntry
    {
        $generalLedger = app(GeneralLedger::class);
        $receivable = $generalLedger->account($account->property_id, 'city_ledger_ar');
        $other = $generalLedger->account($account->property_id, $amount > 0 ? 'other_revenue' : 'discounts');
        $value = round(abs($amount), 2);

        $entry = $account->entries()->create([
            'property_id' => $account->property_id,
            'entry_type' => CityLedgerEntry::ADJUSTMENT,
            'entry_date' => $account->property->today(),
            'due_date' => $amount > 0 ? CarbonImmutable::parse($account->property->today())->addDays($account->payment_terms_days) : null,
            'amount' => round($amount, 2),
            'description' => $description,
            'created_by' => $user->id,
        ]);

        $generalLedger->post($account->property_id, $amount > 0
            ? [[$receivable, $value, 0.0], [$other, 0.0, $value]]
            : [[$other, $value, 0.0], [$receivable, 0.0, $value]], "City ledger adjustment, {$account->name}: {$description}", $entry);

        return $entry;
    }

    /**
     * What the account owes as of the date, aged by days past due: credits settle the oldest
     * charges first (FIFO); what remains of each charge falls in its bucket.
     *
     * @return array{balance: float, overdue: float, buckets: array<string, float>}
     */
    public function ageing(CityLedgerAccount $account, CarbonImmutable $asOf): array
    {
        $entries = $account->entries()->whereDate('entry_date', '<=', $asOf->toDateString())->orderBy('entry_date')->orderBy('id')->get();
        $credit = round(-(float) $entries->where('amount', '<', 0)->sum('amount'), 2);
        $buckets = array_fill_keys(array_keys(self::BUCKETS), 0.0);

        foreach ($entries->where('amount', '>', 0) as $charge) {
            $open = (float) $charge->amount;
            $settled = min($open, $credit);
            $credit = round($credit - $settled, 2);
            $open = round($open - $settled, 2);

            if ($open <= 0) {
                continue;
            }

            $daysPastDue = (int) CarbonImmutable::parse(($charge->due_date ?? $charge->entry_date)->toDateString())->diffInDays($asOf, false);
            $buckets[match (true) {
                $daysPastDue <= 0 => 'current',
                $daysPastDue <= 30 => 'days_1_30',
                $daysPastDue <= 60 => 'days_31_60',
                $daysPastDue <= 90 => 'days_61_90',
                default => 'days_90_plus',
            }] += $open;
        }

        $buckets = array_map(fn (float $amount): float => round($amount, 2), $buckets);
        $balance = round((float) $entries->sum('amount'), 2);

        return [
            'balance' => $balance,
            'overdue' => round(array_sum($buckets) - $buckets['current'], 2),
            'buckets' => $buckets,
        ];
    }

    /**
     * The account's statement for a period: the balance brought forward, each entry with the
     * running balance, and the closing balance.
     *
     * @return array{opening: float, closing: float, entries: list<array<string, mixed>>}
     */
    public function statement(CityLedgerAccount $account, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $opening = round((float) $account->entries()->whereDate('entry_date', '<', $from->toDateString())->sum('amount'), 2);
        $running = $opening;

        $entries = $account->entries()
            ->whereDate('entry_date', '>=', $from->toDateString())
            ->whereDate('entry_date', '<=', $to->toDateString())
            ->with(['booking:id,booking_ref_id', 'payment:id,receipt_number', 'creator:id,name'])
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get()
            ->map(function (CityLedgerEntry $entry) use (&$running): array {
                $running = round($running + (float) $entry->amount, 2);

                return [
                    'id' => $entry->id,
                    'entry_type' => $entry->entry_type,
                    'entry_date' => $entry->entry_date->toDateString(),
                    'due_date' => $entry->due_date?->toDateString(),
                    'reference' => $entry->reference,
                    'description' => $entry->description,
                    'booking_id' => $entry->booking_id,
                    'booking_ref_id' => $entry->booking?->booking_ref_id,
                    'receipt_number' => $entry->payment?->receipt_number,
                    'debit' => (float) $entry->amount > 0 ? (float) $entry->amount : 0.0,
                    'credit' => (float) $entry->amount < 0 ? -(float) $entry->amount : 0.0,
                    'balance' => $running,
                    'created_by' => $entry->creator?->name,
                ];
            })
            ->values()
            ->all();

        return ['opening' => $opening, 'closing' => $running, 'entries' => $entries];
    }

    /**
     * Each account of the property with its balance, credit left and ageing.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function accountsSummary(int $propertyId, CarbonImmutable $asOf): Collection
    {
        return CityLedgerAccount::query()
            ->where('property_id', $propertyId)
            ->orderBy('name')
            ->get()
            ->map(function (CityLedgerAccount $account) use ($asOf): array {
                $ageing = $this->ageing($account, $asOf);

                return [
                    ...$account->only(['id', 'name', 'code', 'account_type', 'agency_id', 'tax_number', 'contact_name', 'email', 'phone', 'address', 'payment_terms_days', 'is_active', 'notes']),
                    'credit_limit' => $account->credit_limit === null ? null : (float) $account->credit_limit,
                    'available_credit' => $account->credit_limit === null ? null : round((float) $account->credit_limit - $ageing['balance'], 2),
                    ...$ageing,
                ];
            });
    }

    private function log(CityLedgerAccount $account, ?User $user, string $action): void
    {
        if ($user === null) {
            return;
        }

        ActivityLog::create(['user_id' => $user->id, 'property_id' => $account->property_id, 'guest_id' => null, 'action' => $action, 'date' => now()]);
    }
}
