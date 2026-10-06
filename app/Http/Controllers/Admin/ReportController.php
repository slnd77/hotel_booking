<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\User;
use App\Services\NightAuditSettings;
use App\Services\PermissionGate;
use App\Services\ReportDeliveries;
use App\Services\ReportFiles;
use App\Services\ReportSettings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\LaravelPdf\PdfBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports: the property's report list, and each report's email settings
 * (subject, heading, send time, whether it is sent and to whom).
 */
class ReportController extends Controller
{
    public function __construct(private ReportSettings $settings, private ReportFiles $files) {}

    public function index(): Response
    {
        return Inertia::render('admin/reports/Index');
    }

    public function edit(Property $property): Response
    {
        $settings = $this->settings->get($property);
        $today = CarbonImmutable::parse($property->today());

        return Inertia::render('admin/reports/Edit', [
            'property' => $property->only(['id', 'name']),
            'reports' => collect(ReportSettings::REPORTS)->map(fn (array $report, string $key): array => [
                'key' => $key,
                'dates' => $report['dates'],
                'staff' => $report['staff'],
                'downloads' => $report['downloads'],
                // The download types already built; the rest show as "not built yet".
                'available_downloads' => $this->files->types($key),
                'columns' => collect(ReportSettings::COLUMNS[$key] ?? [])->map(fn (string $label, string $column): array => ['key' => $column, 'label' => $label])->values(),
                'settings' => [
                    ...$settings['reports'][$key],
                    'recipients' => $this->settings->recipientsFor($property, $key),
                ],
            ])->values(),
            'internalRecipients' => $settings['internal_recipients'],
            'today' => $today->toDateString(),
            // Night audit → Restrict Reports: dated reports stop at the last closed business date.
            'closedUntil' => app(NightAuditSettings::class)->reportsClosedUntil($property)?->toDateString(),
            'restrictable' => collect(ReportSettings::REPORTS)->keys()->filter(fn (string $key): bool => $this->files->isRestrictable($key))->values(),
            'monthStart' => $today->startOfMonth()->toDateString(),
            'monthEnd' => $today->endOfMonth()->toDateString(),
            'staff' => User::query()->orderBy('name')->get(['id', 'name']),
            'activityLogs' => $this->settings->logs($property),
            'deliveries' => app(ReportDeliveries::class)->recent($property),
        ]);
    }

    public function update(Request $request, Property $property, string $report): RedirectResponse
    {
        abort_unless(isset(ReportSettings::REPORTS[$report]), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email_subject' => ['required', 'string', 'max:255'],
            'heading' => ['required', 'string', 'max:255'],
            'send_time' => ['required', 'date_format:H:i'],
            'send_report' => ['boolean'],
            'same_as_internal' => ['boolean'],
            'recipients' => ['array', 'max:20'],
            'recipients.*' => ['required', 'email:rfc', 'max:255', 'distinct:ignore_case'],
            'description' => ['nullable', 'string', 'max:2000'],
        ], [
            'recipients.*.email' => 'Enter a valid email address.',
            'recipients.*.distinct' => 'This email address is listed twice.',
        ]);

        $data['description'] = (string) ($data['description'] ?? '');
        $data['recipients'] = array_values($data['recipients'] ?? []);

        if (($data['send_report'] ?? false) && $data['recipients'] === []) {
            return back()->withErrors(['recipients' => 'Add at least one email recipient to send this report.']);
        }

        if (($data['send_report'] ?? false) && $this->files->types($report) === []) {
            return back()->withErrors(['send_report' => ReportSettings::REPORTS[$report]['name']." can't be e-mailed yet: its file isn't built."]);
        }

        $this->settings->saveReport($property, $report, $data, $request->user());

        return back()->with('success', ReportSettings::REPORTS[$report]['name'].' settings updated.');
    }

    /**
     * The report's Excel or PDF download for the row's date(s) (the property's business dates).
     */
    public function download(Request $request, Property $property, string $report): StreamedResponse|PdfBuilder
    {
        $types = $this->files->types($report);
        abort_if($types === [], 404);

        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'type' => ['nullable', Rule::in($types)],
            'staff' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $date = CarbonImmutable::parse($data['date'] ?? $property->today());
        $type = $data['type'] ?? 'excel';
        app(PermissionGate::class)->require($request->user(), $type === 'pdf' ? 'manage_property.report_download_pdf' : 'manage_property.report_download_excel');

        try {
            [$file, $fileName] = $this->files->make(
                $property,
                $report,
                $type,
                $date,
                isset($data['end_date']) ? CarbonImmutable::parse($data['end_date']) : null,
                isset($data['staff']) ? User::query()->find($request->integer('staff')) : null,
            );
        } catch (ValidationException $exception) {
            abort(422, collect($exception->errors())->flatten()->first());
        }

        if ($file instanceof PdfBuilder) {
            return $file->download();
        }

        return response()->streamDownload(function () use ($file): void {
            (new Xlsx($file))->save('php://output');
        }, $this->files->safeFileName($fileName), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * "E-mail now": queues the report for the row's date(s) to the report's recipients.
     */
    public function send(Request $request, Property $property, string $report, ReportDeliveries $deliveries): RedirectResponse
    {
        $types = $this->files->types($report);
        abort_if($types === [], 404);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
            'staff' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $recipients = $this->settings->recipientsFor($property, $report);

        if ($recipients === []) {
            return back()->withErrors(['status' => 'Add an email recipient (Edit) before e-mailing '.ReportSettings::REPORTS[$report]['name'].'.']);
        }

        try {
            $this->files->ensureReportable($property, $report, CarbonImmutable::parse($data['date']), isset($data['end_date']) ? CarbonImmutable::parse($data['end_date']) : null);
        } catch (ValidationException $exception) {
            return back()->withErrors(['status' => collect($exception->errors())->flatten()->first()]);
        }

        $deliveries->queue(
            $property,
            $report,
            CarbonImmutable::parse($data['date']),
            $this->files->isRange($report) ? CarbonImmutable::parse($data['end_date'] ?? $data['date']) : null,
            isset($data['staff']) ? (int) $data['staff'] : null,
            $request->user(),
        );

        return back()->with('success', ReportSettings::REPORTS[$report]['name'].' will be e-mailed to '.implode(', ', $recipients).' shortly.');
    }

    /**
     * "Select Columns": which columns the report's download leaves out.
     */
    public function columns(Request $request, Property $property, string $report): RedirectResponse
    {
        $columns = array_keys(ReportSettings::COLUMNS[$report] ?? []);

        abort_if($columns === [], 404);

        $hidden = $request->validate([
            'hidden_columns' => ['present', 'array', 'max:'.(count($columns) - 1)],
            'hidden_columns.*' => ['string', Rule::in($columns), 'distinct'],
        ], [
            'hidden_columns.max' => 'Keep at least one column in the download.',
        ])['hidden_columns'];

        // Kept in catalogue order so the activity log reads the same way every time.
        $this->settings->saveReport($property, $report, ['hidden_columns' => array_values(array_intersect($columns, $hidden))], $request->user());

        return back()->with('success', ReportSettings::REPORTS[$report]['name'].' columns updated.');
    }

    /**
     * The Status tick on the report list: whether the report is emailed.
     */
    public function status(Request $request, Property $property, string $report): RedirectResponse
    {
        abort_unless(isset(ReportSettings::REPORTS[$report]), 404);

        $sendReport = $request->validate(['send_report' => ['required', 'boolean']])['send_report'];

        if ($sendReport && $this->files->types($report) === []) {
            return back()->withErrors(['status' => ReportSettings::REPORTS[$report]['name']." can't be e-mailed yet: its file isn't built."]);
        }

        if ($sendReport && $this->settings->recipientsFor($property, $report) === []) {
            return back()->withErrors(['status' => 'Add an email recipient (Edit) before turning on '.ReportSettings::REPORTS[$report]['name'].'.']);
        }

        $this->settings->saveReport($property, $report, ['send_report' => (bool) $sendReport], $request->user());

        return back()->with('success', ReportSettings::REPORTS[$report]['name'].($sendReport ? ' will be emailed.' : ' will no longer be emailed.'));
    }
}
