<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\BookingPaymentMapping;
use App\Models\ChargeCode;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\SubBooking;
use App\Models\SubFolio;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Keeps the folio ledger in step with the booking's money, which the app still keeps
 * on the booking (room rates, discount, taxes, total) and in receipts (payments):
 *
 *  - each room (sub-booking) bills on its own folio, netting to its share of the total;
 *  - receipts tagged to the booking are credited once, reversed if cancelled.
 *
 * The ledger is the record of what a booking owes: bookings.outstanding is only a
 * stored copy of the ledger balance, written back after every sync.
 *
 * The ledger stays append-only: changes are posted as adjustments/reversals, never edits.
 * Enquiries and expired quotes have no ledger (they aren't receivables).
 */
class BookingLedgerSync
{
    /** @var array<int, true> */
    private array $scheduled = [];

    /** @var list<int>|null */
    private ?array $roomBillCodeIds = null;

    public function __construct(private Ledger $ledger) {}

    /**
     * Syncs a booking once its database transaction commits (immediately when there
     * is none). Several writes to one booking in the same transaction sync it once.
     * A failure is reported but never breaks the legacy write that triggered it.
     */
    public function schedule(?int $bookingId): void
    {
        if (! $bookingId || isset($this->scheduled[$bookingId])) {
            return;
        }

        $this->scheduled[$bookingId] = true;

        DB::afterCommit(function () use ($bookingId): void {
            unset($this->scheduled[$bookingId]);

            try {
                if ($booking = Booking::query()->find($bookingId)) {
                    $this->sync($booking);
                }
            } catch (Throwable $exception) {
                // Tests surface mirror bugs; in the app they're logged and the legacy write stands.
                if (app()->runningUnitTests()) {
                    throw $exception;
                }

                report($exception);
            }
        });
    }

    public function sync(Booking $booking): void
    {
        DB::transaction(function () use ($booking): void {
            $booking = Booking::query()->lockForUpdate()->with(['rooms', 'property.currency'])->findOrFail($booking->id);

            $this->syncPrimaryGuest($booking);

            if (! in_array($booking->status, BookingStatus::enquiryStatuses(), true)) {
                $this->syncLedger($booking, $booking->rooms->keyBy('id'));

                // Owed = ledger balance (an overpayment shows as nothing owed, and under Refunds).
                $booking->forceFill(['outstanding' => max(0.0, $booking->outstandingBalance())])->saveQuietly();

                // The general ledger's accruals follow: nights earned, advance deposits held.
                app(GeneralLedger::class)->trueUpBooking($booking);

                // Checked-out folios with nothing left on them settle; reopened ones settle again.
                app(FolioSettlements::class)->refresh($booking);
            }
        });
    }

    /**
     * A room is being removed from its booking: its folios (and every entry on them)
     * move to the booking, so nothing charged or paid on that room drops out of the
     * balance. The next sync then adjusts the removed room's charges away.
     */
    public function releaseRoomFolios(SubBooking $room): void
    {
        Folio::query()->whereMorphedTo('owner', $room)->update([
            'owner_type' => (new Booking)->getMorphClass(),
            'owner_id' => $room->booking_id,
        ]);
    }

    /**
     * Ledger balance (floored at zero) minus the stored outstanding copy — 0 unless the
     * copy was changed behind the ledger's back.
     */
    public function drift(Booking $booking): float
    {
        return round(max(0.0, $booking->outstandingBalance()) - (float) $booking->outstanding, 2);
    }

    /**
     * Whether the ledger has fallen behind the booking: its room bill doesn't net to
     * the booking total, a receipt isn't credited (or reversed) as tagged, or the stored
     * outstanding copy differs. Happens when a sync failed and was only reported.
     * Enquiries and quotes have no ledger, so they're never behind.
     */
    public function isOutOfStep(Booking $booking): bool
    {
        if (in_array($booking->status, BookingStatus::enquiryStatuses(), true)) {
            return false;
        }

        $roomBill = $this->signedSum(
            Transaction::query()
                ->whereNull('payment_id')
                ->whereIn('charge_code_id', $this->roomBillCodeIds())
                ->whereHas('subFolio', fn ($query) => $query->whereIn('folio_id', $booking->allFoliosQuery()->select('id')))
                ->get(['id', 'transaction_type', 'amount']),
        );

        return abs(round((float) $booking->total - $roomBill, 2)) >= 0.01
            || $this->paymentDifferences($booking, $this->postedPaymentEntries($booking)) !== []
            || abs($this->drift($booking)) >= 0.01;
    }

    private function syncPrimaryGuest(Booking $booking): void
    {
        if ($booking->guest_id === null && ($guestId = $booking->guests()->orderBy('booking_guests.id')->value('guests.id'))) {
            $booking->forceFill(['guest_id' => $guestId])->saveQuietly();
        }
    }

    /**
     * Each room bills on its own folio (its own invoice); the booking's master folio
     * holds whatever isn't tied to a room.
     *
     * @param  Collection<int, SubBooking>  $subBookings
     */
    private function syncLedger(Booking $booking, Collection $subBookings): void
    {
        $currency = $booking->property?->currency?->code;
        $masterWindow = $this->ledger->defaultWindowFor($booking, $currency);

        $roomWindows = collect();

        foreach ($subBookings as $room) {
            $roomWindows[$room->id] = $this->ledger->defaultWindowFor($room, $currency);
        }

        $this->syncCharges($booking, $subBookings, $roomWindows, $masterWindow);
        $this->syncAddons($booking, $masterWindow);
        $this->syncPayments($booking, $roomWindows->values(), $masterWindow);
        $this->applyMasterCreditToRooms($roomWindows->values(), $masterWindow);
    }

    /**
     * Each room's window nets to its share of the bill: rate × nights, less its share of
     * the discount, plus its share of the taxes (shares in proportion to the room amount).
     * The first sync itemises a window; later changes post one adjustment for the
     * difference. The master folio(s) net to anything left (normally nothing).
     *
     * @param  Collection<int, SubBooking>  $subBookings
     * @param  Collection<int, SubFolio>  $roomWindows  keyed by sub-booking id
     */
    private function syncCharges(Booking $booking, Collection $subBookings, Collection $roomWindows, SubFolio $masterWindow): void
    {
        $shares = $this->roomShares($booking);

        foreach ($roomWindows as $roomId => $window) {
            $share = $shares[$roomId];
            $source = $subBookings[$roomId];
            $chargeEntries = $this->windowEntries($window)->whereNull('payment_id')->whereIn('charge_code_id', $this->roomBillCodeIds());

            if ($chargeEntries->isEmpty()) {
                $this->postSigned($window, $share['room'], ChargeCode::ROOM, "Room charge, {$share['nights']} night(s)", $source);
                $this->postSigned($window, $share['tax'], ChargeCode::TAX, 'Room taxes', $source);
                $this->postSigned($window, -$share['discount'], ChargeCode::DISCOUNT, 'Discount', $source);
                $this->postSigned($window, $share['adjustment'], ChargeCode::ADJUSTMENT, 'Difference to booking total', $source);

                continue;
            }

            $this->postSigned($window, round($share['total'] - $this->signedSum($chargeEntries), 2), ChargeCode::ADJUSTMENT, 'Booking amended', $source);
        }

        // Master folios (including those of rooms since removed) net to what isn't on a room.
        $masterTarget = round((float) $booking->total - $roomWindows->keys()->sum(fn (int $id): float => $shares[$id]['total']), 2);
        $masterCharges = $this->signedSum(
            Transaction::query()
                ->whereNull('payment_id')
                ->whereIn('charge_code_id', $this->roomBillCodeIds())
                ->whereHas('subFolio.folio', fn ($query) => $query->whereMorphedTo('owner', $booking))
                ->get(['id', 'transaction_type', 'amount', 'payment_id']),
        );
        $difference = round($masterTarget - $masterCharges, 2);

        $this->postSigned($masterWindow, $difference, ChargeCode::ADJUSTMENT, $masterTarget === 0.0 && $masterCharges > 0 ? 'Charges moved to room folios' : 'Booking amended');
    }

    /**
     * Each add-on's charge and tax on the master folio net to the line as it stands: nothing
     * once it is removed or the booking is cancelled or a no-show. A change posts the
     * difference, linked to the add-on, wherever the earlier entries now sit.
     */
    private function syncAddons(Booking $booking, SubFolio $masterWindow): void
    {
        $voided = in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::NoShow], true);
        $codeIds = [ChargeCode::system(ChargeCode::ADDON)->id => ChargeCode::ADDON, ChargeCode::system(ChargeCode::ADDON_TAX)->id => ChargeCode::ADDON_TAX];
        $addons = BookingAddon::withTrashed()->where('booking_id', $booking->id)->get();

        if ($addons->isEmpty()) {
            return;
        }

        $posted = Transaction::query()
            ->whereIn('booking_addon_id', $addons->pluck('id'))
            ->whereIn('charge_code_id', array_keys($codeIds))
            ->get(['id', 'booking_addon_id', 'charge_code_id', 'transaction_type', 'amount'])
            ->groupBy(fn (Transaction $entry): string => $entry->booking_addon_id.':'.$codeIds[$entry->charge_code_id]);

        foreach ($addons as $addon) {
            $wanted = $voided || $addon->trashed()
                ? [ChargeCode::ADDON => 0.0, ChargeCode::ADDON_TAX => 0.0]
                : [ChargeCode::ADDON => (float) $addon->amount, ChargeCode::ADDON_TAX => (float) $addon->tax_amount];

            foreach ($wanted as $code => $amount) {
                $difference = round($amount - $this->signedSum($posted->get("{$addon->id}:{$code}", collect())), 2);

                if (abs($difference) < 0.01) {
                    continue;
                }

                $description = $code === ChargeCode::ADDON_TAX ? "{$addon->name} tax" : "{$addon->name} × {$addon->quantity}";
                $this->ledger->postEntry(
                    $masterWindow,
                    $difference > 0 ? TransactionType::Debit : TransactionType::Credit,
                    abs($difference),
                    ChargeCode::system($code),
                    $posted->has("{$addon->id}:{$code}") ? "{$description} (amended)" : $description,
                    bookingAddon: $addon,
                );
            }
        }
    }

    /**
     * Each room's charge split into room / tax / discount; the last room takes the
     * rounding so the shares add up exactly to the booking's taxes and discount.
     *
     * @return array<int, array{nights: int, room: float, tax: float, discount: float, adjustment: float, total: float}> keyed by sub-booking id
     */
    private function roomShares(Booking $booking): array
    {
        // Each room's own nights: a split booking's rooms can stay different lengths.
        $nightsByRoom = $booking->rooms->mapWithKeys(fn ($room): array => [$room->id => BookingPricing::nights($room->check_in_date->toDateString(), $room->check_out_date->toDateString())]);
        $amounts = $booking->rooms->mapWithKeys(fn ($room): array => [$room->id => round((float) $room->rate_per_night * $nightsByRoom[$room->id], 2)]);
        $roomTotal = $amounts->sum();
        $count = max(1, $amounts->count());
        $shares = [];
        $taxLeft = round((float) $booking->taxes, 2);
        $discountLeft = round((float) $booking->discount, 2);

        foreach ($amounts->keys()->values() as $index => $id) {
            $ratio = $roomTotal > 0 ? $amounts[$id] / $roomTotal : 1 / $count;
            $isLast = $index === $amounts->count() - 1;
            $tax = $isLast ? $taxLeft : round((float) $booking->taxes * $ratio, 2);
            $discount = $isLast ? $discountLeft : round((float) $booking->discount * $ratio, 2);
            $taxLeft = round($taxLeft - $tax, 2);
            $discountLeft = round($discountLeft - $discount, 2);

            $shares[$id] = ['nights' => $nightsByRoom[$id], 'room' => $amounts[$id], 'tax' => $tax, 'discount' => $discount, 'adjustment' => 0.0, 'total' => round($amounts[$id] + $tax - $discount, 2)];
        }

        // A stored total that isn't room − discount + tax (older booking-engine rows) is
        // settled on the last room, so the room invoices always add up to the booking total.
        if ($shares !== []) {
            $lastId = array_key_last($shares);
            $difference = round((float) $booking->total - array_sum(array_column($shares, 'total')), 2);
            $shares[$lastId]['adjustment'] = $difference;
            $shares[$lastId]['total'] = round($shares[$lastId]['total'] + $difference, 2);
        }

        return $shares;
    }

    /**
     * Each receipt tagged to the booking is credited once while it's Received — applied
     * to rooms that still owe, the rest to the master folio. A receipt cancelled (or
     * untagged) later is reversed on the windows it was credited to. Refunds are debits.
     *
     * @param  Collection<int, SubFolio>  $roomWindows
     */
    private function syncPayments(Booking $booking, Collection $roomWindows, SubFolio $masterWindow): void
    {
        $postedEntries = $this->postedPaymentEntries($booking);

        foreach ($this->paymentDifferences($booking, $postedEntries) as $paymentId => $difference) {
            $entries = $postedEntries[$paymentId] ?? collect();
            $payment = Payment::query()->find($paymentId);

            if ($payment?->type === 'refund') {
                $this->postSigned($masterWindow, $difference, ChargeCode::REFUND, "Refund {$payment->receipt_number}", null, $payment);
            } elseif ($difference < 0) {
                $this->creditAcrossWindows($roomWindows, $masterWindow, -$difference, $payment, "Receipt {$payment?->receipt_number}");
            } else {
                $this->reverseAcrossWindows($entries, $masterWindow, $difference, $payment);
            }
        }
    }

    /**
     * Each receipt's ledger entries on any of the booking's folios: a split payment (see
     * PaymentDistributionService) is spread over several rooms' windows.
     *
     * @return Collection<array-key, EloquentCollection<int, Transaction>> keyed by payment id
     */
    private function postedPaymentEntries(Booking $booking): Collection
    {
        return Transaction::query()
            ->whereNotNull('payment_id')
            ->whereHas('subFolio', fn ($query) => $query->whereIn('folio_id', $booking->allFoliosQuery()->select('id')))
            ->get(['id', 'sub_folio_id', 'transaction_type', 'amount', 'payment_id'])
            ->groupBy('payment_id');
    }

    /**
     * What each receipt tagged to the booking still has to post, signed as its effect on
     * the balance (receipts lower it, refunds raise it). Settled receipts are left out.
     *
     * @param  Collection<array-key, EloquentCollection<int, Transaction>>  $postedEntries
     * @return array<array-key, float> keyed by payment id
     */
    private function paymentDifferences(Booking $booking, Collection $postedEntries): array
    {
        $expected = BookingPaymentMapping::query()
            ->where('bookings_payments_mappings.booking_id', $booking->id)
            ->join('payments', 'payments.id', '=', 'bookings_payments_mappings.payment_id')
            ->get(['payments.id', 'payments.type', 'payments.status', 'bookings_payments_mappings.tagged_amount'])
            ->mapWithKeys(function (BookingPaymentMapping $row): array {
                // The payment's columns, selected alongside the mapping.
                $counts = in_array($row->getAttribute('status'), [PaymentStatus::Received->value, PaymentStatus::Refunded->value], true);
                $amount = $counts ? round((float) $row->tagged_amount, 2) : 0.0;

                return [$row->id => $row->getAttribute('type') === 'refund' ? $amount : -$amount];
            });

        $differences = [];

        foreach ($expected->keys()->merge($postedEntries->keys())->unique() as $paymentId) {
            $difference = round((float) ($expected[$paymentId] ?? 0) - $this->signedSum($postedEntries[$paymentId] ?? collect()), 2);

            if (abs($difference) >= 0.01) {
                $differences[$paymentId] = $difference;
            }
        }

        return $differences;
    }

    /**
     * Credits rooms that still owe, in order, and the master folio with anything left.
     *
     * @param  Collection<int, SubFolio>  $roomWindows
     */
    private function creditAcrossWindows(Collection $roomWindows, SubFolio $masterWindow, float $amount, ?Payment $payment, string $description): void
    {
        $remaining = round($amount, 2);

        foreach ($roomWindows as $window) {
            $share = min($remaining, max(0.0, $window->balance()));
            $this->postSigned($window, -$share, ChargeCode::PAYMENT, $description, null, $payment);
            $remaining = round($remaining - $share, 2);
        }

        $this->postSigned($masterWindow, -$remaining, ChargeCode::PAYMENT, $description, null, $payment);
    }

    /**
     * Takes a receipt's credit back from the windows holding it, largest first.
     *
     * @param  Collection<int, Transaction>  $entries  this payment's ledger entries
     */
    private function reverseAcrossWindows(Collection $entries, SubFolio $masterWindow, float $amount, ?Payment $payment): void
    {
        $remaining = round($amount, 2);
        $description = "Receipt {$payment?->receipt_number} reversed";

        $creditByWindow = $entries->groupBy('sub_folio_id')
            ->map(fn (Collection $windowEntries): float => -$this->signedSum($windowEntries))
            ->filter(fn (float $credit): bool => $credit > 0)
            ->sortDesc();

        foreach ($creditByWindow as $windowId => $credit) {
            $share = min($remaining, $credit);
            $this->postSigned(SubFolio::query()->findOrFail($windowId), $share, ChargeCode::PAYMENT, $description, null, $payment);
            $remaining = round($remaining - $share, 2);
        }

        $this->postSigned($masterWindow, $remaining, ChargeCode::PAYMENT, $description, null, $payment);
    }

    /**
     * Money sitting on the master folio (e.g. receipts credited before rooms had their
     * own folios) settles rooms that still owe. Both legs carry the receipt, so each
     * receipt's total credit is unchanged.
     *
     * @param  Collection<int, SubFolio>  $roomWindows
     */
    private function applyMasterCreditToRooms(Collection $roomWindows, SubFolio $masterWindow): void
    {
        $creditByPayment = $masterWindow->transactions()->whereNotNull('payment_id')
            ->get(['id', 'transaction_type', 'amount', 'payment_id', 'description'])
            ->groupBy('payment_id')
            // A receipt the front desk allocated itself (Split Payment / Add Payment's folio split) stays where it was put.
            ->reject(fn (Collection $entries): bool => $entries->contains(fn (Transaction $entry): bool => str_ends_with((string) $entry->description, '(split)')))
            ->map(fn (Collection $entries): float => -$this->signedSum($entries))
            ->filter(fn (float $credit): bool => $credit > 0);

        foreach ($creditByPayment as $paymentId => $credit) {
            $available = min($credit, max(0.0, -$masterWindow->balance()));
            $payment = Payment::query()->find($paymentId);

            foreach ($roomWindows as $window) {
                $share = round(min($available, max(0.0, $window->balance())), 2);

                if ($share < 0.01) {
                    continue;
                }

                $this->postSigned($masterWindow, $share, ChargeCode::PAYMENT, "Receipt {$payment?->receipt_number} applied to room folio", null, $payment);
                $this->postSigned($window, -$share, ChargeCode::PAYMENT, "Receipt {$payment?->receipt_number} (from master folio)", null, $payment);
                $available = round($available - $share, 2);
            }
        }
    }

    /**
     * Posts a debit for a positive amount, a credit for a negative one, nothing for zero.
     */
    private function postSigned(SubFolio $window, float $amount, string $code, string $description, ?SubBooking $source = null, ?Payment $payment = null): void
    {
        if (abs($amount) < 0.01) {
            return;
        }

        $this->ledger->postEntry(
            $window,
            $amount > 0 ? TransactionType::Debit : TransactionType::Credit,
            abs($amount),
            ChargeCode::system($code),
            $description,
            $source,
            $payment,
        );
    }

    /**
     * The codes this sync posts the room bill with. Anything else on a folio (food,
     * incidentals posted through Ledger::postCharge) isn't the booking total's to adjust.
     *
     * @return list<int>
     */
    private function roomBillCodeIds(): array
    {
        return $this->roomBillCodeIds ??= array_map(
            fn (string $code): int => ChargeCode::system($code)->id,
            [ChargeCode::ROOM, ChargeCode::TAX, ChargeCode::DISCOUNT, ChargeCode::ADJUSTMENT],
        );
    }

    /**
     * @return Collection<int, Transaction>
     */
    private function windowEntries(SubFolio $window): Collection
    {
        return $window->transactions()->get(['id', 'transaction_type', 'amount', 'payment_id', 'charge_code_id']);
    }

    /**
     * @param  Collection<int, Transaction>  $entries
     */
    private function signedSum(Collection $entries): float
    {
        return round($entries->sum(fn (Transaction $entry): float => $entry->transaction_type === TransactionType::Debit ? (float) $entry->amount : -(float) $entry->amount), 2);
    }
}
