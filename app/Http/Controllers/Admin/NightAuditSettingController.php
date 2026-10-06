<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\NightAuditSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Night Audit (Rollover Configuration): whether the day rolls over on its
 * own at the rollover time, or only when the front desk runs the night audit.
 */
class NightAuditSettingController extends Controller
{
    public function __construct(private NightAuditSettings $settings) {}

    public function index(): Response
    {
        return Inertia::render('admin/night-audit-settings/Index');
    }

    public function edit(Property $property): Response
    {
        return Inertia::render('admin/night-audit-settings/Edit', [
            'property' => $property->only(['id', 'name']),
            'settings' => [
                ...$this->settings->get($property),
                'current_rollover_date' => $this->settings->currentRolloverDate($property)->toDateString(),
            ],
            'rolloverTypes' => collect(NightAuditSettings::TYPES)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            'activityLogs' => $this->settings->logs($property),
        ]);
    }

    public function update(Request $request, Property $property): RedirectResponse
    {
        $data = $request->validate([
            'rollover_type' => ['required', Rule::in(array_keys(NightAuditSettings::TYPES))],
            'current_rollover_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$property->localTime()->addDay()->toDateString()],
            'default_rollover_time' => ['required', 'date_format:H:i'],
            'pre_rollover_notification' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'restrict_reports' => ['boolean'],
            'validate_excess_payment' => ['boolean'],
            'freeze_past_dated_data' => ['boolean'],
            'payment_reconciliation' => ['boolean'],
            'pos_rollover' => ['boolean'],
            'require_cashier_shift' => ['boolean'],
            'excess_payment_tolerance' => ['required', 'numeric', 'min:0'],
            'validate_invoice' => ['boolean'],
            'invoice_start_date' => ['nullable', 'date_format:Y-m-d'],
            'pos_rollover_start_date' => ['nullable', 'date_format:Y-m-d'],
            'room_rollover_start_date' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'current_rollover_date.before_or_equal' => 'The current rollover date can\'t be more than a day ahead of the property\'s date.',
        ]);

        $data['pre_rollover_notification'] = isset($data['pre_rollover_notification']) ? (int) $data['pre_rollover_notification'] : null;
        $data['excess_payment_tolerance'] = round((float) $data['excess_payment_tolerance'], 2);

        $this->settings->save($property, $data, $request->user());

        return redirect()->route('admin.nightAuditSettings.edit', $property)->with('success', 'Night audit settings updated.');
    }
}
