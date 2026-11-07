<?php

namespace App\Console\Commands;

use App\Jobs\ApplyDataRetention;
use App\Jobs\DeleteAccountExport;
use App\Jobs\PurgeAccountData;
use App\Models\AccountDataRequest;
use App\Models\ApplicationSetting;
use App\Services\ApplicationSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('account-data:process')]
#[Description('Apply each account\'s data retention policy, remove expired exports and run account deletions that are due')]
class ProcessAccountData extends Command
{
    /**
     * Each job carries a row of its account, so with several business accounts it runs as that
     * account and touches only its data.
     */
    public function handle(): int
    {
        $policies = ApplicationSetting::query()->where('key', ApplicationSettings::DATA_RETENTION)->get();
        $policies->each(fn (ApplicationSetting $policy) => ApplyDataRetention::dispatch($policy));

        $exports = AccountDataRequest::query()->where('type', AccountDataRequest::EXPORT)->where('status', AccountDataRequest::READY)->where('expires_at', '<=', now())->get();
        $exports->each(fn (AccountDataRequest $export) => DeleteAccountExport::dispatch($export));

        $deletions = AccountDataRequest::query()->where('type', AccountDataRequest::DELETION)->where('status', AccountDataRequest::SCHEDULED)->where('scheduled_for', '<=', now())->get();
        $deletions->each(fn (AccountDataRequest $deletion) => PurgeAccountData::dispatch($deletion));

        $this->info("{$policies->count()} retention polic(ies), {$exports->count()} expired export(s), {$deletions->count()} account deletion(s) queued.");

        return self::SUCCESS;
    }
}
