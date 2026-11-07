<?php

namespace App\Enums;

/**
 * Where a POS bill stands: open while items are added; posted to a guest's room folio;
 * settled with a payment at the outlet; or voided.
 */
enum OutletBillStatus: string
{
    case Open = 'open';
    case Posted = 'posted';
    case Settled = 'settled';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Posted => 'Posted to Room',
            self::Settled => 'Settled',
            self::Void => 'Void',
        };
    }
}
