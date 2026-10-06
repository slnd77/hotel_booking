<?php

namespace App\Console\Commands;

use App\Models\NightAudit;
use App\Models\Property;
use App\Services\NightAudits;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookings:night-audit {--property= : Only this property id} {--date= : Close this business date (Y-m-d) now, whatever the rollover type}')]
#[Description('Close each property\'s business date once its rollover time (property local time) has passed: no-shows, overstays and the day-end report')]
class RunNightAudit extends Command
{
    public function handle(NightAudits $nightAudits): int
    {
        $properties = Property::query()->when($this->option('property'), fn ($query, $id) => $query->whereKey($id))->get();

        foreach ($properties as $property) {
            $audits = $this->option('date')
                ? [$nightAudits->run($property, $this->forcedDate($property))]
                : $nightAudits->runDue($property);

            foreach ($audits as $audit) {
                $line = "{$property->name}: {$audit->business_date->toDateString()} {$audit->status}";

                $audit->status === NightAudit::COMPLETED ? $this->info($line) : $this->error("{$line} — {$audit->error}");
            }
        }

        return self::SUCCESS;
    }

    private function forcedDate(Property $property): CarbonImmutable
    {
        $date = CarbonImmutable::parse((string) $this->option('date'))->startOfDay();

        if ($date->toDateString() > $property->today()) {
            $this->fail("{$date->toDateString()} hasn't started yet at {$property->name}.");
        }

        return $date;
    }
}
