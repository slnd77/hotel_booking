<?php

namespace App\Jobs;

use App\Models\AccountDataRequest;
use App\Services\AccountData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes an export's ZIP once its download days are over (runs as its account).
 */
class DeleteAccountExport implements ShouldQueue
{
    use Queueable;

    public function __construct(public AccountDataRequest $request) {}

    public function handle(AccountData $data): void
    {
        $data->expireExport($this->request);
    }
}
