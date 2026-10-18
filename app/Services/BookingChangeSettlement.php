<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\BookingPaymentMapping;
use App\Models\ChargeCode;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What an edit that lowers a booking's bill leaves behind:
 *  - the cancellation policy's charge on the amount taken off (the tier for how many days
 *    before the original check-in the change is made), posted to the master folio;
 *  - a credit when the guest has now paid more than the bill, settled as a refund, kept as
 *    an advance on the receipt (untagged, to tag to another booking later) or moved to
 *    another booking;
 *  - the issued invoice brought up to date (it keeps its number).
 */
class BookingChangeSettlement
{
    public const ACTIONS = ['refund', 'guest_ledger', 'advance', 'transfer', 'leave'];

    public function __construct(
        private BookingLedgerSync $ledgerSync,
        private Ledger $ledger,
        private PaymentReceipts $receipts,
        private BookingEngineService $bookingEngine,
    ) {}

    /**
     * The booking's whole bill: rooms with tax, plus add-ons with theirs.
     */
    public function billTotal(Booking $booking): float
    {
        return round((float) $booking->total + (float) $booking->addons()->sum('total'), 2);
    }

    /**
     * What the guest has paid beyond the bill (0 when they still owe).
     */
    public function credit(Booking $booking): float
    {
        return round(max(0.0, -$booking->outstandingBalance()), 2);
    }

    /**
     * The policy that applies to a change made today to a stay starting on the check-in date.
     *
     * @return array{tiers: list<array{days: int, percent: float}>, summary: array<int, array{window: string, penalty: string, refund: string, description: string}>, days_before: int}
     */
    public function policy(Property $property, CarbonImmutable $checkIn): array
    {
        $rule = $this->bookingEngine->resolveCancellationRule($property, $checkIn);
        $today = CarbonImmutable::parse($property->today());

        return [
            'tiers' => array_values(collect($rule->rules ?? [])
                ->map(fn (array $tier): array => ['days' => (int) $tier['day_from_checkin'], 'percent' => (float) $tier['penalty_percent']])
                ->sortBy('days')
                ->all()),
            'summary' => $this->bookingEngine->cancellationRuleSummary($property, $checkIn),
            // Changed on or after the arrival day: the arrival-day tier.
            'days_before' => max(0, (int) $today->diffInDays($checkIn->startOfDay(), false)),
        ];
    }

    /**
     * The charge for taking an amount off the bill: the penalty % of the tier the change
     * falls in (the first whose "days from check-in" covers the days left), on that amount.
     *
     * @return array{percent: float, days_before: int, reduced_by: float, amount: float}
     */
    public function cancellationCharge(Property $property, CarbonImmutable $checkIn, float $before, float $after): array
    {
        $policy = $this->policy($property, $checkIn);
        $reducedBy = round(max(0.0, $before - $after), 2);
        $tier = collect($policy['tiers'])->first(fn (array $tier): bool => $policy['days_before'] <= $tier['days']);
        $percent = $reducedBy > 0 && $tier !== null ? $tier['percent'] : 0.0;

        return [
            'percent' => $percent,
            'days_before' => $policy['days_before'],
            'reduced_by' => $reducedBy,
            'amount' => round($reducedBy * $percent / 100, 2),
        ];
    }

    /**
     * Bookings the credit can move to: the property's open ones (not cancelled, no-show or
     * checked out), the same guest's first, then those owing most.
     *
     * @return list<array{id: int, booking_ref_id: string, guest_name: string, check_in: string, outstanding: float}>
     */
    public function transferTargets(Booking $booking, int $limit = 50): array
    {
        return array_values(Booking::query()
            ->where('property_id', $booking->property_id)
            ->whereKeyNot($booking->id)
            ->whereNotIn('status', [BookingStatus::Cancelled, BookingStatus::NoShow, BookingStatus::CheckedOut, ...BookingStatus::enquiryStatuses()])
            ->with('primaryGuest:id,first_name,last_name')
            ->orderByRaw('CASE WHEN guest_id = ? THEN 0 ELSE 1 END', [$booking->guest_id ?? 0])
            ->orderByDesc('outstanding')
            ->limit($limit)
            ->get()
            ->map(fn (Booking $other): array => [
                'id' => $other->id,
                'booking_ref_id' => (string) $other->booking_ref_id,
                'guest_name' => $other->primaryGuest ? trim("{$other->primaryGuest->first_name} {$other->primaryGuest->last_name}") : '—',
                'check_in' => $other->check_in->toDateString(),
                'outstanding' => round((float) $other->outstanding, 2),
            ])
            ->all());
    }

    /**
     * Settles a saved edit: the cancellation charge (when chosen), then the credit as chosen,
     * then the invoice. Returns what was done, for the flash message.
     *
     * @param  array{apply_cancellation_charge?: bool|null, credit_action?: string|null, refund_mode?: string|null, refund_reference?: string|null, refund_notes?: string|null, transfer_booking_id?: int|null}  $choice
     * @return list<string>
     */
    public function settle(Booking $booking, float $billBefore, CarbonImmutable $checkInBefore, array $choice, User $user): array
    {
        $done = [];
        $booking->refresh();
        $property = $booking->property;

        if ($choice['apply_cancellation_charge'] ?? false) {
            $charge = $this->cancellationCharge($property, $checkInBefore, $billBefore, $this->billTotal($booking));

            if ($charge['amount'] > 0) {
                $this->postCancellationCharge($booking, $charge, $user);
                $done[] = 'cancellation charge '.number_format($charge['amount'], 2)." ({$charge['percent']}%)";
            }
        }

        $this->ledgerSync->sync($booking);
        $credit = $this->credit($booking->refresh());
        $action = $choice['credit_action'] ?? 'leave';

        if ($credit > 0) {
            $done[] = match ($action) {
                'refund' => 'refund '.$this->refund($booking, $credit, $choice['refund_mode'] ?? throw ValidationException::withMessages(['refund_mode' => 'Choose a refund mode.']), $choice['refund_reference'] ?? null, $choice['refund_notes'] ?? null, $user)->receipt_number.' of '.number_format($credit, 2),
                'guest_ledger' => number_format(app(GuestLedger::class)->depositFromBooking($booking, $credit, $user), 2).' moved to the guest ledger',
                'advance' => number_format($this->keepAsAdvance($booking, $credit, $user), 2).' kept as an advance',
                'transfer' => number_format($this->moveToBooking($booking, Booking::query()->findOrFail($choice['transfer_booking_id'] ?? throw ValidationException::withMessages(['transfer_booking_id' => 'Choose a booking to move the credit to.'])), $credit, $user), 2).' moved to another booking',
                default => 'credit of '.number_format($credit, 2).' left on the booking',
            };
        }

        if ($this->refreshInvoice($booking, $user)) {
            $done[] = 'invoice updated';
        }

        return $done;
    }

    /**
     * @param  array{percent: float, days_before: int, reduced_by: float, amount: float}  $charge
     */
    private function postCancellationCharge(Booking $booking, array $charge, User $user): void
    {
        $window = $this->ledger->defaultWindowFor($booking, $booking->property->currency?->code);
        $this->ledger->postEntry(
            $window,
            TransactionType::Debit,
            $charge['amount'],
            ChargeCode::system(ChargeCode::CANCELLATION),
            "Cancellation charge {$charge['percent']}% on ".number_format($charge['reduced_by'], 2)." taken off ({$charge['days_before']} day(s) before check-in)",
        );

        $this->log($booking, $user, "Cancellation charge of {$charge['amount']} ({$charge['percent']}% of {$charge['reduced_by']} taken off, {$charge['days_before']} day(s) before check-in)");
    }

    /**
     * Pays the credit back: a refund tagged to the booking, which the ledger posts as a debit.
     */
    public function refund(Booking $booking, float $amount, string $mode, ?string $reference, ?string $notes, User $user): Payment
    {
        $guest = $booking->primaryGuest;

        $refund = $this->receipts->record($booking->property_id, [
            'type' => 'refund',
            'booking_id' => $booking->id,
            'voucher_number' => $booking->booking_ref_id,
            'guest_name' => $guest ? trim("{$guest->first_name} {$guest->last_name}") : (string) $booking->booking_ref_id,
            'paid_by' => 'Hotel',
            'payment_mode' => $mode,
            'total_txn_amount' => $amount,
            'tagged_amount' => $amount,
            'payment_ref_id' => $reference,
            'notes' => trim('Refund of the credit left by a booking change. '.($notes ?? '')),
            'booking_source' => $booking->source,
            'collection_source' => 'Booking change',
        ], $user->name);

        $this->ledgerSync->sync($booking);
        $this->log($booking, $user, "Refund {$refund->receipt_number} of {$amount} ({$mode}) for the credit left by a booking change");

        return $refund;
    }

    /**
     * Takes the credit off the booking's receipts (latest first): that part of each receipt
     * becomes untagged, an advance the guest can use on another booking.
     */
    public function keepAsAdvance(Booking $booking, float $amount, User $user): float
    {
        $moved = $this->untag($booking, $amount);
        $this->ledgerSync->sync($booking);
        $this->log($booking, $user, "Credit of {$moved} kept as an advance (untagged on the receipt) after a booking change");

        return $moved;
    }

    /**
     * Moves the credit to another booking of the property: the receipts' tags follow it.
     */
    public function moveToBooking(Booking $booking, Booking $target, float $amount, User $user): float
    {
        if ($target->property_id !== $booking->property_id || $target->id === $booking->id) {
            throw ValidationException::withMessages(['transfer_booking_id' => 'Pick another booking of this property.']);
        }

        $moved = DB::transaction(function () use ($booking, $target, $amount): float {
            $moved = 0.0;

            foreach ($this->untagShares($booking, $amount) as $paymentId => $share) {
                $mapping = BookingPaymentMapping::query()->firstOrNew(['booking_id' => $target->id, 'payment_id' => $paymentId]);
                $mapping->tagged_amount = round((float) $mapping->tagged_amount + $share, 2);
                $mapping->save();
                $moved = round($moved + $share, 2);
            }

            return $moved;
        });

        $this->ledgerSync->sync($booking);
        $this->ledgerSync->sync($target);
        $this->log($booking, $user, "Credit of {$moved} moved to booking {$target->booking_ref_id} after a booking change");
        $this->log($target, $user, "Credit of {$moved} moved in from booking {$booking->booking_ref_id}");

        return $moved;
    }

    /**
     * An issued invoice (with no credit notes) that no longer matches the bill is redone; it keeps its number.
     */
    public function refreshInvoice(Booking $booking, User $user): bool
    {
        $invoice = Invoice::query()
            ->where('booking_id', $booking->id)
            ->whereNotIn('status', [InvoiceStatus::Cancelled, InvoiceStatus::Void])
            ->withCount('creditNotes')
            ->latest('id')
            ->first();

        if ($invoice === null || $invoice->credit_notes_count > 0 || abs((float) $invoice->invoice_amount - (float) $booking->total) <= 0.01) {
            return false;
        }

        Invoice::generateForBooking($booking, $user->name);

        return true;
    }

    private function untag(Booking $booking, float $amount): float
    {
        return DB::transaction(function () use ($booking, $amount): float {
            $moved = 0.0;

            foreach ($this->untagShares($booking, $amount) as $paymentId => $share) {
                $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);
                $payment->update([
                    'tagged_amount' => round(max(0.0, (float) $payment->tagged_amount - $share), 2),
                    'untagged_amount' => round((float) $payment->untagged_amount + $share, 2),
                ]);
                $moved = round($moved + $share, 2);
            }

            return $moved;
        });
    }

    /**
     * Lowers the booking's tags on its received receipts, latest first, by the amount.
     *
     * @return array<int, float> what was taken off each payment, keyed by payment id
     */
    private function untagShares(Booking $booking, float $amount): array
    {
        $remaining = round($amount, 2);
        $shares = [];

        $mappings = BookingPaymentMapping::query()
            ->where('bookings_payments_mappings.booking_id', $booking->id)
            ->join('payments', 'payments.id', '=', 'bookings_payments_mappings.payment_id')
            ->where('payments.type', '!=', 'refund')
            ->where('payments.status', PaymentStatus::Received->value)
            ->orderByDesc('payments.id')
            ->lockForUpdate()
            ->get(['bookings_payments_mappings.*']);

        foreach ($mappings as $mapping) {
            if ($remaining <= 0) {
                break;
            }

            $share = round(min($remaining, (float) $mapping->tagged_amount), 2);

            if ($share <= 0) {
                continue;
            }

            $left = round((float) $mapping->tagged_amount - $share, 2);
            $left > 0 ? $mapping->update(['tagged_amount' => $left]) : $mapping->delete();
            $shares[$mapping->payment_id] = $share;
            $remaining = round($remaining - $share, 2);
        }

        return $shares;
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
