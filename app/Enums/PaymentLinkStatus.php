<?php

namespace App\Enums;

enum PaymentLinkStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Paid => 'Paid',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }
}
