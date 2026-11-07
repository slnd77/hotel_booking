<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Confirmed = 'confirmed';
    case OnHold = 'on_hold';
    case CheckedIn = 'checked_in';
    case CheckedOut = 'checked_out';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    case Pending = 'pending';
    case Enquiry = 'enquiry';
    case ExpiredQuote = 'expired_quote';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmed',
            self::OnHold => 'On Hold',
            self::CheckedIn => 'Checked In',
            self::CheckedOut => 'Checked Out',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No Show',
            self::Pending => 'Pending',
            self::Enquiry => 'Enquiry',
            self::ExpiredQuote => 'Expired Quote',
        };
    }

    /**
     * Statuses that don't take up a room: they are left out of availability and the Smart Booking Chart.
     *
     * @return list<self>
     */
    public static function notOccupyingRooms(): array
    {
        return [self::Cancelled, ...self::enquiryStatuses()];
    }

    /**
     * Enquiries (quotes) live on the Enquiries page instead of the Bookings table.
     *
     * @return list<self>
     */
    public static function enquiryStatuses(): array
    {
        return [self::Enquiry, self::ExpiredQuote];
    }

    public static function tryFromNormalized(?string $value): ?self
    {
        if (blank($value)) {
            return null;
        }

        $trimmed = strtolower(trim($value));

        return match ($trimmed) {
            'confirm', 'confirmed' => self::Confirmed,
            'hold', 'on_hold', 'on-hold', 'on hold' => self::OnHold,
            'checked_in', 'checked-in', 'checked in' => self::CheckedIn,
            'checked_out', 'checked-out', 'checked out' => self::CheckedOut,
            'cancel', 'cancelled', 'canceled' => self::Cancelled,
            'no_show', 'no-show', 'noshow', 'no show' => self::NoShow,
            'pending' => self::Pending,
            'enquiry', 'inquiry' => self::Enquiry,
            'expired_quote', 'expired-quote', 'expired quote' => self::ExpiredQuote,
            default => self::tryFrom($value),
        };
    }
}
