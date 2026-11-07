<?php

namespace App\Console\Commands;

use App\Services\Ops\HealthChecks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

#[Signature('ops:monitor')]
#[Description('Record the scheduler heartbeat and alert (critical log + e-mail to OPS_ALERT_EMAIL, once an hour per problem) when the queue, scheduler, backups, failed jobs or disk need attention')]
class MonitorCommand extends Command
{
    public function handle(HealthChecks $health): int
    {
        $health->beat();
        $problems = collect($health->run())->reject(fn (array $check): bool => $check['ok']);

        foreach ($problems as $name => $check) {
            $this->warn("{$name}: {$check['detail']}");

            // One alert an hour per problem, not one every five minutes.
            if (! Cache::add("ops:alerted:{$name}", true, now()->addHour())) {
                continue;
            }

            Log::critical("Health check failed: {$name}", ['detail' => $check['detail'], 'critical' => $check['critical']]);

            if ($to = config('ops.alert_email')) {
                try {
                    Mail::raw(config('app.name')." ({$this->appUrl()}): {$name} needs attention.\n\n{$check['detail']}", fn ($message) => $message
                        ->to($to)
                        ->subject('['.config('app.name').'] '.($check['critical'] ? 'DOWN' : 'Warning').": {$name}"));
                } catch (Throwable $exception) {
                    Log::critical('Could not e-mail the health alert: '.$exception->getMessage());
                }
            }
        }

        if ($problems->isEmpty()) {
            $this->info('All checks pass.');
        }

        return self::SUCCESS;
    }

    private function appUrl(): string
    {
        return (string) config('app.url');
    }
}
