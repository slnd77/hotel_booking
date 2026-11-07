<?php

namespace App\Enums;

/**
 * Stay status of one room/bed within a parent booking.
 */
enum SubBookingStatus: string
{
    case Quote = 'quote';
    case Hold = 'hold';
    case Booked = 'booked';
    case CheckedIn = 'checked_in';
    case CheckedOut = 'checked_out';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /**
     * Statuses that keep their unit out of availability. Quotes (enquiries) and cancelled
     * stays don't; checked-out and no-show stays still hold the nights they were sold for.
     *
     * @return list<self>
     */
    public static function occupying(): array
    {
        return [self::Hold, self::Booked, self::CheckedIn, self::CheckedOut, self::NoShow];
    }
}
