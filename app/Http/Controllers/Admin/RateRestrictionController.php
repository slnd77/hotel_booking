<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Property;
use App\Services\Channex\ChannexTriggers;
use App\Services\StayRestrictions;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rate Settings → Restrictions: minimum / maximum stay, closed to arrival / departure and
 * stop-sell by date and room category, set for a date range at a time.
 */
class RateRestrictionController extends Controller
{
    /** Days the calendar shows at once. */
    private const WINDOW_DAYS = 42;

    public function __construct(private StayRestrictions $restrictions) {}

    public function edit(Request $request, Property $property): Response
    {
        $today = CarbonImmutable::parse($property->today());
        $from = $request->filled('from') && strtotime((string) $request->query('from')) !== false
            ? CarbonImmutable::parse((string) $request->query('from'))->max($today->subYear())
            : $today;
        $to = $from->addDays(self::WINDOW_DAYS - 1);
        $categories = $property->roomCategories()->orderBy('display_order')->get(['id', 'name']);

        return Inertia::render('admin/rate-restrictions/Edit', [
            'property' => $property->only(['id', 'name']),
            'today' => $today->toDateString(),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'roomCategories' => $categories,
            // What each category can sell on each date (property-wide and its own, combined).
            'effective' => $this->restrictions->effective($property->id, $categories->pluck('id')->all(), $from, $to),
            // Property-wide rows, to show them apart.
            'propertyWide' => $this->restrictions->rows($property->id, $from, $to)
                ->whereNull('room_category_id')
                ->mapWithKeys(fn ($row): array => [$row->date->toDateString() => $row->only(['min_stay', 'max_stay', 'closed_to_arrival', 'closed_to_departure', 'stop_sell'])]),
            'activityLogs' => ActivityLog::query()->where('property_id', $property->id)->where('action', 'like', 'Stay restrictions%')
                ->with('user:id,name')->latest('date')->limit(30)->get()
                ->map(fn (ActivityLog $log): array => ['action' => $log->action, 'user' => $log->user?->name, 'at' => $log->date?->toIso8601String()]),
        ]);
    }

    public function update(Request $request, Property $property, ChannexTriggers $channex): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['set', 'clear'])],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'weekdays' => ['nullable', 'array'],
            'weekdays.*' => ['integer', 'between:0,6'],
            'room_category_ids' => ['nullable', 'array'],
            'room_category_ids.*' => ['integer', Rule::exists('room_categories', 'id')->where('property_id', $property->id)],
            // Only the restrictions sent are changed; null clears one.
            'values' => ['required_if:action,set', 'array'],
            'values.min_stay' => ['nullable', 'integer', 'between:1,365'],
            'values.max_stay' => ['nullable', 'integer', 'between:1,365'],
            'values.closed_to_arrival' => ['nullable', 'boolean'],
            'values.closed_to_departure' => ['nullable', 'boolean'],
            'values.stop_sell' => ['nullable', 'boolean'],
        ]);

        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);

        if ($from->diffInDays($to) > 366) {
            return back()->withErrors(['to' => 'Set at most a year at a time.']);
        }

        $values = $data['values'] ?? [];

        if (isset($values['min_stay'], $values['max_stay']) && $values['max_stay'] < $values['min_stay']) {
            return back()->withErrors(['values.max_stay' => 'The maximum stay must be at least the minimum stay.']);
        }

        $categoryIds = array_map('intval', $data['room_category_ids'] ?? []);
        $weekdays = array_map('intval', $data['weekdays'] ?? []);

        if ($data['action'] === 'clear') {
            $this->restrictions->clear($property, $categoryIds, $from, $to);
            ActivityLog::create([
                'user_id' => $request->user()->id,
                'property_id' => $property->id,
                'action' => "Stay restrictions cleared, {$from->format('d M Y')} to {$to->format('d M Y')}.",
                'date' => now(),
            ]);
        } else {
            $this->restrictions->set($property, $categoryIds, $from, $to, $weekdays, $values, $request->user());
        }

        // The channel manager gets the new restrictions with the rates.
        $channex->ratesChanged($property->id);

        return back()->with('success', 'Restrictions saved.');
    }
}
