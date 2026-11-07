<?php

namespace App\Console\Commands;

use App\Models\ChannexConnection;
use App\Services\Channex\ChannexAri;
use App\Services\Channex\ChannexBookings;
use App\Services\Channex\ChannexException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('channex:sync {--pull : Fetch new OTA bookings} {--push : Push availability and rates for the whole sync window} {--property= : Only this property id}')]
#[Description('Channel manager: fetch OTA bookings from Channex and/or push a full availability and rate sync to it')]
class SyncChannex extends Command
{
    public function handle(ChannexBookings $bookings, ChannexAri $ari): int
    {
        $pull = $this->option('pull') || ! $this->option('push');
        $push = $this->option('push') || ! $this->option('pull');
        $status = self::SUCCESS;

        $connections = ChannexConnection::query()
            ->where('is_active', true)
            ->when($this->option('property'), fn ($query, $propertyId) => $query->where('property_id', $propertyId))
            ->with('property:id,name,timezone')
            ->get();

        foreach ($connections as $connection) {
            $name = $connection->property?->name ?? "property {$connection->property_id}";

            try {
                if ($pull) {
                    $result = $bookings->pull($connection);
                    $this->line("{$name}: {$result['applied']} booking revision(s) applied, {$result['failed']} failed.");
                }

                if ($push) {
                    $ari->push($connection);
                    $this->line("{$name}: availability and rates pushed for {$connection->sync_days} days.");
                }
            } catch (ChannexException $exception) {
                $this->error("{$name}: {$exception->getMessage()}");
                $status = self::FAILURE;
            }
        }

        return $status;
    }
}
