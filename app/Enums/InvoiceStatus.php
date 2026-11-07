<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case BillGenerated = 'Bill Generated';
    case Cancelled = 'Cancelled';
    case Paid = 'Paid';
    case Unpaid = 'Unpaid';
    case PartiallyPaid = 'Partially Paid';
    case Void = 'Void';

    public function label(): string
    {
        return $this->value;
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
