<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Booking;
use App\Models\SubBooking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A reservation or enquiry, with its rooms, guests and receipts when loaded. Dates are the
 * property's calendar dates; instants are UTC (ISO 8601).
 *
 * @mixin Booking
 */
class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'booking_ref_id' => $this->booking_ref_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'source' => $this->source,
            'booking_channel_id' => $this->booking_channel_id,
            'agent_id' => $this->agent_id,
            'type' => $this->type,
            'booking_type' => $this->booking_type,
            'check_in' => $this->check_in->toDateString(),
            'check_out' => $this->check_out->toDateString(),
            'check_in_time' => $this->check_in_time,
            'check_out_time' => $this->check_out_time,
            'rate_plan' => $this->rate_plan,
            'pay_at_hotel' => (bool) $this->pay_at_hotel,
            'room_total' => (float) $this->room_total,
            'discount' => (float) $this->discount,
            'taxes' => (float) $this->taxes,
            'total' => (float) $this->total,
            'outstanding' => (float) $this->outstanding,
            'special_instructions' => $this->special_instructions,
            'internal_notes' => $this->internal_notes,
            'hold_release_at' => $this->hold_release_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
            'guests' => GuestResource::collection($this->whenLoaded('guests')),
            'rooms' => $this->whenLoaded('rooms', fn () => $this->rooms->map(fn (SubBooking $room): array => [
                'id' => $room->id,
                'room_category_id' => $room->room_category_id,
                'room_category' => $room->roomCategory?->name,
                'room_id' => $room->room_id,
                'room' => $room->inventoryUnit?->name,
                'meal_plan' => $room->meal_plan,
                'adults' => (int) $room->adults,
                'children' => (int) $room->children,
                'infants' => (int) $room->infants,
                'rate_per_night' => (float) $room->rate_per_night,
                'check_in_date' => $room->check_in_date->toDateString(),
                'check_out_date' => $room->check_out_date->toDateString(),
                'status' => $room->status->value,
            ])->values()),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
        ];
    }
}
