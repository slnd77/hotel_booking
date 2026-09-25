<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MetaField;
use App\Models\Property;
use App\Services\PropertySettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceSettingController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const SETTING_KEYS = [
        'invoice_settings_trackers',
        'invoice_settings_general',
    ];

    /**
     * Images printed on invoices, stored privately and kept in the general settings as
     * "{key}_path" (the rest of the page never sends them).
     *
     * @var array<string, string>
     */
    public const IMAGES = [
        'invoice_logo' => 'Invoice logo',
        'digital_signature' => 'Digital signature',
    ];

    public function index(): Response
    {
        return Inertia::render('admin/invoice-settings/Index', [
            'properties' => Property::query()->orderBy('name')->get(['id', 'name', 'abbreviation']),
        ]);
    }

    public function edit(Property $property): Response
    {
        $general = $this->generalSettings($property);

        return Inertia::render('admin/invoice-settings/Edit', [
            'property' => $property->only(['id', 'name']),
            'metaFields' => MetaField::query()->whereIn('key', self::SETTING_KEYS)->orderBy('id')->get(['id', 'key', 'key_label']),
            'metaFieldValues' => $property->metaFieldValues()
                ->whereIn('meta_fields_key_id', MetaField::query()->whereIn('key', self::SETTING_KEYS)->pluck('id'))
                ->get(['id', 'meta_fields_key_id', 'enabled', 'data']),
            'images' => collect(self::IMAGES)->mapWithKeys(fn (string $label, string $key): array => [
                $key => filled($general["{$key}_path"] ?? null)
                    ? route('admin.invoiceSettings.images.show', [$property, $key, 'v' => md5((string) $general["{$key}_path"])])
                    : null,
            ])->all(),
        ]);
    }

    public function update(Request $request, Property $property): RedirectResponse
    {
        $settingFieldIds = MetaField::query()->whereIn('key', self::SETTING_KEYS)->pluck('id')->all();
        $generalFieldId = MetaField::query()->where('key', 'invoice_settings_general')->value('id');

        $data = $request->validate([
            'meta_fields' => ['array'],
            'meta_fields.*.enabled' => ['boolean'],
            'meta_fields.*.data' => ['nullable', 'string'],
        ]);

        foreach ($data['meta_fields'] ?? [] as $metaFieldKeyId => $value) {
            if (! in_array((int) $metaFieldKeyId, $settingFieldIds, true)) {
                continue;
            }

            // The uploaded images live in the same settings but aren't part of the form: keep them.
            if ((int) $metaFieldKeyId === (int) $generalFieldId && filled($value['data'] ?? null)) {
                $value['data'] = json_encode([...(json_decode($value['data'], true) ?: []), ...$this->imagePaths($property)]);
            }

            $property->metaFieldValues()->updateOrCreate(
                ['meta_fields_key_id' => $metaFieldKeyId],
                ['enabled' => $value['enabled'] ?? false, 'data' => $value['data'] ?? null],
            );
        }

        return redirect()->route('admin.invoiceSettings.edit', $property)->with('success', 'Invoice settings updated.');
    }

    /**
     * Invoice Settings → Invoice Logo / Digital Signature → Select Image.
     */
    public function storeImage(Request $request, Property $property): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', array_keys(self::IMAGES))],
            'image' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $key = $data['type'];
        $old = $this->imagePaths($property)["{$key}_path"] ?? null;
        $path = $request->file('image')->store("invoice-settings/{$property->id}", 'local');

        $this->saveGeneral($property, ["{$key}_path" => $path]);

        if ($old && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        return back()->with('success', self::IMAGES[$key].' uploaded. It prints on invoices.');
    }

    public function destroyImage(Property $property, string $type): RedirectResponse
    {
        abort_unless(isset(self::IMAGES[$type]), 404);

        $old = $this->imagePaths($property)["{$type}_path"] ?? null;
        $this->saveGeneral($property, ["{$type}_path" => null]);

        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return back()->with('success', self::IMAGES[$type].' removed.');
    }

    /** The image itself, for the settings page's preview. */
    public function showImage(Property $property, string $type): StreamedResponse
    {
        abort_unless(isset(self::IMAGES[$type]), 404);

        $path = $this->imagePaths($property)["{$type}_path"] ?? null;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    /**
     * @return array<string, mixed>
     */
    private function generalSettings(Property $property): array
    {
        return app(PropertySettings::class)->json($property, 'invoice_settings_general') ?? [];
    }

    /**
     * @return array<string, string|null>
     */
    private function imagePaths(Property $property): array
    {
        $general = $this->generalSettings($property);

        return collect(self::IMAGES)->keys()->mapWithKeys(fn (string $key): array => ["{$key}_path" => $general["{$key}_path"] ?? null])->all();
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function saveGeneral(Property $property, array $changes): void
    {
        $field = MetaField::query()->firstOrCreate(['key' => 'invoice_settings_general'], ['key_label' => 'Invoice Settings', 'status' => true]);

        $property->metaFieldValues()->updateOrCreate(
            ['meta_fields_key_id' => $field->id],
            ['enabled' => true, 'data' => json_encode([...$this->generalSettings($property), ...$changes])],
        );
    }
}
