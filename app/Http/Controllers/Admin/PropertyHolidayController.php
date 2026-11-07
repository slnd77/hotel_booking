<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Property;
use App\Services\WeekendHolidays;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PropertyHolidayController extends Controller
{
    /**
     * Policies → Weekends & Holidays → Holiday Details: saved as soon as a holiday is added,
     * updated or deleted, so it shows on the Smart Chart and booking form straight away.
     */
    public function update(Request $request, Property $property, WeekendHolidays $weekendHolidays): RedirectResponse
    {
        $type = ['required', Rule::in(WeekendHolidays::TYPES)];

        $data = $request->validate([
            'holidays' => ['present', 'array', 'max:500'],
            'holidays.*.name' => ['required', 'string', 'max:100'],
            'holidays.*.type' => $type,
            'holidays.*.description' => ['nullable', 'string', 'max:500'],
            'holidays.*.from' => ['required', 'date_format:Y-m-d'],
            'holidays.*.to' => ['required', 'date_format:Y-m-d', 'after_or_equal:holidays.*.from'],
            'holidays.*.day_wise' => ['boolean'],
            'holidays.*.days' => ['array', 'max:366'],
            'holidays.*.days.*.date' => ['required', 'date_format:Y-m-d'],
            'holidays.*.days.*.name' => ['required', 'string', 'max:100'],
            'holidays.*.days.*.type' => $type,
            'holidays.*.days.*.description' => ['nullable', 'string', 'max:500'],
        ]);

        $ranges = collect($data['holidays'])->sortBy('from')->values();

        foreach ($ranges as $index => $holiday) {
            if ($index > 0 && $holiday['from'] <= $ranges[$index - 1]['to']) {
                throw ValidationException::withMessages(['holidays' => "{$holiday['name']} overlaps {$ranges[$index - 1]['name']}."]);
            }
        }

        $weekendHolidays->saveHolidays($property, $data['holidays']);

        ActivityLog::create([
            'user_id' => $request->user()?->id,
            'property_id' => $property->id,
            'action' => PropertyController::POLICY_LOG_PREFIX.' Weekends & Holidays updated.',
            'date' => now(),
        ]);

        return redirect()->route('admin.properties.edit', [$property, 'tab' => 'policies'])->with('success', 'Holiday calendar saved.');
    }
}
