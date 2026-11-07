<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backups (php artisan ops:backup, scheduled daily)
    |--------------------------------------------------------------------------
    |
    | The database dump (and, with BACKUP_INCLUDE_FILES, the uploaded files: ID documents,
    | attachments, logos) is written to this filesystem disk under backups/. Point it at an
    | off-server disk (e.g. an "s3" disk) in production; the local disk is lost with the server.
    */

    'backup' => [
        'disk' => env('BACKUP_DISK', 'local'),
        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),
        'include_files' => (bool) env('BACKUP_INCLUDE_FILES', true),
        // Where mysqldump / pg_dump live when they aren't on the PATH.
        'dump_binary_path' => env('BACKUP_DUMP_BINARY_PATH', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring (php artisan ops:monitor every five minutes, and GET /health)
    |--------------------------------------------------------------------------
    |
    | Problems are logged as critical (LOG_STACK with "slack" sends them to Slack) and e-mailed
    | to OPS_ALERT_EMAIL, once an hour per problem. /health answers 200 or 503 for an uptime
    | monitor; with the X-Health-Token header (or ?token=) it also lists each check.
    */

    'alert_email' => env('OPS_ALERT_EMAIL'),
    'health_token' => env('HEALTH_CHECK_TOKEN'),

    'thresholds' => [
        // A queued job waiting longer than this means no worker is running.
        'queue_wait_minutes' => (int) env('OPS_QUEUE_WAIT_MINUTES', 10),
        // The scheduler writes a heartbeat every five minutes.
        'scheduler_stale_minutes' => (int) env('OPS_SCHEDULER_STALE_MINUTES', 15),
        'backup_stale_hours' => (int) env('OPS_BACKUP_STALE_HOURS', 26),
        'disk_free_percent' => (int) env('OPS_DISK_FREE_PERCENT', 10),
    ],

];
