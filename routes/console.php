<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bookings:release-expired-holds')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('bookings:night-audit')->everyFifteenMinutes()->withoutOverlapping();

// Waitlist: a safety net behind the inventory hooks (cancellations re-check it at once), and
// expiring requests whose check-in date has passed.
Schedule::command('waitlist:refresh')->hourlyAt(10)->withoutOverlapping();

// Reports e-mailed daily at each one's send time (property time); the queue worker builds them.
Schedule::command('reports:send-scheduled')->everyFiveMinutes()->withoutOverlapping();

// Channel manager: OTA bookings every minute (the Channex webhook makes it near-instant on a
// public site), and a nightly full availability and rate sync to correct any drift.
Schedule::command('channex:sync --pull')->everyMinute()->withoutOverlapping();
Schedule::command('channex:sync --push')->dailyAt('02:30')->withoutOverlapping();

// General ledger: each night that has passed moves its room revenue out of Unearned Room
// Revenue (the night audit does the same when it closes a day; running both is harmless).
Schedule::command('ledger:recognise-revenue')->hourlyAt(20)->withoutOverlapping();

// Operations: the heartbeat and alerts, the nightly backup, and clearing out old rows.
Schedule::command('ops:monitor')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('ops:backup')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=720')->daily();

// Data & privacy: each account's retention policy, expired exports and due account deletions.
Schedule::command('account-data:process')->dailyAt('03:15')->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
