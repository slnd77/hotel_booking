<?php

use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\User;
use App\Services\ReportSettings;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create(['timezone' => 'Asia/Kolkata']);
    $this->settings = app(ReportSettings::class);
});

function reportPayload(array $overrides = []): array
{
    return [
        'name' => 'Daily Report',
        'email_subject' => 'Daily Front Office Report',
        'heading' => 'Daily Front Office Report',
        'send_time' => '05:30',
        'send_report' => true,
        'same_as_internal' => true,
        'recipients' => ['frontoffice@example.com', 'manager@example.com'],
        'description' => '',
        ...$overrides,
    ];
}

it('lists every report with its default email settings', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.reports.edit', $this->property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/reports/Edit')
            ->has('reports', count(ReportSettings::REPORTS))
            ->where('reports.0.key', 'daily_reports')
            ->where('reports.0.settings.email_subject', 'Daily Front Office Report')
            ->where('reports.0.settings.send_report', false)
        );
});

it('saves a report into the shared internal recipients and logs the change', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.reports.update', [$this->property, 'daily_reports']), reportPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $settings = $this->settings->get($this->property);

    expect($settings['reports']['daily_reports'])->toMatchArray(['name' => 'Daily Report', 'send_report' => true, 'send_time' => '05:30'])
        ->and($settings['internal_recipients'])->toBe(['frontoffice@example.com', 'manager@example.com'])
        ->and($this->settings->recipientsFor($this->property, 'daily_sales_reports'))->toBe(['frontoffice@example.com', 'manager@example.com'])
        ->and(ActivityLog::query()->where('action', 'like', 'Report settings: Daily Reports — Send this report%')->exists())->toBeTrue();
});

it('keeps a report\'s own recipients apart from the internal ones', function () {
    $this->actingAs($this->admin)->put(route('admin.reports.update', [$this->property, 'daily_reports']), reportPayload());
    $this->actingAs($this->admin)->put(route('admin.reports.update', [$this->property, 'daily_sales_reports']), reportPayload([
        'name' => 'Daily Sales Reports',
        'same_as_internal' => false,
        'recipients' => ['sales@example.com'],
    ]));

    expect($this->settings->recipientsFor($this->property, 'daily_sales_reports'))->toBe(['sales@example.com'])
        ->and($this->settings->get($this->property)['internal_recipients'])->toBe(['frontoffice@example.com', 'manager@example.com']);
});

it('rejects invalid recipients and sending to nobody', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.reports.update', [$this->property, 'daily_reports']), reportPayload(['recipients' => ['not-an-email']]))
        ->assertSessionHasErrors('recipients.0');

    $this->actingAs($this->admin)
        ->put(route('admin.reports.update', [$this->property, 'daily_reports']), reportPayload(['recipients' => []]))
        ->assertSessionHasErrors('recipients');

    expect($this->settings->get($this->property)['reports']['daily_reports']['send_report'])->toBeFalse();
});

it('toggles the status without touching the recipients', function () {
    $this->actingAs($this->admin)->put(route('admin.reports.update', [$this->property, 'daily_reports']), reportPayload(['send_report' => false]));

    $this->actingAs($this->admin)
        ->put(route('admin.reports.status', [$this->property, 'daily_reports']), ['send_report' => true])
        ->assertSessionHasNoErrors();

    expect($this->settings->get($this->property)['reports']['daily_reports']['send_report'])->toBeTrue()
        ->and($this->settings->get($this->property)['internal_recipients'])->toBe(['frontoffice@example.com', 'manager@example.com']);
});

it('refuses to turn on a report that has no recipients', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.reports.status', [$this->property, 'daily_reports']), ['send_report' => true])
        ->assertSessionHasErrors('status');

    expect($this->settings->get($this->property)['reports']['daily_reports']['send_report'])->toBeFalse();
});

it('404s for an unknown report', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.reports.status', [$this->property, 'nope']), ['send_report' => true])
        ->assertNotFound();
});

it('saves the columns a report download leaves out', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.reports.columns', [$this->property, 'daily_reports']), ['hidden_columns' => ['guest_email', 's_no']])
        ->assertSessionHasNoErrors();

    expect($this->settings->get($this->property)['reports']['daily_reports']['hidden_columns'])->toBe(['s_no', 'guest_email']);

    $this->actingAs($this->admin)
        ->get(route('admin.reports.edit', $this->property))
        ->assertInertia(fn (Assert $page) => $page
            ->has('reports.0.columns', count(ReportSettings::COLUMNS['daily_reports']))
            ->where('reports.0.settings.hidden_columns', ['s_no', 'guest_email'])
        );
});

it('rejects unknown columns, hiding every column, and reports without columns', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.reports.columns', [$this->property, 'daily_reports']), ['hidden_columns' => ['nope']])
        ->assertSessionHasErrors('hidden_columns.0');

    $this->actingAs($this->admin)
        ->put(route('admin.reports.columns', [$this->property, 'daily_reports']), ['hidden_columns' => array_keys(ReportSettings::COLUMNS['daily_reports'])])
        ->assertSessionHasErrors('hidden_columns');

    $this->actingAs($this->admin)
        ->put(route('admin.reports.columns', [$this->property, 'gstr1_sales_register_report']), ['hidden_columns' => []])
        ->assertNotFound();
});
