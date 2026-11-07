<?php

namespace App\Jobs;

use App\Models\ReportDelivery;
use App\Services\ReportDeliveries;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Builds and e-mails one report delivery from the queue; a failure is kept on the delivery
 * (shown in the Reports page's e-mail log).
 */
class SendReportEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

    /**
     * Large range reports can take a while to build.
     */
    public int $timeout = 300;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public ReportDelivery $delivery) {}

    public function handle(ReportDeliveries $deliveries): void
    {
        if ($this->delivery->status === ReportDelivery::STATUS_SENT) {
            return;
        }

        $deliveries->send($this->delivery);
    }

    public function failed(?Throwable $exception): void
    {
        $this->delivery->update([
            'status' => ReportDelivery::STATUS_FAILED,
            'error' => $exception ? mb_substr($exception->getMessage(), 0, 1000) : 'The report could not be sent.',
        ]);
    }
}
