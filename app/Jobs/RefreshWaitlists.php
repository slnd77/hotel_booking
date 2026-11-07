<?php

namespace App\Jobs;

use App\Models\Property;
use App\Services\Waitlists;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-checks a property's waitlist after its inventory changed (a stay cancelled or shortened,
 * a room unblocked), so requests whose rooms freed up turn available.
 */
class RefreshWaitlists implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public int $propertyId) {}

    public function uniqueId(): string
    {
        return (string) $this->propertyId;
    }

    public function handle(Waitlists $waitlists): void
    {
        $property = Property::query()->find($this->propertyId);

        if ($property !== null) {
            $waitlists->refresh($property);
        }
    }
}
