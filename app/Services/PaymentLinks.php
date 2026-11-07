<?php

namespace App\Services;

use App\Enums\PaymentLinkStatus;
use App\Enums\PaymentMode;
use App\Enums\PaymentStatus;
use App\Mail\PaymentLinkMail;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\BookingPaymentMapping;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Creates "Collect Online" payment links, e-mails them to the guest and turns
 * a successful gateway payment into a receipt against the booking.
 */
class PaymentLinks
{
    /**
     * @param  array{collect_for: string, amount: float|int|string, email?: string|null, mobile?: string|null}  $data
     */
    public function create(Booking $booking, array $data, ?User $user): PaymentLink
    {
        return PaymentLink::create([
            'property_id' => $booking->property_id,
            'booking_id' => $booking->id,
            'created_by' => $user?->id,
            'collect_for' => $data['collect_for'],
            'amount' => round((float) $data['amount'], 2),
            'currency' => $booking->property?->currency?->code ?: 'INR',
            'email' => filled($data['email'] ?? null) ? $data['email'] : null,
            'mobile' => filled($data['mobile'] ?? null) ? $data['mobile'] : null,
            'status' => PaymentLinkStatus::Pending,
        ]);
    }

    /**
     * E-mails the link to its address. SMS isn't supported yet, so a mobile number alone isn't used.
     */
    public function send(PaymentLink $link): void
    {
        Mail::to($link->email)->send(new PaymentLinkMail($link));

        $link->update(['sent_at' => now()]);
    }

    /**
     * Records a verified gateway payment: a receipt tagged to the booking, a lower
     * outstanding amount and a paid link. Safe to call twice for the same link.
     *
     * @param  array<string, mixed>  $verification  Gateway verifyPayment() result
     */
    public function recordPayment(PaymentLink $link, string $gateway, array $verification): PaymentLink
    {
        return DB::transaction(function () use ($link, $gateway, $verification): PaymentLink {
            $link = PaymentLink::query()->lockForUpdate()->findOrFail($link->id);

            if ($link->status === PaymentLinkStatus::Paid) {
                return $link;
            }

            $booking = Booking::query()->lockForUpdate()->with('guests')->findOrFail($link->booking_id);
            $amount = (float) $link->amount;
            $transactionId = (string) ($verification['transaction_id'] ?? $link->gateway_order_id ?? $link->token);
            $guest = $booking->guests->first();

            $payment = Payment::create([
                'property_id' => $link->property_id,
                'booking_id' => $booking->id,
                'type' => 'receipt',
                'voucher_number' => $booking->booking_ref_id,
                'receipt_number' => Payment::nextReceiptNumber($link->property_id),
                'guest_name' => $guest ? trim("{$guest->first_name} {$guest->last_name}") : null,
                'payee_name' => $link->collect_for,
                'payment_mode' => PaymentMode::ECollect,
                'total_txn_amount' => $amount,
                'tagged_amount' => $amount,
                'untagged_amount' => 0,
                'payment_creation_date' => now(),
                'payment_transaction_date' => now(),
                'status' => PaymentStatus::Received,
                'payment_ref_id' => $transactionId,
                'pg_txn_id' => $transactionId,
                'collection_source' => 'Payment Link',
                'pg_commission_amount' => app(GatewayCommissions::class)->for($link->loadMissing('property')->property, $gateway, $amount, $verification),
                'booking_source' => $booking->source ?? 'Direct',
                'collected_by_staff' => 'System',
            ]);

            // Payment::created usually tags the booking already; this only fills in when it didn't.
            BookingPaymentMapping::firstOrCreate(['booking_id' => $booking->id, 'payment_id' => $payment->id], ['tagged_amount' => $amount]);

            // The receipt's mapping re-syncs the ledger, which updates what the booking owes.

            $link->update([
                'status' => PaymentLinkStatus::Paid,
                'payment_gateway' => $gateway,
                'transaction_id' => $transactionId,
                'payment_id' => $payment->id,
                'paid_at' => now(),
            ]);

            // The guest isn't signed in, so the log is attributed to the staff member who created the link.
            if ($link->created_by !== null) {
                ActivityLog::create([
                    'user_id' => $link->created_by,
                    'property_id' => $link->property_id,
                    'booking_id' => $link->booking_id,
                    'guest_id' => $guest?->id,
                    'action' => "Online receipt # {$payment->receipt_number} for amount ".number_format($amount, 2, '.', '').' received via payment link.',
                    'date' => now(),
                ]);
            }

            return $link;
        });
    }
}
