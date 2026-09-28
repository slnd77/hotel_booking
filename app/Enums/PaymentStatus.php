<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Received = 'Received';
    case Pending = 'Pending';
    case Cancelled = 'Cancelled';
    case Refunded = 'Refunded';
    case Failed = 'Failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Pending => 'Pending',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
            self::Failed => 'Failed',
        };
    }

    public static function tryFromNormalized(?string $value): ?self
    {
        if (blank($value)) {
            return null;
        }

        $trimmed = trim($value);

        foreach (self::cases() as $case) {
            if (strcasecmp($case->value, $trimmed) === 0 || strcasecmp($case->name, $trimmed) === 0) {
                return $case;
            }
        }

        return self::tryFrom($trimmed);
    }
}
