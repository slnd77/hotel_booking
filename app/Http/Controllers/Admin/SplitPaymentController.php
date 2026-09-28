<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentMode;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Property;
use App\Services\PaymentDistributionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Booking page → Folios → Split Payment: one payment settling several room invoices.
 */
class SplitPaymentController extends Controller
{
    /**
     * Payment modes offered for a front-desk split payment.
     */
    public const MODES = [PaymentMode::Cash, PaymentMode::CreditCard, PaymentMode::DebitCard, PaymentMode::UPI, PaymentMode::BankTransfer, PaymentMode::NetBanking];

    public function __invoke(Request $request, Property $property, Booking $booking, PaymentDistributionService $distribution): RedirectResponse
    {
        abort_unless($booking->property_id === $property->id, 404);

        $data = $request->validate([
            'payment_mode' => ['required', Rule::in(array_map(fn (PaymentMode $mode): string => $mode->value, self::MODES))],
            'gateway_reference' => ['nullable', 'string', 'max:255'],
            'distributions' => ['required', 'array', 'min:1', 'max:50'],
            'distributions.*.sub_folio_id' => ['required', 'integer'],
            'distributions.*.amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $total = round(array_sum(array_map(fn (array $row): float => (float) $row['amount'], $data['distributions'])), 2);

        $payment = $distribution->processMasterPayment(
            $booking->id,
            $total,
            array_values($data['distributions']),
            PaymentMode::from($data['payment_mode']),
            $data['gateway_reference'] ?? null,
            $request->user()->name,
        );

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $property->id,
            'booking_id' => $booking->id,
            'guest_id' => $booking->guests()->first()?->id,
            'action' => "Receipt # {$payment->receipt_number} for amount ".number_format($total, 2, '.', '').' split across '.count($data['distributions']).' folio window(s).',
            'date' => now(),
        ]);

        return back()->with('success', "Receipt {$payment->receipt_number} of ".number_format($total, 2).' recorded across '.count($data['distributions']).' invoice(s).');
    }
}
