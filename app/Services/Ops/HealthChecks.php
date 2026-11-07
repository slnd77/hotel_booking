<?php

namespace App\Services\Ops;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * What production needs running, checked for /health and ops:monitor: the database and cache
 * answer, a queue worker is taking jobs, the scheduler is alive, jobs aren't failing, a recent
 * backup exists and the disk isn't full. A failed critical check means the site is down or
 * about to be (/health answers 503); the others need attention.
 */
class HealthChecks
{
    /** The scheduler writes this every time ops:monitor runs. */
    public const HEARTBEAT_KEY = 'ops:scheduler-heartbeat';

    /**
     * @return array<string, array{ok: bool, critical: bool, detail: string}>
     */
    public function run(): array
    {
        return [
            'database' => $this->guard(true, function (): array {
                DB::select('select 1');

                return [true, 'Connected.'];
            }),
            'cache' => $this->guard(true, function (): array {
                Cache::put('ops:health-probe', 'ok', 60);

                return [Cache::get('ops:health-probe') === 'ok', 'Read and write.'];
            }),
            'queue' => $this->guard(true, fn (): array => $this->queue()),
            'scheduler' => $this->guard(true, fn (): array => $this->scheduler()),
            'failed_jobs' => $this->guard(false, fn (): array => $this->failedJobs()),
            'backup' => $this->guard(false, fn (): array => $this->backup()),
            'disk' => $this->guard(false, fn (): array => $this->disk()),
        ];
    }

    /**
     * Whether every critical check passed.
     *
     * @param  array<string, array{ok: bool, critical: bool, detail: string}>  $checks
     */
    public static function healthy(array $checks): bool
    {
        return collect($checks)->every(fn (array $check): bool => $check['ok'] || ! $check['critical']);
    }

    public function beat(): void
    {
        Cache::forever(self::HEARTBEAT_KEY, now()->toIso8601String());
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function queue(): array
    {
        if (config('queue.default') !== 'database') {
            return [true, 'Queue driver '.config('queue.default').'.'];
        }

        $connection = config('queue.connections.database.connection');
        $jobs = DB::connection($connection)->table(config('queue.connections.database.table', 'jobs'));
        $waitingSince = (clone $jobs)->whereNull('reserved_at')->min('available_at');
        $waiting = (clone $jobs)->whereNull('reserved_at')->count();
        $limit = config('ops.thresholds.queue_wait_minutes');

        if ($waitingSince !== null && now()->timestamp - (int) $waitingSince > $limit * 60) {
            return [false, "{$waiting} job(s) waiting, the oldest for over {$limit} minutes: is the queue worker running?"];
        }

        return [true, "{$waiting} job(s) waiting."];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function scheduler(): array
    {
        $beat = Cache::get(self::HEARTBEAT_KEY);
        $limit = config('ops.thresholds.scheduler_stale_minutes');

        if ($beat === null) {
            return [false, 'The scheduler has not run yet: add the cron entry (deploy/crontab).'];
        }

        $minutes = (int) CarbonImmutable::parse($beat)->diffInMinutes(now(), true);

        return $minutes > $limit
            ? [false, "The scheduler last ran {$minutes} minutes ago: check the cron entry."]
            : [true, "Last ran {$minutes} minute(s) ago."];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function failedJobs(): array
    {
        $failed = DB::connection(config('queue.failed.database'))->table(config('queue.failed.table', 'failed_jobs'))
            ->where('failed_at', '>=', now()->subDay())
            ->count();

        return $failed > 0
            ? [false, "{$failed} job(s) failed in the last 24 hours (php artisan queue:failed)."]
            : [true, 'No failed jobs in the last 24 hours.'];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function backup(): array
    {
        $disk = Storage::disk(config('ops.backup.disk'));
        $latest = collect($disk->files('backups'))->filter(fn (string $path): bool => str_ends_with($path, '.sql.gz'))
            ->map(fn (string $path): int => $disk->lastModified($path))
            ->max();
        $limit = config('ops.thresholds.backup_stale_hours');

        if ($latest === null) {
            return [false, 'No database backup yet (php artisan ops:backup).'];
        }

        $hours = (int) floor((now()->timestamp - $latest) / 3600);

        return $hours > $limit
            ? [false, "The last database backup is {$hours} hours old."]
            : [true, "Last database backup {$hours} hour(s) ago."];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function disk(): array
    {
        $total = @disk_total_space(storage_path());
        $free = @disk_free_space(storage_path());

        if (! $total || $free === false) {
            return [true, 'Disk space unknown.'];
        }

        $percent = (int) round($free / $total * 100);

        return $percent < config('ops.thresholds.disk_free_percent')
            ? [false, "Only {$percent}% of the disk is free."]
            : [true, "{$percent}% free."];
    }

    /**
     * @param  callable(): array{0: bool, 1: string}  $check
     * @return array{ok: bool, critical: bool, detail: string}
     */
    private function guard(bool $critical, callable $check): array
    {
        try {
            [$ok, $detail] = $check();
        } catch (Throwable $exception) {
            [$ok, $detail] = [false, $exception->getMessage()];
        }

        return ['ok' => $ok, 'critical' => $critical, 'detail' => $detail];
    }
}
