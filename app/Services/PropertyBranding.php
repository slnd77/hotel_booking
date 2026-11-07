<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\MetaField;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Properties → Branding: how the property presents itself on the booking engine (logo,
 * colours, background and banner images, contact details). One JSON document per property
 * in property_meta_fields; images live on the public disk.
 */
class PropertyBranding
{
    public const KEY = 'branding_settings';

    /** Prefix of the activity entries Branding → Activity Logs lists. */
    public const LOG_PREFIX = 'Branding';

    public const IMAGE_DIRECTORY = 'property-branding';

    /** @var array<string, string> */
    public const LABELS = [
        'website_url' => 'Website URL',
        'accommodation_nomenclature' => 'Accommodation Nomenclature',
        'phone_numbers' => 'Phone Number',
        'show_property_name' => 'Show Property Name on Booking Engine',
        'theme_color' => 'Theme Color',
        'header_color' => 'Header Color',
        'text_color' => 'Text Color',
        'logo' => 'Logo',
        'background_images' => 'Background Image',
        'banner_image' => 'Banner Image',
        'support_emails' => 'Support Email ID',
    ];

    /**
     * @return array{website_url: string, accommodation_nomenclature: string, phone_numbers: list<string>, show_property_name: bool, theme_color: string, header_color: string, text_color: string, logo: string|null, background_images: list<array{path: string, selected: bool}>, banner_image: string|null, support_emails: list<string>}
     */
    public function defaults(Property $property): array
    {
        return [
            'website_url' => '',
            'accommodation_nomenclature' => 'Room',
            'phone_numbers' => array_values(array_filter([(string) $property->property_contact_number])),
            'show_property_name' => true,
            'theme_color' => '#f53003',
            'header_color' => '#1b1b18',
            'text_color' => '#ffffff',
            'logo' => null,
            'background_images' => [],
            'banner_image' => null,
            'support_emails' => [],
        ];
    }

    /**
     * The saved branding over the defaults.
     *
     * @return array{website_url: string, accommodation_nomenclature: string, phone_numbers: list<string>, show_property_name: bool, theme_color: string, header_color: string, text_color: string, logo: string|null, background_images: list<array{path: string, selected: bool}>, banner_image: string|null, support_emails: list<string>}
     */
    public function get(Property $property): array
    {
        $defaults = $this->defaults($property);

        return [...$defaults, ...array_intersect_key($this->stored($property), $defaults)];
    }

    /**
     * The branding with public URLs for its images, as the admin page and booking engine use it.
     *
     * @return array<string, mixed>
     */
    public function forDisplay(Property $property): array
    {
        $branding = $this->get($property);
        $disk = Storage::disk('public');

        return [
            ...$branding,
            'logo_url' => $branding['logo'] ? $disk->url($branding['logo']) : null,
            'banner_image_url' => $branding['banner_image'] ? $disk->url($branding['banner_image']) : null,
            'background_images' => array_map(fn (array $image): array => [...$image, 'url' => $disk->url($image['path'])], $branding['background_images']),
        ];
    }

    /**
     * Save the branding form: text and colours, new uploads, and the removal of images
     * the form no longer keeps. Each changed setting is logged.
     *
     * @param  array<string, mixed>  $data  validated input
     */
    public function save(Property $property, array $data, ?User $user = null): void
    {
        $before = $this->get($property);
        $after = [
            ...$before,
            ...array_intersect_key($data, array_flip(['website_url', 'accommodation_nomenclature', 'theme_color', 'header_color', 'text_color'])),
            'website_url' => (string) ($data['website_url'] ?? ''),
            'show_property_name' => (bool) ($data['show_property_name'] ?? false),
            'phone_numbers' => $this->filled($data['phone_numbers'] ?? []),
            'support_emails' => $this->filled($data['support_emails'] ?? []),
        ];

        $after['logo'] = $this->replaceImage($before['logo'], $data['logo'] ?? null, (bool) ($data['remove_logo'] ?? false));
        $after['banner_image'] = $this->replaceImage($before['banner_image'], $data['banner_image'] ?? null, (bool) ($data['remove_banner_image'] ?? false));
        $after['background_images'] = $this->backgroundImages($before['background_images'], $data['background_images'] ?? [], $data['new_background_images'] ?? []);

        $this->store($property, $after);

        if ($user === null) {
            return;
        }

        foreach (self::LABELS as $key => $label) {
            if ($before[$key] !== $after[$key]) {
                ActivityLog::create([
                    'user_id' => $user->id,
                    'property_id' => $property->id,
                    'action' => self::LOG_PREFIX.': '.$label.' '.$this->describeChange($key, $before[$key], $after[$key]),
                    'date' => now(),
                ]);
            }
        }
    }

    /**
     * @return Collection<int, array{id: int, action: string, user: string|null, date: string|null}>
     */
    public function logs(Property $property, int $limit = 100): Collection
    {
        return ActivityLog::query()
            ->where('property_id', $property->id)
            ->whereLike('action', self::LOG_PREFIX.':%')
            ->with('user:id,name')
            ->latest('date')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (ActivityLog $log): array => [
                'id' => $log->id,
                'action' => substr($log->action, strlen(self::LOG_PREFIX) + 2),
                'user' => $log->user?->name,
                'date' => $log->date?->toIso8601ZuluString(),
            ]);
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function filled(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(fn (mixed $value): string => trim((string) $value), $values))));
    }

    private function replaceImage(?string $current, ?UploadedFile $upload, bool $remove): ?string
    {
        if ($upload === null && ! $remove) {
            return $current;
        }

        if ($current !== null) {
            Storage::disk('public')->delete($current);
        }

        return $upload?->store(self::IMAGE_DIRECTORY, 'public');
    }

    /**
     * The kept background images (only paths already saved, so the form cannot point at
     * other files), then the new uploads, shown by default. Dropped images are deleted.
     *
     * @param  list<array{path: string, selected: bool}>  $current
     * @param  array<int, array{path?: string, selected?: mixed}>  $kept
     * @param  array<int, UploadedFile>  $uploads
     * @return list<array{path: string, selected: bool}>
     */
    private function backgroundImages(array $current, array $kept, array $uploads): array
    {
        $currentPaths = array_column($current, 'path');
        $images = collect($kept)
            ->filter(fn (array $image): bool => in_array($image['path'] ?? null, $currentPaths, true))
            ->unique('path')
            ->map(fn (array $image): array => ['path' => (string) $image['path'], 'selected' => (bool) ($image['selected'] ?? false)])
            ->values()
            ->all();

        Storage::disk('public')->delete(array_values(array_diff($currentPaths, array_column($images, 'path'))));

        foreach ($uploads as $upload) {
            $images[] = ['path' => $upload->store(self::IMAGE_DIRECTORY, 'public'), 'selected' => true];
        }

        return $images;
    }

    private function describeChange(string $key, mixed $before, mixed $after): string
    {
        return match ($key) {
            'logo', 'banner_image' => match (true) {
                $after === null => 'removed',
                $before === null => 'uploaded',
                default => 'replaced',
            },
            'background_images' => 'changed ('.count($after).' image'.(count($after) === 1 ? '' : 's').', '.count(array_filter(array_column($after, 'selected'))).' shown)',
            default => 'changed from '.$this->display($before).' to '.$this->display($after),
        };
    }

    private function display(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' || $value === [] => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            is_array($value) => implode(', ', $value),
            default => (string) $value,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(Property $property): array
    {
        return app(PropertySettings::class)->json($property, self::KEY) ?? [];
    }

    /**
     * @param  array<string, mixed>  $branding
     */
    private function store(Property $property, array $branding): void
    {
        $fieldId = MetaField::query()->firstOrCreate(['key' => self::KEY], ['key_label' => 'Branding', 'status' => true])->id;
        $property->metaFieldValues()->updateOrCreate(['meta_fields_key_id' => $fieldId], ['enabled' => true, 'data' => json_encode($branding)]);
    }
}
