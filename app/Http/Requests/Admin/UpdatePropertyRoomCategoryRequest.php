<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePropertyRoomCategoryRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:255'],
            'abbreviation' => ['nullable', 'string', 'max:255'],
            'integration_code' => ['nullable', 'string', 'max:255'],
            'inclusion_details' => ['nullable', 'string'],

            'base_occupancy' => ['required', 'integer', 'min:0'],
            'ideal_occupancy' => ['required', 'integer', 'min:0'],
            'max_adults' => ['required', 'integer', 'min:0'],
            'max_children' => ['required', 'integer', 'min:0'],
            'default_number_of_children' => ['required', 'integer', 'min:0'],
            'total_number_of_guests' => ['required', 'integer', 'min:0'],

            'sync_category_on_ota' => ['boolean'],
            'exclude_from_revenue' => ['boolean'],
            'exclude_from_occupancy' => ['boolean'],
            'exclude_from_upgrade' => ['boolean'],
            // Dormitory: sold by the bed (each room's beds are set under Rooms under Category).
            'sell_by_bed' => ['boolean'],

            'meal_plans' => ['array'],
            'meal_plans.*' => ['string', 'max:255'],
        ];
    }
}
