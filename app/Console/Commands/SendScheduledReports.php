<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Services\ReportDeliveries;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('reports:send-scheduled {--property= : Only this property id}')]
#[Description('Queue the reports e-mailed daily whose send time (property local time) has passed today')]
class SendScheduledReports extends Command
{
    public function handle(ReportDeliveries $deliveries): int
    {
        $queued = 0;
        $properties = Property::query()->when($this->option('property'), fn ($query, $id) => $query->whereKey($id))->get();

        foreach ($properties as $property) {
            $queued += $deliveries->queueDue($property);
        }

        $this->info("{$queued} report e-mail(s) queued.");

        return self::SUCCESS;
    }
}
