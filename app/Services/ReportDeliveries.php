<?php

namespace App\Services;

use App\Jobs\SendReportEmail;
use App\Mail\ReportMail;
use App\Models\Property;
use App\Models\ReportDelivery;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * E-mailed reports: the daily scheduled sends (at each report's send time, property time)
 * and "E-mail now". Each is recorded, then built and sent by the queue worker.
 */
class ReportDeliveries
{
    public function __construct(private ReportSettings $settings, private ReportFiles $files) {}

    /**
     * Queues one report for its recipients now.
     */
    public function queue(Property $property, string $report, CarbonImmutable $date, ?CarbonImmutable $endDate = null, ?int $staffId = null, ?User $user = null): ReportDelivery
    {
        $delivery = ReportDelivery::query()->create([
            'property_id' => $property->id,
            'report' => $report,
            'report_date' => $date->toDateString(),
            'report_end_date' => $endDate?->toDateString(),
            'staff_id' => $staffId,
            'user_id' => $user?->id,
            'recipients' => $this->settings->recipientsFor($property, $report),
            'status' => ReportDelivery::STATUS_QUEUED,
        ]);

        SendReportEmail::dispatch($delivery)->afterCommit();

        return $delivery;
    }

    /**
     * Queues the property's reports whose send time has passed today (property time) and
     * that haven't gone out today. A single-date report covers yesterday; a range report the
     * month to yesterday. Returns how many were queued.
     */
    public function queueDue(Property $property): int
    {
        $now = $property->localTime();
        $yesterday = $now->subDay()->startOfDay();
        $queued = 0;

        foreach ($this->settings->get($property)['reports'] as $report => $reportSettings) {
            if (! $reportSettings['send_report'] || $this->files->types($report) === [] || $now->format('H:i') < $reportSettings['send_time']) {
                continue;
            }

            $recipients = $this->settings->recipientsFor($property, $report);

            if ($recipients === []) {
                continue;
            }

            $isRange = $this->files->isRange($report);

            // Restrict Reports: a day the night audit hasn't closed yet goes out on a later run, once it has.
            try {
                $this->files->ensureReportable($property, $report, $isRange ? $yesterday->startOfMonth() : $yesterday, $isRange ? $yesterday : null);
            } catch (ValidationException) {
                continue;
            }

            try {
                $delivery = ReportDelivery::query()->create([
                    'property_id' => $property->id,
                    'report' => $report,
                    'report_date' => ($isRange ? $yesterday->startOfMonth() : $yesterday)->toDateString(),
                    'report_end_date' => $isRange ? $yesterday->toDateString() : null,
                    'scheduled_for' => $now->toDateString(),
                    'recipients' => $recipients,
                    'status' => ReportDelivery::STATUS_QUEUED,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Already queued today, by this run or an earlier one.
                continue;
            }

            SendReportEmail::dispatch($delivery);
            $queued++;
        }

        return $queued;
    }

    /**
     * Builds the report's files and e-mails them (the queued job).
     */
    public function send(ReportDelivery $delivery): void
    {
        $delivery->loadMissing('property', 'staff');
        $property = $delivery->property;
        $reportSettings = $this->settings->get($property)['reports'][$delivery->report];

        $attachments = collect($this->files->types($delivery->report))->map(function (string $type) use ($delivery, $property): Attachment {
            [$file, $fileName] = $this->files->make($property, $delivery->report, $type, $delivery->report_date, $delivery->report_end_date, $delivery->staff);
            $contents = $this->files->contents($file);

            return Attachment::fromData(fn (): string => $contents, $this->files->safeFileName($fileName))
                ->withMime($type === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        })->all();

        Mail::to($delivery->recipients)->send(new ReportMail(
            $property->name,
            $reportSettings['email_subject'],
            $reportSettings['heading'],
            $reportSettings['description'],
            $this->period($delivery),
            $attachments,
        ));

        $delivery->update(['status' => ReportDelivery::STATUS_SENT, 'error' => null, 'sent_at' => now()]);
    }

    /**
     * The Reports page's e-mail log.
     *
     * @return Collection<int, array{id: int, report: string, period: string, recipients: list<string>, status: string, error: string|null, scheduled: bool, user: string|null, at: string|null}>
     */
    public function recent(Property $property, int $limit = 30): Collection
    {
        return ReportDelivery::query()
            ->where('property_id', $property->id)
            ->with('user:id,name')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (ReportDelivery $delivery): array => [
                'id' => $delivery->id,
                'report' => ReportSettings::REPORTS[$delivery->report]['name'] ?? $delivery->report,
                'period' => $this->period($delivery),
                'recipients' => $delivery->recipients,
                'status' => $delivery->status,
                'error' => $delivery->error,
                'scheduled' => $delivery->scheduled_for !== null,
                'user' => $delivery->user?->name,
                'at' => ($delivery->sent_at ?? $delivery->created_at)?->toIso8601ZuluString(),
            ]);
    }

    private function period(ReportDelivery $delivery): string
    {
        $from = $delivery->report_date->format('d M Y');

        return $delivery->report_end_date && ! $delivery->report_end_date->isSameDay($delivery->report_date)
            ? $from.' – '.$delivery->report_end_date->format('d M Y')
            : $from;
    }
}
