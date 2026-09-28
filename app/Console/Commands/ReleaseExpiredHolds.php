<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookings:release-expired-holds')]
#[Description('Cancel on-hold bookings marked "Auto release the room(s)" whose hold release time has passed, and expire overdue enquiries')]
class ReleaseExpiredHolds extends Command
{
    public function handle(): int
    {
        $released = 0;

        Booking::query()
            ->where('status', BookingStatus::OnHold)
            ->where('hold_auto_release', true)
            ->where('hold_release_at', '<=', now())
            ->each(function (Booking $booking) use (&$released): void {
                $booking->update(['status' => BookingStatus::Cancelled]);
                $released++;
            });

        $expiredEnquiries = Booking::expireOverdueEnquiries();

        $this->info("Released {$released} expired hold(s); {$expiredEnquiries} enquiry(ies) moved to Expired Quote.");

        return self::SUCCESS;
    }
}
