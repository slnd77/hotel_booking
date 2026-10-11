<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\FolioStatus;
use App\Enums\OutletBillStatus;
use App\Enums\OutletType;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Enums\SubBookingStatus;
use App\Enums\TransactionType;
use App\Models\ChargeCode;
use App\Models\Outlet;
use App\Models\OutletBill;
use App\Models\OutletBillItem;
use App\Models\OutletItem;
use App\Models\OutletKot;
use App\Models\OutletKotItem;
use App\Models\OutletTable;
use App\Models\Payment;
use App\Models\Property;
use App\Models\SubBooking;
use App\Models\SubFolio;
use App\Models\Transaction;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Point-of-sale billing for outlets. A bill is opened, its items set (priced from the
 * outlet's price plans on the property's business date), then either:
 *
 *  - posted to a room: the sale and its tax are charged on the in-house guest's folio
 *    (following the room's routing rules), optionally with a payment credited there too;
 *  - settled at the outlet: the bill gets its own folio holding the sale, its tax and the
 *    payment(s) that settled it — several when the payers split it;
 *  - or voided: whatever it posted is reversed (and its payment cancelled).
 *
 * While a bill is open its items go to the kitchen on kitchen order tickets (KOTs), each
 * holding what changed since the last one. An open bill can be split: some of its items move
 * to a new bill for the same table, which is then billed on its own.
 */
class OutletBills
{
    public function __construct(private Ledger $ledger, private OutletPricing $pricing, private BookingLedgerSync $ledgerSync, private NightAuditSettings $nightAuditSettings) {}

    /**
     * The business date new bills belong to: with Night Audit → "Pos Roll Over Flag" on, the
     * date the night audit hasn't closed yet (POS rolls over with it); else the property's today.
     */
    public function businessDate(Property $property): string
    {
        return $this->nightAuditSettings->get($property)['pos_rollover']
            ? $this->nightAuditSettings->currentRolloverDate($property)->toDateString()
            : $property->today();
    }

    /**
     * A new open bill of the outlet, numbered after the outlet's last bill.
     *
     * @param  array{guest_name?: string|null, table_number?: string|null, outlet_table_id?: int|null, split_from_id?: int|null, notes?: string|null}  $attributes
     */
    public function open(Outlet $outlet, ?User $user, array $attributes = []): OutletBill
    {
        // A table of the plan names the bill's table.
        $table = filled($attributes['outlet_table_id'] ?? null)
            ? OutletTable::query()->where('outlet_id', $outlet->id)->findOrFail($attributes['outlet_table_id'])
            : null;

        return DB::transaction(function () use ($outlet, $user, $attributes, $table): OutletBill {
            $last = OutletBill::query()->where('outlet_id', $outlet->id)->lockForUpdate()->max('id');
            $number = $last ? (int) OutletBill::query()->whereKey($last)->value('bill_number') + 1 : 1;

            return OutletBill::create([
                'property_id' => $outlet->property_id,
                'outlet_id' => $outlet->id,
                'bill_number' => (string) $number,
                'business_date' => $this->businessDate($outlet->property),
                'status' => OutletBillStatus::Open,
                'guest_name' => $attributes['guest_name'] ?? null,
                'table_number' => $table->name ?? $attributes['table_number'] ?? null,
                'outlet_table_id' => $table?->id,
                'split_from_id' => $attributes['split_from_id'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $user?->id,
            ]);
        });
    }

    /**
     * Moves an open bill to another table of the plan (or off the plan).
     */
    public function moveToTable(OutletBill $bill, ?OutletTable $table): OutletBill
    {
        $this->assertOpen($bill);

        if ($table !== null && $table->outlet_id !== $bill->outlet_id) {
            throw ValidationException::withMessages(['outlet_table_id' => 'That table is not at this outlet.']);
        }

        $bill->update(['outlet_table_id' => $table?->id, 'table_number' => $table?->name]);

        return $bill->refresh();
    }

    /**
     * Splits an open bill: the given quantities of its lines move to a new open bill for the same
     * table (keeping their prices), with a share of the discount in proportion. What the kitchen
     * already has moves with them, on unprinted transfer tickets, so nothing is cooked twice.
     *
     * @param  array<int, int>  $quantities  bill item id => quantity to move
     */
    public function split(OutletBill $bill, array $quantities, ?User $user): OutletBill
    {
        $this->assertOpen($bill);
        $bill->loadMissing(['items', 'outlet.property']);
        $quantities = array_filter(array_map('intval', $quantities), fn (int $quantity): bool => $quantity > 0);
        $moved = [];
        $kept = [];

        foreach ($bill->items as $line) {
            $move = min((int) $line->quantity, $quantities[$line->id] ?? 0);
            $keep = (int) $line->quantity - $move;
            $row = ['outlet_item_id' => $line->outlet_item_id, 'unit_price' => (float) $line->unit_price];

            if ($move > 0) {
                $moved[] = [...$row, 'quantity' => $move];
            }

            if ($keep > 0) {
                $kept[] = [...$row, 'quantity' => $keep];
            }
        }

        if ($moved === [] || $kept === []) {
            throw ValidationException::withMessages(['items' => 'Move some, but not all, of the bill\'s items to the new bill.']);
        }

        return DB::transaction(function () use ($bill, $moved, $kept, $user): OutletBill {
            $movedAmount = array_sum(array_map(fn (array $line): float => $line['unit_price'] * $line['quantity'], $moved));
            $share = (float) $bill->sub_total > 0 ? round((float) $bill->discount * $movedAmount / (float) $bill->sub_total, 2) : 0.0;

            $new = $this->open($bill->outlet, $user, [
                'guest_name' => $bill->guest_name,
                'table_number' => $bill->table_number,
                'outlet_table_id' => $bill->outlet_table_id,
                'split_from_id' => $bill->id,
                'notes' => "Split from bill #{$bill->bill_number}",
            ]);
            // The lines keep the prices they were billed at.
            $this->setItems($new, $moved, $share, mayOverridePrice: true);
            $this->setItems($bill, $kept, round((float) $bill->discount - $share, 2), mayOverridePrice: true);

            $this->transferKitchen($bill, $new, $moved, $user);

            return $new->refresh()->load('items');
        });
    }

    /**
     * Replaces the bill's items. A line's price is the price plan's for the bill's business
     * date unless an override is allowed and given. The discount comes off the items, and the
     * tax on what's left.
     *
     * @param  list<array{outlet_item_id: int, quantity: int, unit_price?: float|int|string|null}>  $lines
     */
    public function setItems(OutletBill $bill, array $lines, float $discount = 0.0, bool $mayOverridePrice = false): OutletBill
    {
        $this->assertOpen($bill);

        return DB::transaction(function () use ($bill, $lines, $discount, $mayOverridePrice): OutletBill {
            $items = OutletItem::query()
                ->with('taxRate')
                ->where('outlet_id', $bill->outlet_id)
                ->whereIn('id', array_column($lines, 'outlet_item_id'))
                ->get()
                ->keyBy('id');

            $rows = [];

            foreach ($lines as $index => $line) {
                $item = $items->get((int) $line['outlet_item_id']);

                if ($item === null || ! $item->status) {
                    throw ValidationException::withMessages(["items.{$index}.outlet_item_id" => 'This item is not sold at this outlet.']);
                }

                $override = $line['unit_price'] ?? null;
                $price = $mayOverridePrice && $override !== null && $override !== ''
                    ? round((float) $override, 2)
                    : $this->pricing->priceFor($item, $bill->business_date->toDateString());

                if ($price === null) {
                    throw ValidationException::withMessages(["items.{$index}.outlet_item_id" => "{$item->name} has no price on ".$bill->business_date->format('d M Y').'. Add it to a price plan.']);
                }

                $quantity = (int) $line['quantity'];
                $amount = round($price * $quantity, 2);
                $taxRate = $item->taxRate && $item->taxRate->status ? (float) $item->taxRate->rate : 0.0;

                $rows[] = [
                    'outlet_item_id' => $item->id,
                    'name' => $item->name,
                    'sac_hsn_code' => $item->sac_hsn_code,
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'amount' => $amount,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $this->pricing->taxFor($item, $amount),
                ];
            }

            $subTotal = round(array_sum(array_column($rows, 'amount')), 2);
            $discount = round($discount, 2);

            if ($discount < 0 || $discount > $subTotal) {
                throw ValidationException::withMessages(['discount' => 'The discount must be between 0 and the bill amount.']);
            }

            // Tax is on the discounted amount: each line's tax shrinks in proportion.
            $ratio = $subTotal > 0 ? ($subTotal - $discount) / $subTotal : 0.0;
            $taxTotal = round(array_sum(array_map(fn (array $row): float => $row['tax_amount'] * $ratio, $rows)), 2);

            $bill->items()->delete();
            $bill->items()->createMany($rows);
            $bill->update([
                'sub_total' => $subTotal,
                'discount' => $discount,
                'tax_total' => $taxTotal,
                'total' => round($subTotal - $discount + $taxTotal, 2),
            ]);

            return $bill->refresh()->load('items');
        });
    }

    /**
     * Charges the bill to an in-house room's folio. The room's routing rules pick the window
     * unless one of the booking's windows is named. A payment taken now (any part of the bill)
     * is credited to the same window.
     *
     * @param  array{payment_mode: PaymentMode, amount: float, reference?: string|null}|null  $payment
     */
    public function postToRoom(OutletBill $bill, SubBooking $room, ?SubFolio $window = null, ?array $payment = null, ?User $user = null): OutletBill
    {
        $this->assertOpen($bill);
        $this->assertHasTotal($bill);

        $booking = $room->booking;

        if ($booking === null || $booking->property_id !== $bill->property_id) {
            throw ValidationException::withMessages(['sub_booking_id' => 'This room is not a booking of this property.']);
        }

        if ($booking->status !== BookingStatus::CheckedIn || $room->status !== SubBookingStatus::CheckedIn) {
            throw ValidationException::withMessages(['sub_booking_id' => 'Only a checked-in room can be charged.']);
        }

        if ($window !== null && ! $booking->allFoliosQuery()->whereKey($window->folio_id)->exists()) {
            throw ValidationException::withMessages(['sub_folio_id' => 'This billing window is not on the booking.']);
        }

        if ($payment !== null && ($payment['amount'] <= 0 || round($payment['amount'], 2) > (float) $bill->total)) {
            throw ValidationException::withMessages(['payment.amount' => 'The payment must be more than 0 and at most the bill total.']);
        }

        return $this->onLedger(function () use ($bill, $room, $booking, $window, $payment, $user): OutletBill {
            [$saleCode, $taxCode] = $this->chargeCodes($bill);
            $description = $this->description($bill);
            $sale = round((float) $bill->sub_total - (float) $bill->discount, 2);

            $charge = $window
                ? $this->ledger->postEntry($window, TransactionType::Debit, $sale, $saleCode, $description, $room, null, $bill)
                : $this->ledger->postCharge($room, $saleCode, $sale, $description, $bill);
            $chargedWindow = $window ?? $charge->subFolio;

            if ((float) $bill->tax_total > 0) {
                $this->ledger->postEntry($chargedWindow, TransactionType::Debit, (float) $bill->tax_total, $taxCode, "{$description} tax", $room, null, $bill);
            }

            $guest = $booking->guests()->first();

            $bill->update([
                'status' => OutletBillStatus::Posted,
                'booking_id' => $booking->id,
                'sub_booking_id' => $room->id,
                'sub_folio_id' => $chargedWindow->id,
                'guest_name' => $bill->guest_name ?: ($guest ? trim("{$guest->first_name} {$guest->last_name}") : null),
                'closed_at' => now(),
            ]);

            if ($payment !== null) {
                $receipt = $this->createPayment($bill, $payment['payment_mode'], round($payment['amount'], 2), $payment['reference'] ?? null, $user, $booking->id);

                app(PaymentDistributionService::class)->creditWindows($booking, $receipt, (float) $receipt->total_txn_amount, [
                    ['sub_folio_id' => $chargedWindow->id, 'amount' => (float) $receipt->total_txn_amount],
                ]);

                $bill->update(['payment_id' => $receipt->id]);
            }

            // What the booking owes now includes the bill.
            $this->ledgerSync->schedule($booking->id);

            return $bill->refresh();
        });
    }

    /**
     * Settles the bill at the outlet: its own folio holds the sale, the tax and the payment.
     */
    public function settle(OutletBill $bill, PaymentMode $mode, ?string $reference = null, ?User $user = null): OutletBill
    {
        return $this->settleSplit($bill, [['payment_mode' => $mode, 'amount' => (float) $bill->total, 'reference' => $reference]], $user);
    }

    /**
     * Settles the bill with several payments (payers splitting it, or part cash and part card).
     * They must add up to the bill's total; each is its own receipt on the bill's folio.
     *
     * @param  list<array{payment_mode: PaymentMode, amount: float, reference?: string|null, payer?: string|null}>  $payments
     */
    public function settleSplit(OutletBill $bill, array $payments, ?User $user = null): OutletBill
    {
        $this->assertOpen($bill);
        $this->assertHasTotal($bill);

        $paid = round(array_sum(array_map(fn (array $payment): float => round((float) $payment['amount'], 2), $payments)), 2);

        if ($payments === [] || collect($payments)->contains(fn (array $payment): bool => round((float) $payment['amount'], 2) <= 0) || abs($paid - (float) $bill->total) >= 0.01) {
            throw ValidationException::withMessages(['payments' => 'The payments must each be more than 0 and add up to the bill total of '.number_format((float) $bill->total, 2).'.']);
        }

        return $this->onLedger(function () use ($bill, $payments, $user): OutletBill {
            [$saleCode, $taxCode] = $this->chargeCodes($bill);
            $description = $this->description($bill);
            $window = $this->ledger->defaultWindowFor($bill, $bill->property->currency?->code);

            $this->ledger->postEntry($window, TransactionType::Debit, round((float) $bill->sub_total - (float) $bill->discount, 2), $saleCode, $description, null, null, $bill);

            if ((float) $bill->tax_total > 0) {
                $this->ledger->postEntry($window, TransactionType::Debit, (float) $bill->tax_total, $taxCode, "{$description} tax", null, null, $bill);
            }

            $receipts = [];

            foreach ($payments as $payment) {
                $receipt = $this->createPayment($bill, $payment['payment_mode'], round((float) $payment['amount'], 2), $payment['reference'] ?? null, $user, null, $payment['payer'] ?? null);
                $this->ledger->postEntry($window, TransactionType::Credit, (float) $receipt->total_txn_amount, ChargeCode::system(ChargeCode::PAYMENT), "Receipt {$receipt->receipt_number}", null, $receipt, $bill);
                $receipts[] = $receipt;
            }

            $window->folio->update(['status' => FolioStatus::Settled]);

            $bill->update(['status' => OutletBillStatus::Settled, 'sub_folio_id' => $window->id, 'payment_id' => $receipts[0]->id, 'closed_at' => now()]);

            return $bill->refresh();
        });
    }

    /**
     * Voids the bill. A posted bill's charges are reversed on the guest's folio and any payment
     * taken with it is cancelled (the ledger sync reverses its credit); a settled bill's own
     * folio is reversed the same way.
     */
    public function void(OutletBill $bill, string $reason): OutletBill
    {
        if ($bill->status === OutletBillStatus::Void) {
            throw ValidationException::withMessages(['bill' => "Bill {$bill->bill_number} is already void."]);
        }

        return $this->onLedger(function () use ($bill, $reason): OutletBill {
            // The kitchen stops cooking what an open bill sent it.
            if ($bill->status === OutletBillStatus::Open) {
                $this->createKot($bill, null, "Bill voided: {$reason}", cancelAll: true);
            }

            if ($bill->status === OutletBillStatus::Settled) {
                $bill->folio?->update(['status' => FolioStatus::Open]);
            }

            if ($bill->status !== OutletBillStatus::Open) {
                $this->reverseEntries($bill);
            }

            // Every receipt the bill took (a split settlement has several).
            Payment::query()
                ->whereIn('id', $bill->transactions()->whereNotNull('payment_id')->pluck('payment_id')->push($bill->payment_id)->filter()->unique())
                ->where('status', '!=', PaymentStatus::Cancelled)
                ->get()
                ->each(fn (Payment $payment) => $payment->update(['status' => PaymentStatus::Cancelled, 'cancellation_reason' => "POS bill {$bill->bill_number} voided: {$reason}"]));

            if ($bill->status === OutletBillStatus::Settled) {
                $bill->folio?->update(['status' => FolioStatus::Settled]);
            }

            if ($bill->booking_id) {
                $this->ledgerSync->schedule($bill->booking_id);
            }

            $bill->update(['status' => OutletBillStatus::Void, 'voided_at' => now(), 'void_reason' => $reason]);

            return $bill->refresh();
        });
    }

    /**
     * Sends the open bill's changes since its last kitchen ticket (new items, more of an item,
     * or fewer — printed as cancellations) on a new ticket.
     *
     * @throws ValidationException when nothing changed
     */
    public function sendKot(OutletBill $bill, ?User $user, ?string $notes = null): OutletKot
    {
        $this->assertOpen($bill);

        return $this->createKot($bill, $user, $notes, cancelAll: false)
            ?? throw ValidationException::withMessages(['bill' => 'Nothing new to send to the kitchen.']);
    }

    /**
     * How many of each item the bill's tickets have sent to the kitchen so far, keyed by
     * kitchenKey(); items cancelled back to none are left out.
     *
     * @return Collection<array-key, array{outlet_item_id: int|null, name: string, quantity: non-zero-int}>
     */
    public function sentToKitchen(OutletBill $bill): Collection
    {
        return OutletKotItem::query()
            ->whereHas('kot', fn ($query) => $query->where('outlet_bill_id', $bill->id))
            ->get()
            ->groupBy(fn (OutletKotItem $line): string => $this->kitchenKey($line->outlet_item_id, $line->name))
            ->map(fn (Collection $lines): array => $this->kitchenLine($lines))
            ->filter(fn (array $line): bool => $line['quantity'] !== 0);
    }

    /**
     * One item's lines (on the bill or sent on tickets) added up.
     *
     * @param  Collection<int, OutletKotItem>|Collection<int, OutletBillItem>  $lines
     * @return array{outlet_item_id: int|null, name: string, quantity: int}
     */
    private function kitchenLine(Collection $lines): array
    {
        $first = $lines->firstOrFail();

        return ['outlet_item_id' => $first->outlet_item_id, 'name' => $first->name, 'quantity' => (int) $lines->sum('quantity')];
    }

    /**
     * A ticket with the difference between the bill's items (none, when cancelling them all)
     * and what was sent before, or null when there's none.
     */
    private function createKot(OutletBill $bill, ?User $user, ?string $notes, bool $cancelAll): ?OutletKot
    {
        return DB::transaction(function () use ($bill, $user, $notes, $cancelAll): ?OutletKot {
            $sent = $this->sentToKitchen($bill);
            $wanted = $cancelAll ? collect() : $bill->items()->get()
                ->groupBy(fn (OutletBillItem $line): string => $this->kitchenKey($line->outlet_item_id, $line->name))
                ->map(fn (Collection $lines): array => $this->kitchenLine($lines));

            $lines = $wanted->keys()->merge($sent->keys())->unique()
                ->map(function (int|string $key) use ($wanted, $sent): array {
                    $line = $wanted->get($key) ?? $sent->get($key);

                    return [...$line, 'quantity' => ($wanted->get($key)['quantity'] ?? 0) - ($sent->get($key)['quantity'] ?? 0)];
                })
                ->filter(fn (array $line): bool => $line['quantity'] !== 0)
                ->values();

            if ($lines->isEmpty()) {
                return null;
            }

            $last = OutletKot::query()->where('outlet_id', $bill->outlet_id)->lockForUpdate()->max('id');
            $number = $last ? (int) OutletKot::query()->whereKey($last)->value('kot_number') + 1 : 1;

            $kot = OutletKot::create([
                'property_id' => $bill->property_id,
                'outlet_id' => $bill->outlet_id,
                'outlet_bill_id' => $bill->id,
                'kot_number' => (string) $number,
                'business_date' => $bill->business_date->toDateString(),
                'notes' => $notes,
                'created_by' => $user?->id,
            ]);
            $kot->items()->createMany($lines->all());

            return $kot->load('items');
        });
    }

    /**
     * Moves what the kitchen already has of the moved lines from one bill's tickets to the
     * other's: a negative ticket on the old bill and a positive one on the new, both marked
     * printed (the kitchen keeps cooking; only the billing moved).
     *
     * @param  list<array{outlet_item_id: int|null, quantity: int}>  $moved
     */
    private function transferKitchen(OutletBill $from, OutletBill $to, array $moved, ?User $user): void
    {
        $sent = $this->sentToKitchen($from);
        // How much of each item earlier moved lines already took from what was sent.
        $taken = [];
        $lines = [];

        foreach ($moved as $line) {
            $key = $this->kitchenKey($line['outlet_item_id'], '');
            $already = $sent->get($key);

            if ($already === null) {
                continue;
            }

            $quantity = min($line['quantity'], $already['quantity'] - ($taken[$key] ?? 0));
            $taken[$key] = ($taken[$key] ?? 0) + $quantity;

            if ($quantity > 0) {
                $lines[] = ['outlet_item_id' => $already['outlet_item_id'], 'name' => $already['name'], 'quantity' => $quantity];
            }
        }

        if ($lines === []) {
            return;
        }

        foreach ([[$from, -1, "Moved to bill #{$to->bill_number}"], [$to, 1, "Moved from bill #{$from->bill_number}"]] as [$bill, $sign, $notes]) {
            $last = OutletKot::query()->where('outlet_id', $bill->outlet_id)->lockForUpdate()->max('id');
            $kot = OutletKot::create([
                'property_id' => $bill->property_id,
                'outlet_id' => $bill->outlet_id,
                'outlet_bill_id' => $bill->id,
                'kot_number' => (string) ($last ? (int) OutletKot::query()->whereKey($last)->value('kot_number') + 1 : 1),
                'business_date' => $bill->business_date->toDateString(),
                'notes' => $notes,
                'printed_at' => now(),
                'created_by' => $user?->id,
            ]);
            $kot->items()->createMany(array_map(fn (array $line): array => [...$line, 'quantity' => $sign * $line['quantity']], $lines));
        }
    }

    private function kitchenKey(?int $outletItemId, string $name): string
    {
        return $outletItemId !== null ? "item:{$outletItemId}" : "name:{$name}";
    }

    /**
     * Each charge the bill posted is credited back on its window. On a settled bill the
     * payment's credit is debited back too; a room's payment is reversed by the ledger sync
     * once the receipt is cancelled.
     */
    private function reverseEntries(OutletBill $bill): void
    {
        $description = 'Void '.$this->description($bill);
        $entries = $bill->transactions()->with(['subFolio.folio', 'chargeCode'])->get();
        $reverseReceipt = $bill->status === OutletBillStatus::Settled;

        foreach ($entries as $entry) {
            if ($entry->payment_id !== null && ! $reverseReceipt) {
                continue;
            }

            $this->ledger->postEntry(
                $entry->subFolio,
                $entry->transaction_type === TransactionType::Debit ? TransactionType::Credit : TransactionType::Debit,
                (float) $entry->amount,
                $entry->chargeCode,
                $description,
                $entry->sourceSubBooking,
                $entry->payment,
                $bill,
            );
        }
    }

    private function createPayment(OutletBill $bill, PaymentMode $mode, float $amount, ?string $reference, ?User $user, ?int $bookingId = null, ?string $payer = null): Payment
    {
        $bill->loadMissing('outlet');

        return Payment::create([
            'property_id' => $bill->property_id,
            'booking_id' => $bookingId,
            'type' => 'receipt',
            'voucher_number' => "{$bill->outlet->name} #{$bill->bill_number}",
            'receipt_number' => Payment::nextReceiptNumber($bill->property_id),
            'guest_name' => $payer ?: ($bill->guest_name ?: 'Walk-in'),
            'payee_name' => $payer ?: 'Guest',
            'payment_mode' => $mode,
            'total_txn_amount' => $amount,
            'tagged_amount' => $amount,
            'untagged_amount' => 0,
            'payment_creation_date' => now(),
            'payment_transaction_date' => now(),
            'status' => PaymentStatus::Received,
            'payment_ref_id' => $reference,
            'collection_source' => 'POS',
            'booking_source' => 'Direct',
            'collected_by_staff' => $user->name ?? 'Staff',
        ]);
    }

    /**
     * Food & beverage outlets post to the F&B codes (routed with F&B charges); the others to
     * the outlet service codes (incidentals).
     *
     * @return array{0: ChargeCode, 1: ChargeCode}
     */
    private function chargeCodes(OutletBill $bill): array
    {
        $isFood = in_array($bill->outlet->type, [OutletType::FoodAndBeverages, OutletType::BanquetHall], true);

        return $isFood
            ? [ChargeCode::system(ChargeCode::POS_FNB), ChargeCode::system(ChargeCode::POS_FNB_TAX)]
            : [ChargeCode::system(ChargeCode::POS_OTHER), ChargeCode::system(ChargeCode::POS_OTHER_TAX)];
    }

    private function description(OutletBill $bill): string
    {
        return "{$bill->outlet->name} bill #{$bill->bill_number}";
    }

    /**
     * Runs a posting in one transaction; a folio that is no longer open fails as a validation error.
     *
     * @param  Closure(): OutletBill  $callback
     */
    private function onLedger(Closure $callback): OutletBill
    {
        try {
            return DB::transaction($callback);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['bill' => $exception->getMessage()]);
        }
    }

    private function assertOpen(OutletBill $bill): void
    {
        if ($bill->status !== OutletBillStatus::Open) {
            throw ValidationException::withMessages(['bill' => "Bill {$bill->bill_number} is {$bill->status->label()}; it can't be changed."]);
        }
    }

    private function assertHasTotal(OutletBill $bill): void
    {
        if ((float) $bill->total <= 0) {
            throw ValidationException::withMessages(['bill' => 'Add at least one item to the bill.']);
        }
    }
}
