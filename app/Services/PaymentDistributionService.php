<?php

namespace App\Services;

use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Enums\TransactionType;
use App\Models\Booking;
use App\Models\ChargeCode;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\SubFolio;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One physical payment (a single card swipe) settling several billing windows at
 * once — e.g. a father paying for his family's three rooms. The windows stay
 * separate (three invoices); the payment is tagged across them as credits.
 */
class PaymentDistributionService
{
    public function __construct(private Ledger $ledger) {}

    /**
     * @param  list<array{sub_folio_id: int, amount: float|int|string}>  $distributions
     *
     * @throws ValidationException when the split doesn't add up or targets a window of another booking
     */
    public function processMasterPayment(
        int $bookingId,
        float $totalAmount,
        array $distributions,
        PaymentMode $method = PaymentMode::CreditCard,
        ?string $gatewayReference = null,
        ?string $collectedBy = null,
    ): Payment {
        return DB::transaction(function () use ($bookingId, $totalAmount, $distributions, $method, $gatewayReference, $collectedBy): Payment {
            $booking = Booking::query()->lockForUpdate()->with('guests')->findOrFail($bookingId);
            $windows = $this->validatedWindows($booking, $totalAmount, $distributions);
            $total = round($totalAmount, 2);
            $guest = $booking->guests->first();

            // 1. The master record of the physical transaction.
            $payment = Payment::create([
                'property_id' => $booking->property_id,
                'booking_id' => $booking->id,
                'type' => 'receipt',
                'voucher_number' => $booking->booking_ref_id,
                'receipt_number' => Payment::nextReceiptNumber($booking->property_id),
                'guest_name' => $guest ? trim("{$guest->first_name} {$guest->last_name}") : null,
                'payment_mode' => $method,
                'total_txn_amount' => $total,
                'tagged_amount' => $total,
                'untagged_amount' => 0,
                'payment_creation_date' => now(),
                'payment_transaction_date' => now(),
                'status' => PaymentStatus::Received,
                'payment_ref_id' => $gatewayReference,
                'pg_txn_id' => $gatewayReference,
                'collection_source' => 'Split Payment',
                'booking_source' => $booking->source ?? 'Direct',
                'collected_by_staff' => $collectedBy ?? auth()->user()?->name ?? 'Staff',
            ]);

            // 2. One credit per window, each tagged back to the master payment.
            $this->postCredits($windows, $distributions, $payment);

            // What the booking owes follows from the ledger once this commits (BookingLedgerSync).

            return $payment;
        });
    }

    /**
     * Add Payment → a receipt's share for one booking, spread over the booking's folio and
     * sub-folios as the front desk chose. The ledger sync then sees the receipt already
     * credited and leaves it where it was put.
     *
     * @param  list<array{sub_folio_id: int, amount: float|int|string}>  $distributions  shares of $amount (zero shares are skipped)
     *
     * @throws ValidationException when the shares don't add up to $amount or aren't this booking's windows
     */
    public function creditWindows(Booking $booking, Payment $payment, float $amount, array $distributions): void
    {
        $distributions = array_values(array_filter($distributions, fn (array $distribution): bool => round((float) ($distribution['amount'] ?? 0), 2) > 0));

        $this->postCredits($this->validatedWindows($booking, $amount, $distributions), $distributions, $payment);
    }

    /**
     * A booking's billing windows in the order a receipt settles them by default: each
     * room's sub-folio (booking order), then the booking's own folio. Labelled like the
     * booking page ("Room 2 | Deluxe").
     *
     * @return list<array{sub_folio_id: int, folio_id: int, label: string, is_room: bool, balance: float}>
     */
    public function folioWindowsFor(Booking $booking): array
    {
        $booking->loadMissing('rooms.roomCategory', 'rooms.folio.subFolios', 'folios.subFolios');
        $windows = [];

        foreach ($booking->rooms->values() as $index => $room) {
            foreach ($room->folio?->subFolios ?? [] as $window) {
                $windows[] = ['sub_folio_id' => $window->id, 'folio_id' => $room->folio->id, 'label' => 'Room '.($index + 1).' | '.($room->roomCategory?->name ?? 'Room').$this->windowSuffix($room->folio, $window), 'is_room' => true, 'balance' => round($window->balance(), 2)];
            }
        }

        foreach ($booking->folios->sortBy('id')->values() as $position => $folio) {
            foreach ($folio->subFolios as $window) {
                $label = ($position === 0 ? 'Master folio' : "Folio #{$folio->id}").$this->windowSuffix($folio, $window);
                $windows[] = ['sub_folio_id' => $window->id, 'folio_id' => $folio->id, 'label' => $label, 'is_room' => false, 'balance' => round($window->balance(), 2)];
            }
        }

        return $windows;
    }

    /** " — Window B" when the folio has more than one window. */
    private function windowSuffix(Folio $folio, SubFolio $window): string
    {
        return $folio->subFolios->count() > 1 ? " — {$window->window_name}" : '';
    }

    /**
     * @param  array<int, SubFolio>  $windows  keyed by id
     * @param  list<array{sub_folio_id: int, amount: float|int|string}>  $distributions
     */
    private function postCredits(array $windows, array $distributions, Payment $payment): void
    {
        $paymentCode = ChargeCode::system(ChargeCode::PAYMENT);

        foreach ($distributions as $distribution) {
            $this->ledger->postEntry(
                $windows[(int) $distribution['sub_folio_id']],
                TransactionType::Credit,
                round((float) $distribution['amount'], 2),
                $paymentCode,
                "Receipt {$payment->receipt_number} (split)",
                null,
                $payment,
            );
        }
    }

    /**
     * @param  list<array{sub_folio_id: int, amount: float|int|string}>  $distributions
     * @return array<int, SubFolio> keyed by id
     */
    private function validatedWindows(Booking $booking, float $totalAmount, array $distributions): array
    {
        if ($distributions === []) {
            throw ValidationException::withMessages(['distributions' => 'Choose at least one billing window to settle.']);
        }

        foreach ($distributions as $distribution) {
            if ((float) ($distribution['amount'] ?? 0) <= 0) {
                throw ValidationException::withMessages(['distributions' => 'Every share of the payment must be more than zero.']);
            }
        }

        $distributed = round(array_sum(array_map(fn (array $distribution): float => (float) $distribution['amount'], $distributions)), 2);

        if (abs($distributed - round($totalAmount, 2)) >= 0.01) {
            throw ValidationException::withMessages(['distributions' => "The shares add up to {$distributed}, not the payment total of ".round($totalAmount, 2).'.']);
        }

        $windowIds = array_map(fn (array $distribution): int => (int) $distribution['sub_folio_id'], $distributions);

        if (count($windowIds) !== count(array_unique($windowIds))) {
            throw ValidationException::withMessages(['distributions' => 'Each billing window can only appear once.']);
        }

        // Only this booking's windows (its master folio or its rooms' folios) can be settled.
        $windows = SubFolio::query()
            ->with('folio')
            ->whereIn('id', $windowIds)
            ->whereIn('folio_id', $booking->allFoliosQuery()->select('id'))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($windows->count() !== count($windowIds)) {
            throw ValidationException::withMessages(['distributions' => 'A billing window does not belong to this booking.']);
        }

        return $windows->all();
    }
}
