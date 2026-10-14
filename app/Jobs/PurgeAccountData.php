<?php

namespace App\Jobs;

use App\Models\AccountDataRequest;
use App\Services\AccountData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deletes all the account's data once its deletion date has come (runs as that account).
 */
class PurgeAccountData implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public AccountDataRequest $request) {}

    public function handle(AccountData $data): void
    {
        $data->purge($this->request->fresh() ?? $this->request);
    }
}
