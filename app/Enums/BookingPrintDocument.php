<?php

namespace App\Enums;

/**
 * The printable documents of a booking (the PRINT menu on the booking page and edit form).
 */
enum BookingPrintDocument: string
{
    case Grc = 'grc';
    case ReservationInternal = 'reservation-internal';
    case ReservationGuest = 'reservation-guest';
    case BillSummary = 'bill-summary';
    case RoomInvoice = 'room-invoice';

    public function label(): string
    {
        return match ($this) {
            self::Grc => 'GRC (Blank)',
            self::ReservationInternal => 'Reservation (Int.)',
            self::ReservationGuest => 'Reservation (Guest)',
            self::BillSummary => 'Guest Bill Summary',
            self::RoomInvoice => 'Room Invoice',
        };
    }
}
