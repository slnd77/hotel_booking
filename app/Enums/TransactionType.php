<?php

namespace App\Enums;

/**
 * Debits are charges the payer owes; credits (payments, allowances) reduce what they owe.
 */
enum TransactionType: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
