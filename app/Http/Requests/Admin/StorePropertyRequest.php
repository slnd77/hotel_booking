<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StorePropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'property_legal_name' => ['required', 'string', 'max:255'],
            'property_group_id' => ['required', 'integer', 'exists:property_groups,id'],
            'abbreviation' => ['required', 'string', 'max:255'],
            'property_type' => ['required', 'integer'],

            'third_party_analytics_enabled' => ['boolean'],
            'third_party_ad_tracking_enabled' => ['boolean'],

            'property_contact_number' => ['nullable', 'string', 'max:255'],
            'outlet_sac_code_enabled' => ['boolean'],

            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['required', 'string', 'max:255'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'state_id' => ['required', 'integer', 'exists:states,id'],
            'country_id' => ['required', 'integer', 'exists:countries,id'],

            'corporate_address_permanent_address_same' => ['boolean'],
            'corporate_address_line_1' => ['required', 'string', 'max:255'],
            'corporate_address_line_2' => ['nullable', 'string', 'max:255'],
            'corporate_postal_code' => ['required', 'string', 'max:255'],
            'corporate_city_id' => ['required', 'integer', 'exists:cities,id'],
            'corporate_state_id' => ['required', 'integer', 'exists:states,id'],
            'corporate_country_id' => ['required', 'integer', 'exists:countries,id'],

            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'ignore_decimal_places_in_bill_settlement' => ['boolean'],
            'checkout_tolerance_value' => ['nullable', 'numeric'],

            'language' => ['required', 'integer', 'exists:languages,id'],

            'location_map_url' => ['nullable', 'url', 'max:2048'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'property_segment' => ['required', 'integer'],

            'star_rating' => ['required', 'integer', 'between:1,5'],
            'tax_type' => ['required', 'integer'],
            'metasearch_engines_listings_enabled' => ['boolean'],

            'customise_name_for+room_invoice' => ['nullable', 'string', 'max:255'],

            'third_party_analytics' => ['array'],
            'third_party_analytics.*' => ['nullable', 'string', 'max:255'],
            'third_party_ad_tracking' => ['array'],
            'third_party_ad_tracking.*' => ['nullable', 'string', 'max:255'],
            'metasearch_platforms' => ['array'],
            'metasearch_platforms.*' => ['nullable', 'string', 'max:255'],

            'meta_fields' => ['array'],
            'meta_fields.*.enabled' => ['boolean'],
            'meta_fields.*.data' => ['nullable', 'string'],
        ];
    }
}
