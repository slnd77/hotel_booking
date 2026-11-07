<?php

namespace App\Services;

use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Models\CashierShift;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cashier shifts: a cashier opens a shift with the cash float in the drawer, every receipt
 * and refund they take while it is open is tagged with it, and at close the cash counted is
 * set against what should be there (float + cash received − cash refunded). Handing over
 * opens the next cashier's shift with the counted cash as their float.
 */
class CashierShifts
{
    /**
     * The user's open shift at the property.
     */
    public function current(Property $property, User $user): ?CashierShift
    {
        return CashierShift::query()->open()->where('property_id', $property->id)->where('user_id', $user->id)->latest('id')->first();
    }

    /**
     * @throws ValidationException when the user already has a shift open there
     */
    public function open(Property $property, User $user, float $openingFloat, ?string $notes = null): CashierShift
    {
        return DB::transaction(function () use ($property, $user, $openingFloat, $notes): CashierShift {
            User::query()->lockForUpdate()->find($user->id);

            if ($this->current($property, $user) !== null) {
                throw ValidationException::withMessages(['opening_float' => 'You already have a shift open at this property.']);
            }

            return CashierShift::create([
                'property_id' => $property->id,
                'user_id' => $user->id,
                'opened_at' => now(),
                'opening_float' => round($openingFloat, 2),
                'opening_notes' => $notes,
            ]);
        });
    }

    /**
     * What the shift took: receipts and refunds by payment mode, and the cash that should be
     * in the drawer. Cancelled receipts don't count.
     *
     * @return array{receipts_total: float, refunds_total: float, cash_receipts: float, cash_refunds: float, expected_cash: float, by_mode: list<array{mode: string, receipts: float, refunds: float, count: int}>}
     */
    public function summary(CashierShift $shift): array
    {
        $payments = $this->payments($shift);
        [$refunds, $receipts] = $payments->partition(fn (Payment $payment): bool => $payment->type === 'refund');
        $isCash = fn (Payment $payment): bool => $payment->payment_mode === PaymentMode::Cash;
        $cashReceipts = round((float) $receipts->filter($isCash)->sum('total_txn_amount'), 2);
        $cashRefunds = round((float) $refunds->filter($isCash)->sum('total_txn_amount'), 2);

        return [
            'receipts_total' => round((float) $receipts->sum('total_txn_amount'), 2),
            'refunds_total' => round((float) $refunds->sum('total_txn_amount'), 2),
            'cash_receipts' => $cashReceipts,
            'cash_refunds' => $cashRefunds,
            'expected_cash' => round((float) $shift->opening_float + $cashReceipts - $cashRefunds, 2),
            'by_mode' => $payments
                ->groupBy(fn (Payment $payment): string => $payment->payment_mode?->label() ?? 'Others')
                ->map(fn (Collection $group, string $mode): array => [
                    'mode' => $mode,
                    'receipts' => round((float) $group->where('type', '!=', 'refund')->sum('total_txn_amount'), 2),
                    'refunds' => round((float) $group->where('type', 'refund')->sum('total_txn_amount'), 2),
                    'count' => $group->count(),
                ])
                ->sortKeys()
                ->values()
                ->all(),
        ];
    }

    /**
     * Closes the shift with the cash counted; the variance is counted − expected (short is
     * negative). A hand-over opens the next cashier's shift with the counted cash.
     *
     * @throws ValidationException when the shift is already closed, or the next cashier has one open
     */
    public function close(CashierShift $shift, float $cashCounted, ?string $notes = null, ?User $handOverTo = null): CashierShift
    {
        return DB::transaction(function () use ($shift, $cashCounted, $notes, $handOverTo): CashierShift {
            $shift = CashierShift::query()->lockForUpdate()->with('property')->findOrFail($shift->id);

            if (! $shift->isOpen()) {
                throw ValidationException::withMessages(['cash_counted' => 'This shift is already closed.']);
            }

            if ($handOverTo !== null && ($handOverTo->is($shift->user) || $this->current($shift->property, $handOverTo) !== null)) {
                throw ValidationException::withMessages(['handed_over_to' => 'Hand over to another cashier who has no shift open here.']);
            }

            $summary = $this->summary($shift);
            $cashCounted = round($cashCounted, 2);

            $shift->update([
                'closed_at' => now(),
                'cash_receipts' => $summary['cash_receipts'],
                'cash_refunds' => $summary['cash_refunds'],
                'expected_cash' => $summary['expected_cash'],
                'cash_counted' => $cashCounted,
                'variance' => round($cashCounted - $summary['expected_cash'], 2),
                'totals_by_mode' => $summary['by_mode'],
                'handed_over_to' => $handOverTo?->id,
                'closing_notes' => $notes,
            ]);

            if ($handOverTo !== null) {
                $this->open($shift->property, $handOverTo, $cashCounted, "Handed over by {$shift->user?->name}");
            }

            return $shift;
        });
    }

    /**
     * Shifts that were open at some point on the property's business date.
     *
     * @return Collection<int, CashierShift>
     */
    public function forDay(Property $property, string $date, ?User $user = null): Collection
    {
        $dayStart = CarbonImmutable::parse($date, $property->timezoneName())->startOfDay()->utc();

        return CashierShift::query()
            ->where('property_id', $property->id)
            ->where('opened_at', '<', $dayStart->addDay())
            ->where(fn ($query) => $query->whereNull('closed_at')->orWhere('closed_at', '>=', $dayStart))
            ->when($user, fn ($query) => $query->where('user_id', $user->id))
            ->with(['user:id,name', 'handedOverTo:id,name'])
            ->orderBy('opened_at')
            ->get();
    }

    /**
     * @return Collection<int, Payment>
     */
    private function payments(CashierShift $shift): Collection
    {
        return Payment::query()
            ->where('cashier_shift_id', $shift->id)
            ->where(fn ($query) => $query->where('type', 'refund')->orWhere('status', '!=', PaymentStatus::Cancelled->value))
            ->get(['id', 'type', 'status', 'payment_mode', 'total_txn_amount']);
    }
}
