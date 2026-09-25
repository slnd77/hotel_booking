<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MetaField;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaxSettingController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const SETTING_KEYS = [
        'tax_settings_tax_category',
        'tax_settings_tax_rule',
    ];

    public function index(): Response
    {
        return Inertia::render('admin/tax-settings/Index', [
            'properties' => Property::query()->orderBy('name')->get(['id', 'name', 'abbreviation']),
        ]);
    }

    public function edit(Property $property): Response
    {
        return Inertia::render('admin/tax-settings/Edit', [
            'property' => $property->only(['id', 'name']),
            'metaFields' => MetaField::query()->whereIn('key', self::SETTING_KEYS)->orderBy('id')->get(['id', 'key', 'key_label']),
            'metaFieldValues' => $property->metaFieldValues()
                ->whereIn('meta_fields_key_id', MetaField::query()->whereIn('key', self::SETTING_KEYS)->pluck('id'))
                ->get(['id', 'meta_fields_key_id', 'enabled', 'data']),
        ]);
    }

    public function update(Request $request, Property $property): RedirectResponse
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

        return redirect()->route('admin.taxSettings.edit', $property)->with('success', 'Tax settings updated.');
    }
}
