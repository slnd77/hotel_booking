<?php

namespace App\Jobs;

use App\Models\ApplicationSetting;
use App\Services\DataRetention;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies an account's retention policy; it carries the account's policy setting, so it runs
 * as that account.
 */
class ApplyDataRetention implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(public ApplicationSetting $policy) {}

    public function handle(DataRetention $retention): void
    {
        $retention->apply();
    }
}
