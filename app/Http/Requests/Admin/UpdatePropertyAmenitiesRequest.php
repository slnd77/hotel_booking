<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePropertyAmenitiesRequest extends FormRequest
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
            'amenity_ids' => ['array'],
            'amenity_ids.*' => ['integer', 'exists:amenities,id'],
        ];
    }
}
