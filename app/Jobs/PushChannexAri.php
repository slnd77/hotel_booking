<?php

namespace App\Jobs;

use App\Models\ChannexConnection;
use App\Services\Channex\ChannexAri;
use App\Services\Channex\ChannexException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Pushes a property's availability and/or rates for a date range to Channex. The same push
 * queued twice within a minute (e.g. a booking and its rooms saved together) runs once.
 */
class PushChannexAri implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 60;

    public function __construct(
        public int $connectionId,
        public ?string $from = null,
        public ?string $to = null,
        public bool $availability = true,
        public bool $rates = true,
    ) {}

    public function uniqueId(): string
    {
        return implode('|', [$this->connectionId, $this->from, $this->to, (int) $this->availability, (int) $this->rates]);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ChannexAri $ari): void
    {
        $connection = ChannexConnection::query()->find($this->connectionId);

        if ($connection === null || ! $connection->is_active) {
            return;
        }

        try {
            $ari->push($connection, $this->from, $this->to, $this->availability, $this->rates);
        } catch (ChannexException) {
            // The error is kept on the connection (shown on the Channel Manager page). Never
            // thrown: a Channex outage must not break the booking save that queued this.
            if ($this->job !== null && $this->attempts() < $this->tries) {
                $this->release(60);
            }
        }
    }
}
