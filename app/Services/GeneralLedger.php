<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\GlAccountType;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\Booking;
use App\Models\BookingPaymentMapping;
use App\Models\ChargeCode;
use App\Models\CompanyProfile;
use App\Models\GlAccount;
use App\Models\GlMapping;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OutletBill;
use App\Models\Payment;
use App\Models\Property;
use App\Models\SubBooking;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The double-entry general ledger. Each property has a chart of accounts; every folio entry
 * becomes a balanced journal entry in the same database transaction: a charge debits Guest
 * Ledger (accounts receivable) and credits revenue or tax, a payment debits the account of its
 * payment mode and credits the Guest Ledger. The folio ledger stays the guest sub-ledger, so
 * the Guest Ledger account equals the property's folio balances.
 *
 * Other money movements post their own entries: credit notes (tax back out of Tax Payable),
 * city ledger payments and adjustments, gateway commission.
 *
 * Accrual on top of that, trued up per booking (see trueUpBooking) and per receipt:
 *  - the room bill is billed in full when booked, so it credits Unearned Room Revenue; each
 *    night that passes moves its share into Room Revenue (and its discount into Discounts);
 *  - money paid on a booking that hasn't arrived is held in Advance Deposits until check-in;
 *  - a receipt not (fully) tagged to a booking sits in Unallocated Receipts until it is;
 *  - GST is split into CGST / SGST / UTGST / IGST payable, the way the invoice splits it.
 */
class GeneralLedger
{
    /**
     * The chart every property starts with: system key => [code, name, type].
     *
     * @var array<string, array{0: string, 1: string, 2: GlAccountType}>
     */
    public const SYSTEM_ACCOUNTS = [
        'cash' => ['1000', 'Cash', GlAccountType::Asset],
        'bank' => ['1010', 'Bank', GlAccountType::Asset],
        'card_clearing' => ['1020', 'Card Clearing', GlAccountType::Asset],
        'gateway_clearing' => ['1030', 'Online Gateway Clearing', GlAccountType::Asset],
        'guest_ar' => ['1100', 'Guest Ledger (Receivable)', GlAccountType::Asset],
        'city_ledger_ar' => ['1110', 'City Ledger (Receivable)', GlAccountType::Asset],
        'tds_receivable' => ['1200', 'TDS Receivable', GlAccountType::Asset],
        'suspense' => ['1900', 'Suspense', GlAccountType::Asset],
        'advance_deposits' => ['2000', 'Advance Deposits & Vouchers', GlAccountType::Liability],
        'unallocated_receipts' => ['2010', 'Unallocated Receipts', GlAccountType::Liability],
        'unearned_room_revenue' => ['2050', 'Unearned Room Revenue', GlAccountType::Liability],
        'tax_payable' => ['2100', 'Tax Payable', GlAccountType::Liability],
        'cgst_payable' => ['2110', 'CGST Payable', GlAccountType::Liability],
        'sgst_payable' => ['2120', 'SGST Payable', GlAccountType::Liability],
        'utgst_payable' => ['2130', 'UTGST Payable', GlAccountType::Liability],
        'igst_payable' => ['2140', 'IGST Payable', GlAccountType::Liability],
        'room_revenue' => ['4000', 'Room Revenue', GlAccountType::Revenue],
        'fnb_revenue' => ['4100', 'Food & Beverage Revenue', GlAccountType::Revenue],
        'other_revenue' => ['4200', 'Other Revenue', GlAccountType::Revenue],
        'discounts' => ['4900', 'Discounts & Allowances', GlAccountType::Revenue],
        'sales_returns' => ['4910', 'Sales Returns (Credit Notes)', GlAccountType::Revenue],
        'commission_expense' => ['5000', 'Payment Gateway Commission', GlAccountType::Expense],
        'bad_debts' => ['5100', 'Bad Debts', GlAccountType::Expense],
    ];

    /**
     * Where the system charge codes post. Null: not journalled (a transfer between two folio
     * windows stays inside the Guest Ledger). Payments and refunds post by payment mode.
     *
     * @var array<string, string|null>
     */
    private const CHARGE_CODE_ACCOUNTS = [
        ChargeCode::ROOM => 'unearned_room_revenue',
        ChargeCode::TAX => 'tax_payable',
        ChargeCode::DISCOUNT => 'unearned_room_revenue',
        ChargeCode::ADJUSTMENT => 'unearned_room_revenue',
        ChargeCode::TRANSFER => null,
        ChargeCode::POS_FNB => 'fnb_revenue',
        ChargeCode::POS_FNB_TAX => 'tax_payable',
        ChargeCode::POS_OTHER => 'other_revenue',
        ChargeCode::POS_OTHER_TAX => 'tax_payable',
        ChargeCode::ADDON => 'other_revenue',
        ChargeCode::ADDON_TAX => 'tax_payable',
        ChargeCode::CREDIT_NOTE => 'sales_returns',
        ChargeCode::CITY_LEDGER => 'city_ledger_ar',
    ];

    /**
     * The payable account each GST component's tax goes to.
     *
     * @var array<string, string>
     */
    private const GST_ACCOUNTS = [
        'CGST' => 'cgst_payable',
        'SGST' => 'sgst_payable',
        'UTGST' => 'utgst_payable',
        'IGST' => 'igst_payable',
    ];

    /** @var array<int, array<string, int>> property id => system key => account id */
    private array $chart = [];

    /** @var array<int, array<string, int>> property id => "type:key" => account id */
    private array $mappings = [];

    /**
     * The property's account for a system key, creating the chart on first use.
     */
    public function account(int $propertyId, string $systemKey): int
    {
        if (! isset($this->chart[$propertyId])) {
            $this->chart[$propertyId] = $this->ensureChart($propertyId);
        }

        return $this->chart[$propertyId][$systemKey] ?? throw new InvalidArgumentException("No system account {$systemKey}.");
    }

    /**
     * Creates the property's system accounts that are missing.
     *
     * @return array<string, int> system key => account id
     */
    public function ensureChart(int $propertyId): array
    {
        $existing = GlAccount::query()->where('property_id', $propertyId)->whereNotNull('system_key')->pluck('id', 'system_key');

        foreach (self::SYSTEM_ACCOUNTS as $key => [$code, $name, $type]) {
            if (! $existing->has($key)) {
                $taken = GlAccount::query()->where('property_id', $propertyId)->where('code', $code)->exists();
                $existing[$key] = GlAccount::query()->create([
                    'property_id' => $propertyId,
                    'code' => $taken ? "{$code}-S" : $code,
                    'name' => $name,
                    'type' => $type,
                    'system_key' => $key,
                ])->id;
            }
        }

        return $existing->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Posts a balanced entry. Lines: [account id, debit, credit, memo?, transaction id?].
     *
     * @param  list<array{0: int, 1: float, 2: float, 3?: string|null, 4?: int|null}>  $lines
     *
     * @throws InvalidArgumentException when the debits don't equal the credits
     */
    public function post(int $propertyId, array $lines, ?string $description = null, ?Model $source = null, ?CarbonImmutable $businessDate = null): JournalEntry
    {
        $lines = array_values(array_filter($lines, fn (array $line): bool => round($line[1], 2) > 0 || round($line[2], 2) > 0));
        $debits = round(array_sum(array_column($lines, 1)), 2);
        $credits = round(array_sum(array_column($lines, 2)), 2);

        if ($lines === [] || abs($debits - $credits) >= 0.005) {
            throw new InvalidArgumentException("A journal entry must balance: debits {$debits}, credits {$credits}.");
        }

        return DB::transaction(function () use ($propertyId, $lines, $description, $source, $businessDate): JournalEntry {
            $entry = JournalEntry::query()->create([
                'property_id' => $propertyId,
                'business_date' => ($businessDate ?? CarbonImmutable::parse(Property::query()->findOrFail($propertyId)->today()))->toDateString(),
                'posted_at' => now(),
                'description' => $description === null ? null : mb_substr($description, 0, 500),
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create([
                    'gl_account_id' => $line[0],
                    'debit' => round($line[1], 2),
                    'credit' => round($line[2], 2),
                    'memo' => $line[3] ?? null,
                    'transaction_id' => $line[4] ?? null,
                ]);
            }

            return $entry;
        });
    }

    /**
     * Journals a folio entry: the Guest Ledger against the account its charge code (or, for a
     * payment or refund, its payment mode) posts to. Null when it isn't journalled: a transfer
     * between folio windows, an entry already journalled, or a folio with no property.
     */
    public function journalTransaction(Transaction $transaction): ?JournalEntry
    {
        if (JournalLine::query()->where('transaction_id', $transaction->id)->exists()) {
            return null;
        }

        $transaction->loadMissing(['subFolio.folio.owner', 'chargeCode', 'payment']);
        $propertyId = $this->propertyIdOf($transaction);

        if ($propertyId === null) {
            return null;
        }

        $counter = $this->counterAccount($propertyId, $transaction);
        $receivable = $this->account($propertyId, 'guest_ar');

        if ($counter === null || $counter === $receivable) {
            return null;
        }

        $amount = (float) $transaction->amount;
        $isCharge = $transaction->transaction_type === TransactionType::Debit;
        $memo = $transaction->description;

        // Tax splits into the GST accounts the way the invoice splits it.
        $counterLines = $counter === $this->account($propertyId, 'tax_payable')
            ? $this->taxSplit($propertyId, $amount, $this->bookingOf($transaction))
            : [[$counter, $amount]];

        $lines = [[$receivable, $isCharge ? $amount : 0.0, $isCharge ? 0.0 : $amount, $memo, $transaction->id]];

        foreach ($counterLines as [$account, $share]) {
            $lines[] = [$account, $isCharge ? 0.0 : $share, $isCharge ? $share : 0.0, $memo, $transaction->id];
        }

        // On the property's calendar day the folio entry was posted.
        $property = Property::query()->findOrFail($propertyId);
        $businessDate = CarbonImmutable::parse($property->localTime($transaction->posted_at ?? now())->toDateString());

        return $this->post($propertyId, $lines, $transaction->description ?: $transaction->chargeCode?->name, null, $businessDate);
    }

    /**
     * Tax split over the GST payable accounts by the property's room tax components (CGST,
     * SGST, UTGST, IGST): in the booking's own proportions when given, else evenly. Without
     * GST components it all stays in Tax Payable. The last share takes the rounding.
     *
     * @return list<array{0: int, 1: float}> [account id, amount]
     */
    public function taxSplit(int $propertyId, float $amount, ?Booking $booking = null): array
    {
        $prints = app(BookingPrints::class);
        $property = $booking->property ?? Property::query()->find($propertyId);
        $names = $property === null ? [] : $prints->taxComponentNames($property);
        $gstNames = array_values(array_filter($names, fn (string $name): bool => array_key_exists(strtoupper($name), self::GST_ACCOUNTS)));

        if ($gstNames === [] || $amount <= 0) {
            return [[$this->account($propertyId, 'tax_payable'), round($amount, 2)]];
        }

        $weights = array_fill_keys($names, 1.0);

        if ($booking !== null && (float) $booking->taxes > 0) {
            $byComponent = $prints->taxByComponent($booking, $names);

            if (array_sum($byComponent) > 0) {
                $weights = $byComponent;
            }
        }

        $totalWeight = array_sum($weights);
        $shares = [];
        $left = round($amount, 2);
        $keys = array_keys($weights);

        foreach ($keys as $index => $name) {
            $key = self::GST_ACCOUNTS[strtoupper($name)] ?? 'tax_payable';
            $share = $index === count($keys) - 1 ? $left : round($amount * $weights[$name] / $totalWeight, 2);
            $left = round($left - $share, 2);
            $account = $this->account($propertyId, $key);
            $shares[$account] = round(($shares[$account] ?? 0.0) + $share, 2);
        }

        return array_values(collect($shares)->map(fn (float $share, int $account): array => [$account, $share])->all());
    }

    /**
     * The booking a folio entry belongs to (none for an outlet bill or company folio).
     */
    public function bookingOf(Transaction $transaction): ?Booking
    {
        $owner = $transaction->subFolio?->folio?->owner;

        return match (true) {
            $owner instanceof SubBooking => $owner->booking,
            $owner instanceof Booking => $owner,
            default => null,
        };
    }

    /**
     * Brings a booking's accruals up to date: the room bill earned for each night through
     * $earnedThrough (all of it once the stay is over: checked out, cancelled or a no-show)
     * moves from Unearned Room Revenue to Room Revenue and Discounts; money paid while the
     * guest hasn't arrived moves from the Guest Ledger to Advance Deposits, and back on arrival.
     * Posts only the difference to what earlier true-ups posted, so it can run any number of times.
     */
    public function trueUpBooking(Booking $booking, ?CarbonImmutable $earnedThrough = null): void
    {
        if (in_array($booking->status, BookingStatus::enquiryStatuses(), true)) {
            return;
        }

        $propertyId = $booking->property_id;
        $property = $booking->property ?? Property::query()->findOrFail($propertyId);
        $today = CarbonImmutable::parse($property->today());
        $earnedThrough ??= $today->subDay();

        $entryIds = Transaction::query()
            ->whereHas('subFolio', fn (Builder $window) => $window->whereIn('folio_id', $booking->allFoliosQuery()->select('id')))
            ->pluck('id');

        $unearned = $this->account($propertyId, 'unearned_room_revenue');
        $billed = JournalLine::query()
            ->where('journal_lines.gl_account_id', $unearned)
            ->whereIn('journal_lines.transaction_id', $entryIds)
            ->join('transactions', 'transactions.id', '=', 'journal_lines.transaction_id')
            ->selectRaw('transactions.charge_code_id, COALESCE(SUM(journal_lines.credit), 0) - COALESCE(SUM(journal_lines.debit), 0) as net')
            ->groupBy('transactions.charge_code_id')
            ->pluck('net', 'charge_code_id');

        $discountCode = ChargeCode::system(ChargeCode::DISCOUNT)->id;
        $billedDiscount = round(-(float) ($billed[$discountCode] ?? 0), 2);
        $billedRoom = round((float) $billed->except([$discountCode])->sum(), 2);

        $fraction = $this->earnedFraction($booking, $earnedThrough);
        $recognisedRoom = $this->sourcedNet($booking, $this->account($propertyId, 'room_revenue'), creditNormal: true);
        $recognisedDiscount = $this->sourcedNet($booking, $this->account($propertyId, 'discounts'), creditNormal: false);
        $roomDifference = round($billedRoom * $fraction - $recognisedRoom, 2);
        $discountDifference = round($billedDiscount * $fraction - $recognisedDiscount, 2);

        if (abs($roomDifference) >= 0.01 || abs($discountDifference) >= 0.01) {
            $roomRevenue = $this->account($propertyId, 'room_revenue');
            $discounts = $this->account($propertyId, 'discounts');

            $this->post($propertyId, [
                [$unearned, max(0.0, $roomDifference), max(0.0, -$roomDifference)],
                [$roomRevenue, max(0.0, -$roomDifference), max(0.0, $roomDifference)],
                [$discounts, max(0.0, $discountDifference), max(0.0, -$discountDifference)],
                [$unearned, max(0.0, -$discountDifference), max(0.0, $discountDifference)],
            ], "Room revenue earned, booking {$booking->booking_ref_id}", $booking, $earnedThrough->min($today));
        }

        // Advance deposits: what the guest has paid before arriving.
        $preArrival = in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::OnHold, BookingStatus::Pending], true);
        $paid = $preArrival
            ? max(0.0, -Transaction::balanceOf(Transaction::query()->whereIn('id', $entryIds)->whereNotNull('payment_id')))
            : 0.0;
        $deposits = $this->account($propertyId, 'advance_deposits');
        $depositDifference = round($paid - $this->sourcedNet($booking, $deposits, creditNormal: true), 2);

        if (abs($depositDifference) >= 0.01) {
            $receivable = $this->account($propertyId, 'guest_ar');

            $this->post($propertyId, [
                [$receivable, max(0.0, $depositDifference), max(0.0, -$depositDifference)],
                [$deposits, max(0.0, -$depositDifference), max(0.0, $depositDifference)],
            ], $depositDifference > 0 ? "Advance deposit held, booking {$booking->booking_ref_id}" : "Advance deposit applied, booking {$booking->booking_ref_id}", $booking, $today);
        }
    }

    /**
     * Brings the accruals of the property's current and recently ended stays up to date
     * through a business date (the night audit and the daily scheduler call this).
     *
     * @return int how many bookings were looked at
     */
    public function recogniseRevenue(Property $property, CarbonImmutable $earnedThrough): int
    {
        $count = 0;

        Booking::query()
            ->where('property_id', $property->id)
            ->whereNotIn('status', BookingStatus::enquiryStatuses())
            ->whereDate('check_in', '<=', $earnedThrough->toDateString())
            ->where(fn (Builder $query) => $query
                ->whereDate('check_out', '>=', $earnedThrough->subDays(7)->toDateString())
                ->orWhere('updated_at', '>=', $earnedThrough->subDays(7)))
            ->with('property')
            ->chunkById(200, function (Collection $bookings) use ($earnedThrough, &$count): void {
                foreach ($bookings as $booking) {
                    DB::transaction(fn () => $this->trueUpBooking($booking, $earnedThrough));
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Keeps a receipt's untagged part in Unallocated Receipts: the money is in (its payment
     * mode's account) but not yet on any folio. Tagging it to a booking moves it to the folio
     * (and the Guest Ledger); cancelling it takes it out. City ledger payments post their own
     * entry, refunds aren't held here.
     */
    public function trueUpReceipt(Payment $payment): void
    {
        if ($payment->collection_source === 'City Ledger') {
            return;
        }

        $tagged = (float) BookingPaymentMapping::query()->where('payment_id', $payment->id)->sum('tagged_amount');
        $untagged = $payment->type === 'receipt' && $payment->status === PaymentStatus::Received
            ? max(0.0, round((float) $payment->total_txn_amount - $tagged, 2))
            : 0.0;

        $holding = $this->account($payment->property_id, 'unallocated_receipts');
        $posted = round((float) JournalLine::query()
            ->where('gl_account_id', $holding)
            ->whereHas('entry', fn (Builder $entry) => $entry->where('source_type', $payment->getMorphClass())->where('source_id', $payment->id))
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as net')
            ->value('net'), 2);
        $difference = round($untagged - $posted, 2);

        if (abs($difference) < 0.01) {
            return;
        }

        $modeAccount = $this->paymentModeAccount($payment->property_id, $payment->payment_mode);

        $this->post($payment->property_id, [
            [$modeAccount, max(0.0, $difference), max(0.0, -$difference)],
            [$holding, max(0.0, -$difference), max(0.0, $difference)],
        ], $difference > 0 ? "Receipt {$payment->receipt_number} not yet tagged to a booking" : "Receipt {$payment->receipt_number} tagged or cancelled", $payment);
    }

    /**
     * Advance deposits held per booking by the true-ups: the Guest Ledger carries them on top
     * of the folio balances.
     */
    public function depositsHeld(int $propertyId): float
    {
        return round((float) JournalLine::query()
            ->where('gl_account_id', $this->account($propertyId, 'advance_deposits'))
            ->whereHas('entry', fn (Builder $entry) => $entry->where('source_type', (new Booking)->getMorphClass()))
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as net')
            ->value('net'), 2);
    }

    /**
     * The share of the stay earned through a date: nights passed / nights, all of it once the
     * stay is over (checked out, cancelled, no-show).
     */
    private function earnedFraction(Booking $booking, CarbonImmutable $earnedThrough): float
    {
        if (in_array($booking->status, [BookingStatus::CheckedOut, BookingStatus::Cancelled, BookingStatus::NoShow], true)) {
            return 1.0;
        }

        $checkIn = CarbonImmutable::parse($booking->check_in->toDateString());
        $nights = max(1, BookingPricing::nights($booking->check_in->toDateString(), $booking->check_out->toDateString()));
        $passed = $earnedThrough->lt($checkIn) ? 0 : (int) $checkIn->diffInDays($earnedThrough) + 1;

        return min($nights, $passed) / $nights;
    }

    /**
     * What the booking's own true-ups have put on an account, in its normal direction.
     */
    private function sourcedNet(Booking $booking, int $accountId, bool $creditNormal): float
    {
        $net = (float) JournalLine::query()
            ->where('gl_account_id', $accountId)
            ->whereHas('entry', fn (Builder $entry) => $entry->where('source_type', $booking->getMorphClass())->where('source_id', $booking->id))
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
            ->value('net');

        return round($creditNormal ? -$net : $net, 2);
    }

    /**
     * The account a folio entry posts against (the other side being the Guest Ledger).
     */
    public function counterAccount(int $propertyId, Transaction $transaction): ?int
    {
        $code = $transaction->chargeCode;

        if ($code === null) {
            return $this->account($propertyId, 'suspense');
        }

        if (in_array($code->code, [ChargeCode::PAYMENT, ChargeCode::REFUND], true) || $code->category->value === 'payment') {
            return $this->paymentModeAccount($propertyId, $transaction->payment?->payment_mode);
        }

        if ($mapped = $this->mapped($propertyId, GlMapping::CHARGE_CODE, $code->code)) {
            return $mapped;
        }

        if (array_key_exists($code->code, self::CHARGE_CODE_ACCOUNTS)) {
            $key = self::CHARGE_CODE_ACCOUNTS[$code->code];

            return $key === null ? null : $this->account($propertyId, $key);
        }

        // A property's own charge codes post by their category.
        return match ($code->category->value) {
            'transfer' => null,
            'fnb' => $this->account($propertyId, 'fnb_revenue'),
            'incidentals' => $this->account($propertyId, 'other_revenue'),
            default => $this->account($propertyId, 'room_revenue'),
        };
    }

    /**
     * The account money in a payment mode lands in (or, for commission, TDS and the like, the
     * account it is booked to).
     */
    public function paymentModeAccount(int $propertyId, ?PaymentMode $mode): int
    {
        if ($mode !== null && ($mapped = $this->mapped($propertyId, GlMapping::PAYMENT_MODE, $mode->value))) {
            return $mapped;
        }

        return $this->account($propertyId, self::defaultPaymentModeKey($mode));
    }

    public static function defaultPaymentModeKey(?PaymentMode $mode): string
    {
        return match ($mode) {
            PaymentMode::Cash, PaymentMode::HOCash => 'cash',
            PaymentMode::CreditCard, PaymentMode::DebitCard, PaymentMode::AmExCard => 'card_clearing',
            PaymentMode::Online, PaymentMode::RazorPay, PaymentMode::AirPay, PaymentMode::AUBPaymate, PaymentMode::ECollect, PaymentMode::PaymentWallet => 'gateway_clearing',
            PaymentMode::UPI, PaymentMode::QRCode, PaymentMode::NEFT, PaymentMode::RTGS, PaymentMode::IMPS, PaymentMode::NetBanking,
            PaymentMode::BankTransfer, PaymentMode::BankDeposit, PaymentMode::ChequeDD, PaymentMode::USSD, PaymentMode::MoMo, PaymentMode::BillsPayment => 'bank',
            PaymentMode::Commission => 'commission_expense',
            PaymentMode::TDS => 'tds_receivable',
            PaymentMode::BadDebts => 'bad_debts',
            PaymentMode::CreditNote => 'sales_returns',
            PaymentMode::DebitNote => 'other_revenue',
            PaymentMode::GiftCard, PaymentMode::GiftCertificate, PaymentMode::LoyaltyRewards => 'advance_deposits',
            PaymentMode::OTA => 'city_ledger_ar',
            default => 'suspense',
        };
    }

    /**
     * Each account's debits, credits and balance (in its normal direction) up to the date.
     * The debits and credits of all accounts are equal.
     *
     * @return array{rows: list<array<string, mixed>>, total_debit: float, total_credit: float}
     */
    public function trialBalance(int $propertyId, CarbonImmutable $asOf): array
    {
        $this->ensureChart($propertyId);

        $sums = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.property_id', $propertyId)
            ->whereDate('journal_entries.business_date', '<=', $asOf->toDateString())
            ->groupBy('journal_lines.gl_account_id')
            ->selectRaw('journal_lines.gl_account_id, SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')
            ->get()
            ->keyBy('gl_account_id');

        $rows = array_values(GlAccount::query()->where('property_id', $propertyId)->orderBy('code')->get()
            ->map(function (GlAccount $account) use ($sums): array {
                $debit = round((float) ($sums[$account->id]->debit ?? 0), 2);
                $credit = round((float) ($sums[$account->id]->credit ?? 0), 2);
                $net = round($debit - $credit, 2);

                return [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type->value,
                    'debit' => $debit,
                    'credit' => $credit,
                    // The trial balance's two columns: a net debit or a net credit.
                    'balance_debit' => $net > 0 ? $net : 0.0,
                    'balance_credit' => $net < 0 ? -$net : 0.0,
                    'balance' => $account->type->isDebitNormal() ? $net : -$net,
                ];
            })
            ->all());

        return [
            'rows' => $rows,
            'total_debit' => round(array_sum(array_column($rows, 'balance_debit')), 2),
            'total_credit' => round(array_sum(array_column($rows, 'balance_credit')), 2),
        ];
    }

    /**
     * An account's lines over a period with the balance brought forward and running balance.
     *
     * @return array{opening: float, closing: float, lines: list<array<string, mixed>>}
     */
    public function statement(GlAccount $account, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sign = $account->type->isDebitNormal() ? 1 : -1;
        $base = fn () => JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.gl_account_id', $account->id);

        $openingRow = $base()->whereDate('journal_entries.business_date', '<', $from->toDateString())
            ->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as debit, COALESCE(SUM(journal_lines.credit), 0) as credit')
            ->first();
        $opening = round($sign * ((float) $openingRow->debit - (float) $openingRow->credit), 2);
        $running = $opening;

        $lines = array_values($base()
            ->whereDate('journal_entries.business_date', '>=', $from->toDateString())
            ->whereDate('journal_entries.business_date', '<=', $to->toDateString())
            ->orderBy('journal_entries.business_date')
            ->orderBy('journal_lines.id')
            ->limit(2000)
            ->get(['journal_lines.*', 'journal_entries.business_date', 'journal_entries.description as entry_description'])
            ->map(function (JournalLine $line) use (&$running, $sign): array {
                $running = round($running + $sign * ((float) $line->debit - (float) $line->credit), 2);

                return [
                    'id' => $line->id,
                    'journal_entry_id' => $line->journal_entry_id,
                    'business_date' => CarbonImmutable::parse($line->getAttribute('business_date'))->toDateString(),
                    'description' => $line->memo ?: $line->getAttribute('entry_description'),
                    'debit' => (float) $line->debit,
                    'credit' => (float) $line->credit,
                    'balance' => $running,
                ];
            })
            ->all());

        return ['opening' => $opening, 'closing' => $running, 'lines' => $lines];
    }

    /**
     * The Guest Ledger account against the property's folio balances: equal once every folio
     * entry is journalled (see the journal backfill command).
     *
     * The Guest Ledger also carries the advance deposits moved out of it (see trueUpBooking),
     * so those are taken off before comparing.
     *
     * @return array{gl_balance: float, deposits_held: float, folio_balance: float, difference: float, unjournalled: int}
     */
    public function receivableControl(int $propertyId): array
    {
        $receivable = $this->account($propertyId, 'guest_ar');
        $glRow = JournalLine::query()->where('gl_account_id', $receivable)
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')->first();
        $glBalance = round((float) $glRow->debit - (float) $glRow->credit, 2);
        $depositsHeld = $this->depositsHeld($propertyId);

        // Transfers between windows net to nothing and aren't journalled; they are left out.
        $transferCodes = ChargeCode::query()
            ->where(fn ($query) => $query->where('code', ChargeCode::TRANSFER)->whereNull('property_id'))
            ->orWhere(fn ($query) => $query->where('category', 'transfer')->whereNotNull('property_id'))
            ->pluck('id');
        $folioEntries = $this->propertyTransactions($propertyId)->whereNotIn('charge_code_id', $transferCodes);
        $folioBalance = Transaction::balanceOf(clone $folioEntries);

        return [
            'gl_balance' => $glBalance,
            'deposits_held' => $depositsHeld,
            'folio_balance' => $folioBalance,
            'difference' => round($glBalance - $depositsHeld - $folioBalance, 2),
            'unjournalled' => (clone $folioEntries)->whereNotExists(fn ($query) => $query->selectRaw('1')->from('journal_lines')->whereColumn('journal_lines.transaction_id', 'transactions.id'))->count(),
        ];
    }

    /**
     * The property's folio entries (bookings, their rooms, outlet bills, its companies).
     *
     * @return Builder<Transaction>
     */
    public function propertyTransactions(int $propertyId): Builder
    {
        return Transaction::query()->whereHas('subFolio.folio', fn (Builder $folio) => $folio
            ->whereHasMorph('owner', [SubBooking::class], fn (Builder $stay) => $stay->whereHas('booking', fn (Builder $booking) => $booking->where('property_id', $propertyId)))
            ->orWhereHasMorph('owner', [Booking::class, OutletBill::class, CompanyProfile::class], fn (Builder $owner) => $owner->where('property_id', $propertyId)));
    }

    /**
     * The property whose books a folio entry belongs in.
     */
    public function propertyIdOf(Transaction $transaction): ?int
    {
        $owner = $transaction->subFolio?->folio?->owner;

        return match (true) {
            $owner instanceof SubBooking => $owner->booking?->property_id,
            $owner instanceof Booking, $owner instanceof OutletBill, $owner instanceof CompanyProfile => $owner->property_id,
            default => null,
        };
    }

    /**
     * Forgets the cached chart and mappings (after the chart or mappings change).
     */
    public function forget(): void
    {
        $this->chart = [];
        $this->mappings = [];
    }

    /**
     * @return Collection<int, GlAccount>
     */
    public function accounts(int $propertyId): Collection
    {
        $this->ensureChart($propertyId);

        return GlAccount::query()->where('property_id', $propertyId)->orderBy('code')->get();
    }

    private function mapped(int $propertyId, string $type, string $key): ?int
    {
        $this->mappings[$propertyId] ??= GlMapping::query()->where('property_id', $propertyId)->get()
            ->mapWithKeys(fn (GlMapping $mapping): array => ["{$mapping->mapping_type}:{$mapping->key}" => (int) $mapping->gl_account_id])
            ->all();

        return $this->mappings[$propertyId]["{$type}:{$key}"] ?? null;
    }
}
