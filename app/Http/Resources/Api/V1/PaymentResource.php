<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A receipt or refund, with the bookings it is tagged to.
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'booking_id' => $this->booking_id,
            'type' => $this->type,
            'receipt_number' => $this->receipt_number,
            'voucher_number' => $this->voucher_number,
            'status' => $this->status?->value,
            'payment_mode' => $this->payment_mode?->value,
            'total_txn_amount' => (float) $this->total_txn_amount,
            'tagged_amount' => (float) $this->tagged_amount,
            'untagged_amount' => (float) $this->untagged_amount,
            'guest_name' => $this->guest_name,
            'payee_name' => $this->payee_name,
            'payment_ref_id' => $this->payment_ref_id,
            'pg_txn_id' => $this->pg_txn_id,
            'collection_source' => $this->collection_source,
            'collected_by_staff' => $this->collected_by_staff,
            'notes' => $this->notes,
            'cancellation_reason' => $this->cancellation_reason,
            'payment_transaction_date' => $this->payment_transaction_date?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'allocations' => $this->whenLoaded('bookings', fn () => $this->bookings->map(fn (Booking $booking): array => [
                'booking_id' => $booking->id,
                'booking_ref_id' => $booking->booking_ref_id,
                'tagged_amount' => (float) $booking->pivot->tagged_amount,
            ])->values()),
        ];
    }
}
