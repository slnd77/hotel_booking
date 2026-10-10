<?php

use App\Mail\ReportMail;
use App\Models\Currency;
use App\Models\Language;
use App\Models\Property;
use App\Models\ReportDelivery;
use App\Models\User;
use App\Services\ReportSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::firstOrCreate(['name' => 'admin']);
    Currency::firstOrCreate(['code' => 'USD'], ['name' => 'USD']);
    Language::firstOrCreate(['code' => 'en'], ['name' => 'English']);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->property = Property::factory()->create(['timezone' => 'Asia/Kolkata']);
    $this->settings = app(ReportSettings::class);
    $this->settings->saveReport($this->property, 'daily_reports', ['send_report' => true, 'send_time' => '07:00', 'same_as_internal' => false, 'recipients' => ['gm@example.com']]);

    Mail::fake();
    Pdf::fake();
});

test('a report is e-mailed once a day after its send time, for the day before, with its files attached', function () {
    // 06:59 in Kolkata: not yet.
    $this->travelTo(CarbonImmutable::parse('2026-10-10 06:59', 'Asia/Kolkata'));
    $this->artisan('reports:send-scheduled')->assertSuccessful();
    Mail::assertNothingSent();

    $this->travelTo(CarbonImmutable::parse('2026-10-10 07:05', 'Asia/Kolkata'));
    $this->artisan('reports:send-scheduled')->assertSuccessful();
    $this->artisan('reports:send-scheduled')->assertSuccessful();

    Mail::assertSent(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo('gm@example.com')
        && $mail->period === '09 Oct 2026'
        && count($mail->attachments()) === 2);
    Mail::assertSentCount(1);

    $delivery = ReportDelivery::query()->sole();
    expect($delivery->status)->toBe(ReportDelivery::STATUS_SENT)
        ->and($delivery->scheduled_for->toDateString())->toBe('2026-10-10')
        ->and($delivery->sent_at)->not->toBeNull();
});

test('a range report e-mails the month to yesterday', function () {
    $this->settings->saveReport($this->property, 'daily_reports', ['send_report' => false]);
    $this->settings->saveReport($this->property, 'gstr3b_summary_return_report', ['send_report' => true, 'send_time' => '07:00', 'same_as_internal' => false, 'recipients' => ['accounts@example.com']]);

    $this->travelTo(CarbonImmutable::parse('2026-10-10 08:00', 'Asia/Kolkata'));
    $this->artisan('reports:send-scheduled')->assertSuccessful();

    Mail::assertSent(ReportMail::class, fn (ReportMail $mail): bool => $mail->hasTo('accounts@example.com') && $mail->period === '01 Oct 2026 – 09 Oct 2026');
});

test('"E-mail now" queues the report for the chosen date and logs it on the page', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.reports.send', [$this->property, 'daily_reports']), ['date' => '2026-10-05'])
        ->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'gm@example.com'));

    Mail::assertSent(ReportMail::class, fn (ReportMail $mail): bool => $mail->period === '05 Oct 2026');

    $this->actingAs($this->admin)
        ->get(route('admin.reports.edit', $this->property))
        ->assertInertia(fn ($page) => $page
            ->where('deliveries.0.report', 'Daily Reports')
            ->where('deliveries.0.status', 'sent')
            ->where('deliveries.0.scheduled', false)
            ->where('deliveries.0.user', $this->admin->name));
});

test('a report without recipients, or whose file is not built, is not e-mailed', function () {
    $this->settings->saveReport($this->property, 'daily_reports', ['recipients' => []]);

    $this->actingAs($this->admin)
        ->post(route('admin.reports.send', [$this->property, 'daily_reports']), ['date' => '2026-10-05'])
        ->assertSessionHasErrors('status');

    $this->actingAs($this->admin)
        ->post(route('admin.reports.send', [$this->property, 'post_master_report']), ['date' => '2026-10-05'])
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->put(route('admin.reports.status', [$this->property, 'post_master_report']), ['send_report' => true])
        ->assertSessionHasErrors(['status' => "Post Master Report can't be e-mailed yet: its file isn't built."]);

    Mail::assertNothingSent();
    expect(ReportDelivery::query()->count())->toBe(0);
});
