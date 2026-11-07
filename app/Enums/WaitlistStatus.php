<?php

namespace App\Enums;

/**
 * Where a waitlist request stands: waiting for rooms, rooms now free (staff can convert it),
 * or closed (converted into a booking, expired past its check-in, or cancelled).
 */
enum WaitlistStatus: string
{
    case Waiting = 'waiting';
    case Available = 'available';
    case Converted = 'converted';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Waiting',
            self::Available => 'Rooms available',
            self::Converted => 'Converted',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Requests still looking for rooms.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Waiting, self::Available];
    }
}
