<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Services\Waitlists;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('waitlist:refresh')]
#[Description('Re-check every open waitlist request against current availability, and expire those past their check-in date')]
class RefreshWaitlistsCommand extends Command
{
    public function handle(Waitlists $waitlists): int
    {
        $totals = ['available' => 0, 'waiting' => 0, 'expired' => 0];

        Property::query()->whereHas('waitlistRequests', fn ($query) => $query->open())->each(function (Property $property) use ($waitlists, &$totals): void {
            foreach ($waitlists->refresh($property) as $status => $count) {
                $totals[$status] += $count;
            }
        });

        $this->info("Waitlist: {$totals['available']} available, {$totals['waiting']} waiting, {$totals['expired']} expired.");

        return self::SUCCESS;
    }
}
