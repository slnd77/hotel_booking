<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountDataRequest;
use App\Services\AccountData;
use App\Services\ApplicationSettings;
use App\Services\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Manage Account → Data & privacy: how long the account keeps personal data, a full export of
 * its data, and the account's deletion.
 */
class DataPrivacyController extends Controller
{
    public function __construct(private ApplicationSettings $settings, private AccountData $data) {}

    public function edit(Request $request): Response
    {
        $deletion = AccountDataRequest::scheduledDeletion()?->load('user:id,name');

        return Inertia::render('admin/data-privacy/Edit', [
            'retention' => $this->settings->get(ApplicationSettings::DATA_RETENTION),
            'exports' => AccountDataRequest::query()->where('type', AccountDataRequest::EXPORT)->with('user:id,name')->latest('id')->limit(10)->get()
                ->map(fn (AccountDataRequest $export): array => [
                    'id' => $export->id,
                    'status' => $export->status,
                    'size' => $export->file_size,
                    'by' => $export->user?->name,
                    'requested_at' => $export->created_at?->toIso8601String(),
                    'expires_at' => $export->expires_at?->toIso8601String(),
                    'error' => $export->error,
                ]),
            'deletion' => $deletion ? [
                'id' => $deletion->id,
                'by' => $deletion->user?->name,
                'scheduled_for' => $deletion->scheduled_for?->toIso8601String(),
            ] : null,
            'graceDays' => AccountData::GRACE_DAYS,
            'exportDays' => AccountData::EXPORT_DAYS,
            'canDelete' => $request->user()->hasAnyRole(PermissionCatalog::ownerRoles()),
            'activityLogs' => $this->settings->logs(ApplicationSettings::DATA_RETENTION),
        ]);
    }

    public function updateRetention(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'guest_data_months' => ['nullable', 'integer', 'min:6', 'max:240'],
            'id_document_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'activity_log_months' => ['nullable', 'integer', 'min:12', 'max:240'],
        ]);
        $data = array_map(fn (mixed $value): ?int => $value === null ? null : (int) $value, $data);

        $describe = fn (?int $value, string $unit): string => $value === null ? 'kept' : "{$value} {$unit}";
        $this->settings->save(ApplicationSettings::DATA_RETENTION, $data, $request->user(), sprintf(
            'Retention set: guest personal data %s, ID documents %s, activity logs %s.',
            $describe($data['guest_data_months'], 'months after the last stay'),
            $describe($data['id_document_days'], 'days after check-out'),
            $describe($data['activity_log_months'], 'months'),
        ));

        return back()->with('success', 'Retention policy saved. It is applied every night.');
    }

    public function storeExport(Request $request): RedirectResponse
    {
        $this->data->requestExport($request->user());
        $this->settings->log(ApplicationSettings::DATA_RETENTION, $request->user(), 'Full account data export requested.');

        return back()->with('success', 'The export is being prepared. Refresh this page in a few minutes to download it.');
    }

    public function downloadExport(Request $request, AccountDataRequest $accountDataRequest): StreamedResponse
    {
        abort_unless($accountDataRequest->type === AccountDataRequest::EXPORT && $accountDataRequest->status === AccountDataRequest::READY, 404);
        abort_unless($accountDataRequest->file_path !== null && Storage::disk('local')->exists($accountDataRequest->file_path), 404);

        $this->settings->log(ApplicationSettings::DATA_RETENTION, $request->user(), "Account data export #{$accountDataRequest->id} downloaded.");

        return Storage::disk('local')->download($accountDataRequest->file_path, 'account-data-'.$accountDataRequest->created_at?->format('Y-m-d').'.zip', ['Cache-Control' => 'private, no-store']);
    }

    /**
     * Schedules the account's deletion: the account owner only, with their password.
     */
    public function scheduleDeletion(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(PermissionCatalog::ownerRoles()), 403, 'Only the account owner can delete the account.');

        $request->validate([
            'password' => ['required', 'current_password'],
            'confirmation' => ['required', 'in:DELETE'],
        ], [
            'confirmation.in' => 'Type DELETE to confirm.',
        ]);

        $deletion = $this->data->scheduleDeletion($request->user());
        $this->settings->log(ApplicationSettings::DATA_RETENTION, $request->user(), 'Account deletion scheduled for '.$deletion->scheduled_for?->toDateString().'.');

        return back()->with('warning', 'The account and all its data will be deleted on '.$deletion->scheduled_for?->toFormattedDateString().'. You can cancel until then.');
    }

    public function cancelDeletion(Request $request, AccountDataRequest $accountDataRequest): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(PermissionCatalog::ownerRoles()), 403, 'Only the account owner can cancel the deletion.');
        abort_unless($accountDataRequest->type === AccountDataRequest::DELETION && $accountDataRequest->status === AccountDataRequest::SCHEDULED, 404);

        $this->data->cancelDeletion($accountDataRequest, $request->user());
        $this->settings->log(ApplicationSettings::DATA_RETENTION, $request->user(), 'Account deletion cancelled.');

        return back()->with('success', 'The account deletion was cancelled.');
    }
}
