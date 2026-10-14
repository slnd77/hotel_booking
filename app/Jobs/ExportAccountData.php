<?php

namespace App\Jobs;

use App\Models\AccountDataRequest;
use App\Services\AccountData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Builds the ZIP of all the account's data (runs as the account the request belongs to).
 */
class ExportAccountData implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public AccountDataRequest $request) {}

    public function handle(AccountData $data): void
    {
        $data->export($this->request);
    }
}
