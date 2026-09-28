<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BlockReason;
use App\Models\Country;
use App\Models\Property;
use App\Services\ApplicationSettings;
use App\Services\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Manage Account → Application Settings: account-wide Block Unblock Config,
 * Reservation, Guest, Widget and Day Posting settings.
 */
class ApplicationSettingController extends Controller
{
    public function edit(ApplicationSettings $settings): Response
    {
        return Inertia::render('admin/application-settings/Edit', [
            'blockReasons' => BlockReason::query()->ordered()->get(['id', 'name', 'display_order']),
            'reservation' => $settings->get(ApplicationSettings::RESERVATION),
            'guest' => $settings->get(ApplicationSettings::GUEST),
            'dayPosting' => $settings->get(ApplicationSettings::DAY_POSTING),
            'smartChart' => $settings->get(ApplicationSettings::SMART_CHART) + [
                'min_days' => ApplicationSettings::SMART_CHART_MIN_DAYS,
                'max_days' => ApplicationSettings::SMART_CHART_MAX_DAYS,
                'min_refresh_minutes' => ApplicationSettings::SMART_CHART_MIN_REFRESH_MINUTES,
                'max_refresh_minutes' => ApplicationSettings::SMART_CHART_MAX_REFRESH_MINUTES,
            ],
            'notificationChannels' => $settings->notificationChannels(),
            'guestEmails' => collect(ApplicationSettings::GUEST_EMAILS)->map(fn (string $label, string $key): array => [
                'key' => $key,
                'label' => $label,
                'enabled' => $settings->sendsGuestEmail($key),
            ])->values(),
            'widgetProperties' => $settings->orderedProperties(['id', 'name']),
            'countries' => Country::query()->orderBy('name')->pluck('name'),
            'activityLogs' => collect([
                ApplicationSettings::BLOCK_UNBLOCK,
                ApplicationSettings::RESERVATION,
                ApplicationSettings::GUEST,
                ApplicationSettings::WIDGET,
                ApplicationSettings::DAY_POSTING,
                ApplicationSettings::SMART_CHART,
                ApplicationSettings::NOTIFICATION,
            ])->mapWithKeys(fn (string $section): array => [$section => $settings->logs($section)]),
        ]);
    }

    public function update(Request $request, string $section, ApplicationSettings $settings): RedirectResponse
    {
        // Smart Chart and Widget have their own permissions; the other sections are the account owner's.
        $permission = match ($section) {
            ApplicationSettings::SMART_CHART => 'manage_account.smart_chart_settings',
            ApplicationSettings::WIDGET => 'manage_account.widget_settings',
            default => null,
        };
        abort_unless($request->user()->hasAnyRole(PermissionCatalog::ownerRoles()) || ($permission !== null && $request->user()->checkPermissionTo($permission)), 403);

        $value = match ($section) {
            ApplicationSettings::RESERVATION => $this->validateReservation($request),
            ApplicationSettings::GUEST => $this->validateGuest($request),
            ApplicationSettings::WIDGET => $request->validate([
                'property_order' => ['required', 'array'],
                'property_order.*' => ['integer', 'distinct', 'exists:properties,id'],
            ]),
            ApplicationSettings::DAY_POSTING => $request->validate([
                'transaction_posting_time' => ['required', 'date_format:H:i'],
            ]),
            ApplicationSettings::SMART_CHART => array_map('intval', $request->validate([
                'days_to_show' => ['required', 'integer', 'min:'.ApplicationSettings::SMART_CHART_MIN_DAYS, 'max:'.ApplicationSettings::SMART_CHART_MAX_DAYS],
                'auto_refresh_minutes' => ['sometimes', 'required', 'integer', 'min:'.ApplicationSettings::SMART_CHART_MIN_REFRESH_MINUTES, 'max:'.ApplicationSettings::SMART_CHART_MAX_REFRESH_MINUTES],
            ]) + ['auto_refresh_minutes' => $settings->smartChartRefreshMinutes()]),
            default => abort(404),
        };

        $before = $settings->get($section);
        $settings->save($section, $value, $request->user(), $this->describeChanges($section, $before, $value));

        return back()->with('success', Str::headline($section).' settings saved.');
    }

    public function storeBlockReason(Request $request, ApplicationSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', $this->uniqueReasonName()],
        ]);

        BlockReason::create(['name' => trim($data['name']), 'display_order' => (int) BlockReason::query()->max('display_order') + 1]);
        $settings->log(ApplicationSettings::BLOCK_UNBLOCK, $request->user(), "Added block reason \"{$data['name']}\".");

        return back()->with('success', 'Block reason added.');
    }

    public function updateBlockReason(Request $request, BlockReason $blockReason, ApplicationSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', $this->uniqueReasonName($blockReason)],
        ]);

        $oldName = $blockReason->name;
        $blockReason->update(['name' => trim($data['name'])]);
        $settings->log(ApplicationSettings::BLOCK_UNBLOCK, $request->user(), "Renamed block reason \"{$oldName}\" to \"{$blockReason->name}\".");

        return back()->with('success', 'Block reason updated.');
    }

    public function reorderBlockReasons(Request $request, ApplicationSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'distinct', 'exists:block_reasons,id'],
        ]);

        foreach ($data['ids'] as $index => $id) {
            BlockReason::query()->whereKey($id)->update(['display_order' => $index + 1]);
        }

        $settings->log(ApplicationSettings::BLOCK_UNBLOCK, $request->user(), 'Changed block reason display order: '.BlockReason::query()->ordered()->pluck('name')->implode(', ').'.');

        return back()->with('success', 'Display order saved.');
    }

    /**
     * Reason names are compared case-insensitively ("booking" duplicates "Booking").
     */
    private function uniqueReasonName(?BlockReason $ignore = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($ignore): void {
            $exists = BlockReason::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $value))])
                ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
                ->exists();

            if ($exists) {
                $fail('A block reason with this name already exists.');
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function validateReservation(Request $request): array
    {
        $data = $request->validate([
            'check_in' => ['required', 'array'],
            'check_in.*' => ['boolean'],
            'check_out' => ['required', 'array'],
            'check_out.*' => ['boolean'],
            'subtypes' => ['required', 'array', 'min:1', 'max:30'],
            'subtypes.*.value' => ['nullable', 'string', 'max:50'],
            'subtypes.*.label' => ['required', 'string', 'max:50', 'distinct:ignore_case'],
            'subtypes.*.active' => ['boolean'],
        ]);

        $data['subtypes'] = collect($request->array('subtypes'))->map(fn (array $subtype): array => [
            'value' => filled($subtype['value'] ?? null) ? $subtype['value'] : Str::snake(Str::ascii($subtype['label'])),
            'label' => trim($subtype['label']),
            'active' => (bool) ($subtype['active'] ?? false),
        ])->values()->all();

        if (! collect($data['subtypes'])->contains('active', true)) {
            throw ValidationException::withMessages(['subtypes' => 'Keep at least one subtype switched on.']);
        }

        return [
            'check_in' => Arr::only($data['check_in'], array_keys(app(ApplicationSettings::class)->defaults(ApplicationSettings::RESERVATION)['check_in'])),
            'check_out' => Arr::only($data['check_out'], array_keys(app(ApplicationSettings::class)->defaults(ApplicationSettings::RESERVATION)['check_out'])),
            'subtypes' => $data['subtypes'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateGuest(Request $request): array
    {
        $data = $request->validate([
            'enable_tracker_number' => ['boolean'],
            'tracker_prefix' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9\-\/]*$/'],
            'tracker_start_number' => ['required', 'integer', 'min:1', 'max:999999999'],
            'mandatory' => ['required', 'array'],
            'mandatory.*' => ['boolean'],
            // "Property Country", "Nil" (empty) or a country name.
            'default_nationality' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value !== ApplicationSettings::PROPERTY_COUNTRY && ! Country::query()->where('name', $value)->exists()) {
                    $fail('Choose Property Country, Nil or a country from the list.');
                }
            }],
        ]);

        $mandatoryKeys = array_keys(app(ApplicationSettings::class)->defaults(ApplicationSettings::GUEST)['mandatory']);

        return [
            'enable_tracker_number' => (bool) ($data['enable_tracker_number'] ?? false),
            'tracker_prefix' => $data['tracker_prefix'] ?? '',
            'tracker_start_number' => (int) $data['tracker_start_number'],
            // First name is always required, whatever the checkbox says.
            'mandatory' => ['first_name' => true] + Arr::only(array_map('boolval', $data['mandatory']), $mandatoryKeys),
            'default_nationality' => $data['default_nationality'] ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function describeChanges(string $section, array $before, array $after): string
    {
        $old = Arr::dot($before);
        $changes = collect(Arr::dot($after))
            ->filter(fn ($value, string $key): bool => ($old[$key] ?? null) !== $value)
            ->map(fn ($value, string $key): string => Str::headline(str_replace('.', ' ', $key)).': '.$this->display($old[$key] ?? null).' → '.$this->display($value))
            ->values();

        return $changes->isEmpty()
            ? 'Saved '.Str::headline($section).' settings (no changes).'
            : 'Updated '.Str::headline($section).' settings — '.$changes->take(10)->implode('; ').($changes->count() > 10 ? '; …' : '').'.';
    }

    private function display(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'on' : 'off',
            $value === null || $value === '' => '—',
            default => (string) $value,
        };
    }
}
