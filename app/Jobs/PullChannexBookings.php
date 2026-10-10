<?php

namespace App\Jobs;

use App\Models\ChannexConnection;
use App\Services\Channex\ChannexBookings;
use App\Services\Channex\ChannexException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fetches and applies a property's new OTA booking revisions from Channex (queued by the
 * Channex webhook). Several webhook calls in a burst fetch once.
 */
class PullChannexBookings implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 30;

    public function __construct(public int $connectionId) {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(ChannexBookings $bookings): void
    {
        $connection = ChannexConnection::query()->find($this->connectionId);

        if ($connection === null || ! $connection->is_active) {
            return;
        }

        try {
            $bookings->pull($connection);
        } catch (ChannexException) {
            // Kept on the connection; the scheduled pull tries again within a minute.
        }
    }
}
