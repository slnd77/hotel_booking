<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MetaField;
use App\Models\Property;
use App\Services\Channex\ChannexTriggers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RateSettingController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const SETTING_KEYS = [
        'rate_settings_general',
        'rate_settings_occupancy_pricing',
        'rate_settings_tax',
        'rate_settings_rounding',
        'rate_settings_rate_plan',
        'rate_settings_rate_planner',
        'rate_settings_deals_promotion',
        'rate_settings_packages',
        'rate_settings_packages_planner',
    ];

    /**
     * Settings added after the meta fields were first seeded, created on first use.
     *
     * @var array<string, string>
     */
    private const LATER_KEYS = [
        'rate_settings_packages' => 'Configure packages for your property.',
        'rate_settings_packages_planner' => 'Configure the packages calendar for your property.',
        'rate_settings_rounding' => 'Configure rate rounding settings for your property.',
    ];

    public function index(): Response
    {
        return Inertia::render('admin/rate-settings/Index', [
            'properties' => Property::query()->orderBy('name')->get(['id', 'name', 'abbreviation']),
        ]);
    }

    public function edit(Property $property): Response
    {
        foreach (self::LATER_KEYS as $key => $label) {
            MetaField::query()->firstOrCreate(['key' => $key], ['key_label' => $label, 'status' => true]);
        }

        return Inertia::render('admin/rate-settings/Edit', [
            'property' => $property->only(['id', 'name']),
            'metaFields' => MetaField::query()->whereIn('key', self::SETTING_KEYS)->orderBy('id')->get(['id', 'key', 'key_label']),
            'metaFieldValues' => $property->metaFieldValues()
                ->whereIn('meta_fields_key_id', MetaField::query()->whereIn('key', self::SETTING_KEYS)->pluck('id'))
                ->get(['id', 'meta_fields_key_id', 'enabled', 'data']),
            'roomCategories' => $property->roomCategories()
                ->orderBy('display_order')
                ->get(['id', 'name', 'max_adults', 'meal_plans']),
        ]);
    }

    public function update(Request $request, Property $property, ChannexTriggers $triggers): RedirectResponse
    {
        $settingFieldIds = MetaField::query()->whereIn('key', self::SETTING_KEYS)->pluck('id')->all();

        $data = $request->validate([
            'meta_fields' => ['array'],
            'meta_fields.*.enabled' => ['boolean'],
            'meta_fields.*.data' => ['nullable', 'string'],
        ]);

        foreach ($data['meta_fields'] ?? [] as $metaFieldKeyId => $value) {
            if (! in_array((int) $metaFieldKeyId, $settingFieldIds, true)) {
                continue;
            }

            $property->metaFieldValues()->updateOrCreate(
                ['meta_fields_key_id' => $metaFieldKeyId],
                ['enabled' => $value['enabled'] ?? false, 'data' => $value['data'] ?? null],
            );
        }

        $triggers->ratesChanged($property->id);

        return redirect()->route('admin.rateSettings.edit', $property)->with('success', 'Rate settings updated.');
    }
}
