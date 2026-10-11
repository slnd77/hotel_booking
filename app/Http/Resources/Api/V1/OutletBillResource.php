<?php

namespace App\Http\Resources\Api\V1;

use App\Models\OutletBill;
use App\Models\OutletBillItem;
use App\Models\OutletKot;
use App\Models\OutletKotItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A POS bill with its lines, totals and where it went (a room's folio, or its own payment).
 *
 * @mixin OutletBill
 */
class OutletBillResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'outlet_id' => $this->outlet_id,
            'outlet' => $this->whenLoaded('outlet', fn (): string => $this->outlet->name),
            'bill_number' => $this->bill_number,
            'business_date' => $this->business_date->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'guest_name' => $this->guest_name,
            'table_number' => $this->table_number,
            'outlet_table_id' => $this->outlet_table_id,
            'split_from_id' => $this->split_from_id,
            'booking_id' => $this->booking_id,
            'booking_ref_id' => $this->whenLoaded('booking', fn (): ?string => $this->booking?->booking_ref_id),
            'sub_booking_id' => $this->sub_booking_id,
            'sub_folio_id' => $this->sub_folio_id,
            'payment_id' => $this->payment_id,
            'receipt_number' => $this->whenLoaded('payment', fn (): ?string => $this->payment?->receipt_number),
            'sub_total' => (float) $this->sub_total,
            'discount' => (float) $this->discount,
            'tax_total' => (float) $this->tax_total,
            'total' => (float) $this->total,
            'notes' => $this->notes,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (OutletBillItem $line): array => [
                'id' => $line->id,
                'outlet_item_id' => $line->outlet_item_id,
                'name' => $line->name,
                'sac_hsn_code' => $line->sac_hsn_code,
                'quantity' => $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'amount' => (float) $line->amount,
                'tax_rate' => (float) $line->tax_rate,
                'tax_amount' => (float) $line->tax_amount,
            ])->values()),
            'kots' => $this->whenLoaded('kots', fn () => $this->kots->map(fn (OutletKot $kot): array => [
                'id' => $kot->id,
                'kot_number' => $kot->kot_number,
                'notes' => $kot->notes,
                'is_cancellation' => $kot->isCancellation(),
                'items' => $kot->items->map(fn (OutletKotItem $line): array => ['outlet_item_id' => $line->outlet_item_id, 'name' => $line->name, 'quantity' => $line->quantity])->values(),
                'created_at' => $kot->created_at?->toIso8601ZuluString(),
                'printed_at' => $kot->printed_at?->toIso8601ZuluString(),
            ])->values()),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'closed_at' => $this->closed_at?->toIso8601ZuluString(),
            'voided_at' => $this->voided_at?->toIso8601ZuluString(),
            'void_reason' => $this->void_reason,
        ];
    }
}
