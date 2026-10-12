<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Services\GeneralLedger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ledger:recognise-revenue {--property= : Only this property id} {--date= : Nights earned through this date (default: yesterday in each property\'s time)}')]
#[Description('Move the room revenue of the nights that have passed from Unearned Room Revenue to Room Revenue (safe to run again)')]
class RecogniseRevenueCommand extends Command
{
    public function handle(GeneralLedger $generalLedger): int
    {
        Property::query()
            ->when($this->option('property'), fn ($query, $id) => $query->whereKey((int) $id))
            ->each(function (Property $property) use ($generalLedger): void {
                $date = $this->option('date')
                    ? CarbonImmutable::parse((string) $this->option('date'))
                    : CarbonImmutable::parse($property->today())->subDay();

                $count = $generalLedger->recogniseRevenue($property, $date);
                $this->line("{$property->name}: {$count} booking(s) brought up to date through {$date->toDateString()}.");
            });

        return self::SUCCESS;
    }
}
