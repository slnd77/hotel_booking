<?php

namespace App\Http\Controllers;

use App\Enums\PaymentLinkStatus;
use App\Models\PaymentLink;
use App\Services\BookingEngineService;
use App\Services\PaymentLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The guest-facing page behind a "Collect Online" link: shows the booking and
 * amount, and takes the payment through the property's payment gateways.
 */
class PaymentLinkPageController extends Controller
{
    public function __construct(
        protected BookingEngineService $bookingEngineService,
        protected PaymentLinks $paymentLinks,
    ) {}

    public function show(PaymentLink $paymentLink): Response
    {
        $paymentLink->load(['property', 'booking.rooms.roomCategory:id,name', 'booking.guests']);
        $booking = $paymentLink->booking;
        $guest = $booking->guests->first();

        return Inertia::render('PaymentLink/Pay', [
            'property' => ['name' => $paymentLink->property->name],
            // Times below are UTC; the page shows them in the property's timezone.
            'timezone' => $paymentLink->property->timezoneName(),
            'link' => [
                'token' => $paymentLink->token,
                'amount' => (float) $paymentLink->amount,
                'currency' => $paymentLink->currency,
                'status' => $paymentLink->effectiveStatus()->value,
                'status_label' => $paymentLink->effectiveStatus()->label(),
                'expires_at' => $paymentLink->expires_at?->toIso8601ZuluString(),
                'paid_at' => $paymentLink->paid_at?->toIso8601ZuluString(),
                'email' => $paymentLink->email,
                'mobile' => $paymentLink->mobile,
                'unpayable_reason' => $paymentLink->status === PaymentLinkStatus::Pending ? $paymentLink->unpayableReason() : null,
            ],
            'booking' => [
                'booking_ref_id' => $booking->booking_ref_id,
                'check_in' => $booking->check_in->format('d M Y'),
                'check_out' => $booking->check_out->format('d M Y'),
                'nights' => max(1, (int) $booking->check_in->diffInDays($booking->check_out)),
                'guest_name' => $guest ? trim("{$guest->first_name} {$guest->last_name}") : null,
                'room_lines' => $booking->rooms
                    ->groupBy(fn ($room): string => ($room->roomCategory->name ?? '—').'|'.($room->meal_plan ?: '—'))
                    ->map(fn ($rooms): string => $rooms->count().' - '.($rooms->first()->roomCategory->name ?? '—').' - '.($rooms->first()->meal_plan ?: '—').' X '.$rooms->sum('adults'))
                    ->values()
                    ->all(),
            ],
            'gateways' => $paymentLink->isPayable() ? $this->bookingEngineService->payableGateways($paymentLink->property) : [],
        ]);
    }

    public function initiate(Request $request, PaymentLink $paymentLink): JsonResponse
    {
        $gateway = $this->validatedGateway($request, $paymentLink);

        if (($reason = $paymentLink->unpayableReason()) !== null) {
            throw ValidationException::withMessages(['payment_gateway' => $reason]);
        }

        $paymentLink->loadMissing('booking.guests', 'property');
        $guest = $paymentLink->booking->guests->first();

        try {
            $order = $this->bookingEngineService->resolveGatewayForProperty($paymentLink->property, $gateway)->createOrder(
                $paymentLink->booking,
                (float) $paymentLink->amount,
                $paymentLink->currency,
                [
                    'booking_ref_id' => $paymentLink->booking->booking_ref_id,
                    'property_id' => $paymentLink->property_id,
                    'guest' => [
                        'name' => $guest ? trim("{$guest->first_name} {$guest->last_name}") : null,
                        'email' => $paymentLink->email,
                        'phone' => $paymentLink->mobile,
                    ],
                ],
            );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['success' => false, 'message' => 'The payment could not be started. Please try again.'], 422);
        }

        if (! ($order['success'] ?? true)) {
            return response()->json(['success' => false, 'message' => $order['message'] ?? 'The payment could not be started.'], 422);
        }

        $paymentLink->update(['payment_gateway' => $gateway, 'gateway_order_id' => $order['order_id'] ?? null]);

        return response()->json(['success' => true, ...$order]);
    }

    public function confirm(Request $request, PaymentLink $paymentLink): JsonResponse
    {
        $gateway = $this->validatedGateway($request, $paymentLink);
        $payload = $request->validate([
            'transaction_id' => ['nullable', 'string', 'max:255'],
            'order_id' => ['nullable', 'string', 'max:255'],
            'signature' => ['nullable', 'string', 'max:512'],
        ]);

        if ($paymentLink->status === PaymentLinkStatus::Paid) {
            return response()->json(['success' => true, 'message' => 'This link has already been paid.']);
        }

        // Money the gateway already took is always recorded (even if the link expired or the booking was
        // settled meanwhile — any overpayment then shows under Refunds); a cancelled link or another order is not.
        if ($paymentLink->status !== PaymentLinkStatus::Pending || $paymentLink->payment_gateway !== $gateway
            || ($paymentLink->gateway_order_id !== null && ($payload['order_id'] ?? null) !== $paymentLink->gateway_order_id)) {
            throw ValidationException::withMessages(['payment_gateway' => 'This payment does not match the link. Please start the payment again.']);
        }

        // Without the signature the Razorpay check is skipped, so a link can't be marked paid without one.
        if ($gateway === 'razorpay' && blank($payload['signature'] ?? null)) {
            throw ValidationException::withMessages(['signature' => 'The payment could not be verified.']);
        }

        $paymentLink->loadMissing('property');
        $verification = $this->bookingEngineService->resolveGatewayForProperty($paymentLink->property, $gateway)
            ->verifyPayment([...$payload, 'payment_gateway' => $gateway]);

        if (! ($verification['success'] ?? false)) {
            return response()->json(['success' => false, 'message' => $verification['message'] ?? 'The payment could not be verified.'], 422);
        }

        $paymentLink = $this->paymentLinks->recordPayment($paymentLink, $gateway, $verification);

        return response()->json([
            'success' => true,
            'message' => 'Payment received. Thank you!',
            'receipt_number' => $paymentLink->payment?->receipt_number,
        ]);
    }

    private function validatedGateway(Request $request, PaymentLink $paymentLink): string
    {
        $paymentLink->loadMissing('property');
        $gateways = array_column($this->bookingEngineService->payableGateways($paymentLink->property), 'id');

        return $request->validate(['payment_gateway' => ['required', 'string', Rule::in($gateways)]])['payment_gateway'];
    }
}
