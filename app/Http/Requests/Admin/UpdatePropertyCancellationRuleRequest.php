<?php

namespace App\Http\Requests\Admin;

use App\Models\Property;
use App\Models\PropertyCancellationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePropertyCancellationRuleRequest extends FormRequest
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
            'start_time_stamp' => ['required', 'date'],
            'end_time_stamp' => ['required', 'date', 'after:start_time_stamp'],

            'rules' => ['required', 'array', 'min:1'],
            'rules.*.day_from_checkin' => ['required', 'integer', 'min:0'],
            'rules.*.penalty_percent' => ['required', 'numeric', 'between:0,100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $start = $this->input('start_time_stamp');
            $end = $this->input('end_time_stamp');

            if (! $start || ! $end || $validator->errors()->has('start_time_stamp') || $validator->errors()->has('end_time_stamp')) {
                return;
            }

            $property = $this->route('property');
            $propertyId = $property instanceof Property
                ? $property->id
                : (Property::where('id', $property)->orWhere('ulid', $property)->value('id') ?? $property);

            $currentRule = $this->route('cancellationRule');
            $currentRuleId = $currentRule instanceof PropertyCancellationRule
                ? $currentRule->id
                : (PropertyCancellationRule::where('id', $currentRule)->value('id') ?? $currentRule);

            $hasOverlap = PropertyCancellationRule::query()
                ->where('property_id', $propertyId)
                ->when($currentRuleId, fn ($query) => $query->where('id', '!=', $currentRuleId))
                ->where(function ($query) use ($start, $end) {
                    $query->where('start_time_stamp', '<=', $end)
                        ->where('end_time_stamp', '>=', $start);
                })
                ->exists();

            if ($hasOverlap) {
                $validator->errors()->add('start_time_stamp', 'The date range overlaps with an existing cancellation rule.');
            }
        });
    }
}
