<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Guest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A guest profile.
 *
 * @mixin Guest
 */
class GuestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'tracker_number' => $this->tracker_number,
            'title' => $this->title,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone_country_code' => $this->phone_country_code,
            'phone' => $this->phone,
            'emails' => array_values((array) $this->emails),
            'gender' => $this->gender,
            'dob' => $this->dob?->toDateString(),
            'nationality' => $this->nationality,
            'purpose_of_visit' => $this->purpose_of_visit,
            'guest_preferences' => $this->guest_preferences,
            'anniversary' => $this->anniversary?->toDateString(),
            'company' => $this->company,
            'address' => $this->address,
            'vehicle_number' => $this->vehicle_number,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
