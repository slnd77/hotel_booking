<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\GuestEmails;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one guest e-mail (GuestEmails::CONFIRMATION / CANCELLATION / RECEIPT) from the queue.
 */
class SendGuestEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public bool $deleteWhenMissingModels = true;

    /**
     * @param  int|null  $userId  who made the change (for the activity log)
     */
    public function __construct(public string $email, public Booking $booking, public ?Payment $payment = null, public ?int $userId = null) {}

    public function handle(GuestEmails $guestEmails): void
    {
        $guestEmails->send($this->email, $this->booking, $this->payment, $this->userId);
    }
}
