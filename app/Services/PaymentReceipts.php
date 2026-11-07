<?php

namespace App\Services;

use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\BookingPaymentMapping;
use App\Models\CashierShift;
use App\Models\Currency;
use App\Models\Payment;
use App\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a receipt (or refund) the way Databases → Payments → Add Payment does: the
 * payment, its tags to bookings and any chosen folio split, all in one transaction (a split
 * that doesn't add up saves nothing). The ledger sync then credits the bookings' folios.
 * Used by the admin panel and the API.
 */
class PaymentReceipts
{
    public function __construct(private PaymentDistributionService $distribution, private ExchangeRates $exchangeRates) {}

    /**
     * @param  array<string, mixed>  $data  validated Add Payment fields (see DatabaseController::paymentsStore)
     */
    public function record(int $propertyId, array $data, ?string $collectedBy = null): Payment
    {
        $receiptNumber = filled($data['receipt_number'] ?? null)
            ? $data['receipt_number']
            : Payment::nextReceiptNumber($propertyId);

        $txnDate = filled($data['payment_transaction_date'] ?? null)
            // Sent as an instant with its offset (the modal converts the property's wall-clock time); stored in UTC.
            ? CarbonImmutable::parse($data['payment_transaction_date'])->utc()
            : now();

        // Paid in another currency: the amount in the property's currency is converted here
        // at the rate of the transaction date, whatever the form said.
        $property = Property::query()->with('currency')->findOrFail($propertyId);
        $foreignCurrency = filled($data['currency_code'] ?? null) && strtoupper($data['currency_code']) !== Currency::forProperty($property)['code']
            ? strtoupper($data['currency_code'])
            : null;

        if ($foreignCurrency !== null) {
            $converted = $this->exchangeRates->toPropertyCurrency($property, $foreignCurrency, (float) $data['currency_amount'], CarbonImmutable::parse($property->localTime($txnDate)->toDateString()));
            $data['total_txn_amount'] = $converted['amount'];
        }

        $amount = (float) $data['total_txn_amount'];

        $this->ensureShiftOpen($property);

        $allocations = collect($data['allocations'] ?? [])
            ->filter(fn ($a) => (float) ($a['tagged_amount'] ?? 0) > 0);

        if ($allocations->isNotEmpty()) {
            $taggedAmount = round((float) $allocations->sum('tagged_amount'), 2);
            $untaggedAmount = max(0.0, round($amount - $taggedAmount, 2));
        } elseif (! empty($data['booking_id'])) {
            $booking = Booking::find($data['booking_id']);
            $taggedAmount = isset($data['tagged_amount'])
                ? round((float) $data['tagged_amount'], 2)
                : $amount;
            $untaggedAmount = max(0.0, round($amount - $taggedAmount, 2));
            if ($booking && $taggedAmount > 0) {
                $allocations = collect([
                    [
                        'booking_id' => $booking->id,
                        'tagged_amount' => $taggedAmount,
                    ],
                ]);
            }
        } else {
            $taggedAmount = 0.0;
            $untaggedAmount = $amount;
        }

        $notes = $data['notes'] ?? '';
        if (! empty($data['block_charge'])) {
            $notes = trim($notes."\n[Block Charge: Yes]");
        }
        if (filled($data['tin_no'] ?? null)) {
            $notes = trim($notes."\n[TIN: {$data['tin_no']}]");
        }

        $currencyFields = $foreignCurrency !== null
            ? ['currency_code' => $foreignCurrency, 'currency_amount' => round((float) $data['currency_amount'], 2), 'exchange_rate' => $converted['rate']]
            : [];

        return DB::transaction(function () use ($data, $allocations, $propertyId, $receiptNumber, $amount, $taggedAmount, $untaggedAmount, $txnDate, $notes, $collectedBy, $currencyFields): Payment {
            $payment = Payment::create([
                'property_id' => $propertyId,
                'booking_id' => $data['booking_id'] ?? ($allocations->first()['booking_id'] ?? null),
                'type' => $data['type'] ?? 'receipt',
                'voucher_number' => $data['voucher_number'] ?? null,
                'receipt_number' => $receiptNumber,
                'guest_name' => $data['guest_name'],
                'payee_name' => $data['paid_by'] ?? 'Guest',
                'payment_mode' => PaymentMode::normalize($data['payment_mode']) ?? PaymentMode::Online,
                'total_txn_amount' => $amount,
                ...$currencyFields,
                'tagged_amount' => $taggedAmount,
                'untagged_amount' => $untaggedAmount,
                'payment_creation_date' => now(),
                'payment_transaction_date' => $txnDate,
                'status' => PaymentStatus::Received,
                'payment_ref_id' => $data['payment_ref_id'] ?? null,
                'pg_txn_id' => $data['pg_txn_id'] ?? null,
                'notes' => $notes ?: null,
                'booking_source' => $data['booking_source'] ?? 'Direct',
                'collection_source' => $data['collection_source'] ?? null,
                'collected_by_staff' => $data['collected_by_staff'] ?? ($collectedBy ?? 'Staff'),
            ]);

            foreach ($allocations as $alloc) {
                $bId = (int) $alloc['booking_id'];
                $tAmt = (float) $alloc['tagged_amount'];
                if ($tAmt > 0) {
                    BookingPaymentMapping::updateOrCreate(
                        [
                            'booking_id' => $bId,
                            'payment_id' => $payment->id,
                        ],
                        [
                            'tagged_amount' => $tAmt,
                        ]
                    );

                    // Spread over the booking's folio / sub-folios as chosen; the ledger sync then leaves it there.
                    if ($payment->type !== 'refund' && ! empty($alloc['windows'])) {
                        $this->distribution->creditWindows(Booking::query()->findOrFail($bId), $payment, $tAmt, array_values($alloc['windows']));
                    }
                }
            }

            return $payment;
        });
    }

    /**
     * With "Require Open Cashier Shift" on (Night Audit settings), staff record payments
     * only while their shift is open, so every receipt counts in a shift.
     *
     * @throws ValidationException
     */
    private function ensureShiftOpen(Property $property): void
    {
        $user = auth()->user();

        if ($user === null || ! app(NightAuditSettings::class)->get($property)['require_cashier_shift']) {
            return;
        }

        if (! CashierShift::query()->open()->where('property_id', $property->id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['total_txn_amount' => 'Open your cashier shift (Cashier Shift page) before recording a payment.']);
        }
    }
}
