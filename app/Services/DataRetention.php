<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ApplicationSettingLog;
use App\Models\Guest;
use App\Models\WebCheckin;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Manage Account → Data & privacy → Retention: the account's own limits on how long it keeps
 * guests' personal data, web check-in ID documents and activity logs (GDPR / DPDP storage
 * limitation). Bookings, invoices and payments stay (tax law); erased guests keep them under
 * an anonymous name.
 */
class DataRetention
{
    public function __construct(private ApplicationSettings $settings, private GuestPrivacy $privacy) {}

    /**
     * Applies the account's retention limits.
     *
     * @return array{guests: int, id_documents: int, activity_logs: int}
     */
    public function apply(): array
    {
        $policy = $this->settings->get(ApplicationSettings::DATA_RETENTION);

        $done = [
            'guests' => $policy['guest_data_months'] ? $this->eraseGuests((int) $policy['guest_data_months']) : 0,
            'id_documents' => $policy['id_document_days'] ? $this->deleteIdDocuments((int) $policy['id_document_days']) : 0,
            'activity_logs' => $policy['activity_log_months'] ? $this->deleteActivityLogs((int) $policy['activity_log_months']) : 0,
        ];

        if (array_sum($done) > 0) {
            $this->settings->log(ApplicationSettings::DATA_RETENTION, null, "Retention policy applied: {$done['guests']} guest(s)' personal data erased, {$done['id_documents']} ID document(s) deleted, {$done['activity_logs']} activity log entr(ies) deleted.");
        }

        return $done;
    }

    /**
     * Guests whose last stay (or, with none, whose record) is older than the limit and who have
     * nothing still to come.
     */
    private function eraseGuests(int $months): int
    {
        $cutoff = now()->subMonths($months);
        $erased = 0;

        Guest::query()
            ->where('created_at', '<', $cutoff)
            ->whereNot(fn ($query) => $query->where('first_name', 'Erased')->where('last_name', 'like', 'Guest #%'))
            ->whereDoesntHave('bookings', fn ($query) => $query->where('check_out', '>=', $cutoff->toDateString()))
            ->eachById(function (Guest $guest) use (&$erased): void {
                try {
                    $this->privacy->erase($guest, null, 'by the retention policy');
                    $erased++;
                } catch (ValidationException) {
                    // A booking of theirs is still open (e.g. never checked out): kept for now.
                }
            });

        return $erased;
    }

    /**
     * ID documents and signatures from web check-in, once the stay ended that many days ago.
     */
    private function deleteIdDocuments(int $days): int
    {
        $deleted = 0;

        WebCheckin::query()
            ->where(fn ($query) => $query->whereNotNull('id_document_path')->orWhereNotNull('signature_path'))
            ->whereHas('booking', fn ($query) => $query->where('check_out', '<', now()->subDays($days)->toDateString()))
            ->eachById(function (WebCheckin $checkin) use (&$deleted): void {
                Storage::disk('local')->delete(array_values(array_filter([$checkin->id_document_path, $checkin->signature_path])));
                $checkin->forceFill(['id_document_path' => null, 'signature_path' => null])->saveQuietly();
                $deleted++;
            });

        return $deleted;
    }

    private function deleteActivityLogs(int $months): int
    {
        $cutoff = now()->subMonths($months);

        return ActivityLog::query()->where('created_at', '<', $cutoff)->delete()
            + ApplicationSettingLog::query()->where('created_at', '<', $cutoff)->delete();
    }
}
