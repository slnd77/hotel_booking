<?php

namespace App\Enums;

enum BookingCheckInStatus: string
{
    case CheckIn = 'check-in';
    case CheckOut = 'Check Out';
    case NoStay = 'No Stay';
    case CheckOutAndReleasedRoom = 'Check-Out & Released Room';

    public function label(): string
    {
        return match ($this) {
            self::CheckIn => 'Check-In',
            self::CheckOut => 'Check-Out',
            self::NoStay => 'No Stay',
            self::CheckOutAndReleasedRoom => 'Check-Out & Released Room',
        };
    }

    public static function tryFromNormalized(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (blank($value)) {
            return null;
        }

        $trimmed = trim((string) $value);
        $lower = strtolower(str_replace(['_', ' '], '-', $trimmed));

        return match ($lower) {
            'check-in', 'checkin', 'checked-in', 'checkedin' => self::CheckIn,
            'check-out', 'checkout', 'checked-out', 'checkedout' => self::CheckOut,
            'no-stay', 'nostay' => self::NoStay,
            'check-out-&-released-room', 'check-out-and-released-room' => self::CheckOutAndReleasedRoom,
            default => self::tryFrom($trimmed) ?? self::tryFrom(str_replace('-', ' ', $trimmed)),
        };
    }
}
