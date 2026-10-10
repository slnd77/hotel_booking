<?php

namespace App\Enums;

/**
 * The ways a user can prove it is them after their password. A user may turn on several and
 * pick one at each login; the order here is the order they are offered in.
 */
enum TwoFactorMethod: string
{
    case Passkey = 'passkey';
    case Totp = 'totp';
    case WhatsApp = 'whatsapp';
    case Sms = 'sms';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Passkey => 'Passkey',
            self::Totp => 'Authenticator app (TOTP)',
            self::WhatsApp => 'WhatsApp OTP',
            self::Sms => 'SMS OTP',
            self::Email => 'E-mail OTP',
        };
    }

    /**
     * Methods that send the user a one-time code.
     */
    public function sendsCode(): bool
    {
        return in_array($this, [self::WhatsApp, self::Sms, self::Email], true);
    }

    /**
     * Methods that send their code to the user's mobile number.
     */
    public function needsMobile(): bool
    {
        return in_array($this, [self::WhatsApp, self::Sms], true);
    }

    /**
     * @return list<self>
     */
    public static function codeChannels(): array
    {
        return array_values(array_filter(self::cases(), fn (self $method): bool => $method->sendsCode()));
    }
}
