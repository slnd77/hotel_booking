<?php

namespace App\Enums;

/**
 * The five kinds of general-ledger account. Assets and expenses grow with debits; liabilities,
 * equity and revenue grow with credits.
 */
enum GlAccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Whether a debit increases the account's balance.
     */
    public function isDebitNormal(): bool
    {
        return in_array($this, [self::Asset, self::Expense], true);
    }
}
