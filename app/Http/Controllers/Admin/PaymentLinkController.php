<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Enums\PaymentLinkStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\PaymentLink;
use App\Models\Property;
use App\Services\PaymentLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Booking → Collect Online: creates a payment link and, with "Send Payment Link", e-mails it.
 */
class PaymentLinkController extends Controller
{
    public function store(Request $request, Property $property, Booking $booking, PaymentLinks $paymentLinks): JsonResponse
    {
        abort_unless($booking->property_id === $property->id, 404);

        $outstanding = round((float) $booking->outstanding, 2);

        $data = $request->validate([
            'collect_for' => ['required', 'string', 'in:Guest,Guest Company'],
            'amount' => ['required', 'numeric', 'min:1', 'max:'.max(1, $outstanding)],
            'email' => ['nullable', 'email', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'send' => ['boolean'],
        ], [
            'amount.max' => 'The amount cannot be more than the outstanding amount of '.number_format($outstanding, 2).'.',
        ]);

        if (in_array($booking->status, [BookingStatus::Cancelled, BookingStatus::NoShow, BookingStatus::ExpiredQuote], true) || $outstanding <= 0) {
            throw ValidationException::withMessages(['amount' => "There is nothing to collect for this {$booking->status->label()} booking."]);
        }

        $send = (bool) ($data['send'] ?? false);

        if ($send && blank($data['email'] ?? null)) {
            throw ValidationException::withMessages([
                'email' => 'Enter an e-mail address to send the link. Sending by SMS isn\'t supported yet — use "Get Payment Link" to share it another way.',
            ]);
        }

        $link = $paymentLinks->create($booking, $data, $request->user());
        $amount = number_format((float) $link->amount, 2, '.', '');

        $this->log($request, $booking, "Payment link of amount {$link->currency} {$amount} created via Collect Online.");

        if (! $send) {
            return response()->json(['message' => 'Payment link created.', 'payment_link' => $link->url()]);
        }

        try {
            $paymentLinks->send($link);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'The link was created but the e-mail could not be sent. Copy the link below and share it instead.',
                'payment_link' => $link->url(),
            ], 422);
        }

        $this->log($request, $booking, "Payment link of amount {$link->currency} {$amount} sent to {$link->email} via Collect Online.");

        return response()->json(['message' => "Payment link sent to {$link->email}.", 'payment_link' => $link->url()]);
    }

    /**
     * E-mails a pending link again (e.g. the guest lost the first e-mail).
     */
    public function resend(Request $request, Property $property, Booking $booking, PaymentLink $paymentLink, PaymentLinks $paymentLinks): RedirectResponse
    {
        $this->ensureBelongs($property, $booking, $paymentLink);

        if (($reason = $paymentLink->unpayableReason()) !== null) {
            return back()->with('error', $reason);
        }

        if (blank($paymentLink->email)) {
            return back()->with('error', 'This link has no e-mail address to send it to. Copy the link instead.');
        }

        try {
            $paymentLinks->send($paymentLink);
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', 'The e-mail could not be sent. Copy the link and share it instead.');
        }

        $this->log($request, $booking, "Payment link of amount {$paymentLink->currency} ".number_format((float) $paymentLink->amount, 2, '.', '')." re-sent to {$paymentLink->email}.");

        return back()->with('success', "Payment link sent to {$paymentLink->email}.");
    }

    public function cancel(Request $request, Property $property, Booking $booking, PaymentLink $paymentLink): RedirectResponse
    {
        $this->ensureBelongs($property, $booking, $paymentLink);

        if ($paymentLink->status !== PaymentLinkStatus::Pending) {
            return back()->with('error', "A {$paymentLink->status->label()} link can't be cancelled.");
        }

        $paymentLink->update(['status' => PaymentLinkStatus::Cancelled]);
        $this->log($request, $booking, "Payment link of amount {$paymentLink->currency} ".number_format((float) $paymentLink->amount, 2, '.', '').' cancelled.');

        return back()->with('success', 'Payment link cancelled.');
    }

    private function ensureBelongs(Property $property, Booking $booking, PaymentLink $paymentLink): void
    {
        abort_unless($booking->property_id === $property->id && $paymentLink->booking_id === $booking->id, 404);
    }

    private function log(Request $request, Booking $booking, string $action): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'property_id' => $booking->property_id,
            'booking_id' => $booking->id,
            'guest_id' => $booking->guests()->first()?->id,
            'action' => $action,
            'date' => now(),
        ]);
    }
}
