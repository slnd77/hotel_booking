<?php

namespace App\Enums;

enum PaymentMode: string
{
    case Online = 'Online';
    case Cash = 'Cash';
    case CreditCard = 'Credit Card';
    case DebitCard = 'Debit Card';
    case ECollect = 'E-Collect';
    case NetBanking = 'Net Banking';
    case NEFT = 'NEFT';
    case RTGS = 'RTGS';
    case IMPS = 'IMPS';
    case UPI = 'UPI';
    case ChequeDD = 'Cheque/DD';
    case PaymentWallet = 'Payment Wallet';
    case USSD = 'USSD';
    case Others = 'Others';
    case DebitNote = 'Debit Note';
    case CreditNote = 'Credit Note';
    case BillsPayment = 'Bills Payment';
    case GiftCard = 'Gift Card';
    case Commission = 'Commission';
    case TDS = 'TDS';
    case AirPay = 'Air Pay';
    case BankTransfer = 'Bank Transfer';
    case AUBPaymate = 'AUB Paymate';
    case QRCode = 'QR Code';
    case BankDeposit = 'Bank Deposit';
    case MoMo = 'MoMo';
    case BadDebts = 'Bad Debts';
    case GiftCertificate = 'Gift Certificate';
    case AmExCard = 'AmEx Card';
    case RazorPay = 'Razor Pay';
    case LoyaltyRewards = 'Loyalty Rewards';
    case HOCash = 'HO Cash';
    case TransferPOS = 'Transfer/POS';
    case OTA = 'OTA';
    case Razorpay = 'Razorpay';
    case Cashfree = 'Cashfree';
    case TestGateway = 'Test Gateway';

    public function label(): string
    {
        return $this->value;
    }

    /**
     * Normalize payment mode, mapping payment gateway names to 'Online'.
     */
    public static function normalize(?string $value): ?self
    {
        if (blank($value)) {
            return null;
        }

        $trimmed = trim($value);
        $lower = strtolower($trimmed);

        if (in_array($lower, ['online', 'razorpay', 'cashfree', 'test', 'test gateway', 'payment gateway', 'razor pay', 'quick pay'], true)) {
            return self::Online;
        }

        foreach (self::cases() as $case) {
            if (strcasecmp($case->value, $trimmed) === 0 || strcasecmp($case->name, $trimmed) === 0) {
                return $case;
            }
        }

        return self::tryFrom($trimmed) ?? self::Others;
    }
}
