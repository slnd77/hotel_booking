<?php

namespace App\Services;

use App\Enums\PaymentMode;
use App\Models\Property;

/**
 * Settings → Booking → Payment Settings: which payment modes a property uses, and whether each
 * is offered when taking a payment and when paying a refund. Stored in the
 * booking_settings_payment_modes meta field; a mode not saved yet is on for both.
 * Gateway, OTA and e-collect modes are recorded by the system, never picked, so they're left out.
 */
class PaymentModeSettings
{
    public const KEY = 'booking_settings_payment_modes';

    /** Set by the system (gateway receipts, OTA collections), not chosen by staff. */
    public const SYSTEM_MODES = [
        PaymentMode::Online,
        PaymentMode::ECollect,
        PaymentMode::OTA,
        PaymentMode::Razorpay,
        PaymentMode::Cashfree,
        PaymentMode::TestGateway,
    ];

    public function __construct(private PropertySettings $settings) {}

    /**
     * Every selectable mode with its switches, in the enum's order.
     *
     * @return list<array{mode: string, enabled: bool, payment: bool, refund: bool}>
     */
    public function all(Property $property): array
    {
        $modes = $this->settings->json($property, self::KEY)['modes'] ?? [];
        $saved = collect(is_array($modes) ? $modes : [])->keyBy('mode');

        return array_values(collect(PaymentMode::cases())
            ->reject(fn (PaymentMode $mode): bool => in_array($mode, self::SYSTEM_MODES, true))
            ->map(fn (PaymentMode $mode): array => [
                'mode' => $mode->value,
                'enabled' => (bool) ($saved[$mode->value]['enabled'] ?? true),
                'payment' => (bool) ($saved[$mode->value]['payment'] ?? true),
                'refund' => (bool) ($saved[$mode->value]['refund'] ?? true),
            ])
            ->all());
    }

    /**
     * Modes offered when taking a payment.
     *
     * @return list<string>
     */
    public function paymentModes(Property $property): array
    {
        return $this->pick($property, 'payment');
    }

    /**
     * Modes offered when paying a refund.
     *
     * @return list<string>
     */
    public function refundModes(Property $property): array
    {
        return $this->pick($property, 'refund');
    }

    /**
     * @return list<string>
     */
    private function pick(Property $property, string $use): array
    {
        return array_values(collect($this->all($property))
            ->filter(fn (array $mode): bool => $mode['enabled'] && $mode[$use])
            ->map(fn (array $mode): string => $mode['mode'])
            ->all());
    }
}
