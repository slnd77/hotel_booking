<?php

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\User;
use App\Services\PropertyBranding;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Storage::fake('public');
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->property = Property::factory()->create(['property_contact_number' => '9474751202']);
});

function brandingPayload(array $overrides = []): array
{
    return [
        'website_url' => 'https://www.example.com/',
        'accommodation_nomenclature' => 'Cottage',
        'phone_numbers' => ['9474751202', '', '033 2222 3333'],
        'show_property_name' => true,
        'theme_color' => '#8b7355',
        'header_color' => '#2f4f4f',
        'text_color' => '#ffffff',
        'support_emails' => ['desk@example.com'],
        ...$overrides,
    ];
}

it('saves branding with uploads and stays on the branding tab', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.properties.branding.update', $this->property), brandingPayload([
            'logo' => UploadedFile::fake()->image('logo.png'),
            'banner_image' => UploadedFile::fake()->image('banner.jpg', 1920, 400),
            'new_background_images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ]))
        ->assertRedirect(route('admin.properties.edit', [$this->property, 'tab' => 'branding']));

    $branding = app(PropertyBranding::class)->get($this->property);

    expect($branding)
        ->accommodation_nomenclature->toBe('Cottage')
        ->phone_numbers->toBe(['9474751202', '033 2222 3333'])
        ->theme_color->toBe('#8b7355')
        ->and($branding['background_images'])->toHaveCount(2)
        ->and(collect($branding['background_images'])->every(fn (array $image): bool => $image['selected']))->toBeTrue();

    Storage::disk('public')->assertExists([$branding['logo'], $branding['banner_image'], ...array_column($branding['background_images'], 'path')]);
});

it('removes dropped images from disk and ignores paths it did not save', function () {
    $this->actingAs($this->admin)->post(route('admin.properties.branding.update', $this->property), brandingPayload([
        'logo' => UploadedFile::fake()->image('logo.png'),
        'new_background_images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
    ]));
    $saved = app(PropertyBranding::class)->get($this->property);
    [$kept, $dropped] = array_column($saved['background_images'], 'path');

    $this->actingAs($this->admin)->post(route('admin.properties.branding.update', $this->property), brandingPayload([
        'remove_logo' => true,
        'background_images' => [['path' => $kept, 'selected' => false], ['path' => 'secrets/other.jpg', 'selected' => true]],
    ]))->assertSessionHasNoErrors();

    expect(app(PropertyBranding::class)->get($this->property))
        ->logo->toBeNull()
        ->background_images->toBe([['path' => $kept, 'selected' => false]]);
    Storage::disk('public')->assertMissing([$saved['logo'], $dropped]);
    Storage::disk('public')->assertExists($kept);
});

it('validates colours, contacts and image types', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.properties.branding.update', $this->property), brandingPayload([
            'theme_color' => 'red',
            'support_emails' => ['not-an-email'],
            'logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
        ]))
        ->assertSessionHasErrors(['theme_color', 'support_emails.0', 'logo']);
});

it('logs each changed branding setting and shows the logs on the edit page', function () {
    $this->actingAs($this->admin)->post(route('admin.properties.branding.update', $this->property), brandingPayload());
    $this->actingAs($this->admin)->post(route('admin.properties.branding.update', $this->property), brandingPayload());

    $actions = ActivityLog::query()->where('property_id', $this->property->id)->pluck('action');

    expect($actions)->toContain('Branding: Accommodation Nomenclature changed from Room to Cottage')
        ->and($actions)->toHaveCount(6);

    $this->actingAs($this->admin)
        ->get(route('admin.properties.edit', $this->property))
        ->assertInertia(fn (Assert $page) => $page
            ->where('branding.accommodation_nomenclature', 'Cottage')
            ->has('brandingLogs', 6)
            ->where('metaFields', fn ($fields) => collect($fields)->doesntContain('key', PropertyBranding::KEY)));
});

it('defaults the phone number to the property contact number', function () {
    expect(app(PropertyBranding::class)->get($this->property))
        ->phone_numbers->toBe(['9474751202'])
        ->accommodation_nomenclature->toBe('Room')
        ->show_property_name->toBeTrue();
});
