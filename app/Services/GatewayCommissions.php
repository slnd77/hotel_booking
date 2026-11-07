<?php

namespace App\Services;

use App\Models\Property;

/**
 * What a payment gateway keeps from an online receipt. The fee the gateway reports for the
 * payment wins (with its tax); otherwise the gateway's rate from Booking Engine settings:
 * a percentage plus a fixed fee per payment, and tax charged on that fee.
 */
class GatewayCommissions
{
    public function __construct(private PropertySettings $settings) {}

    /**
     * @param  array<string, mixed>  $verification  the gateway's verifyPayment() result
     */
    public function for(Property $property, string $gateway, float $amount, array $verification = []): ?float
    {
        if (isset($verification['fee']) && is_numeric($verification['fee'])) {
            return round((float) $verification['fee'] + (float) ($verification['fee_tax'] ?? 0), 2);
        }

        $rate = $this->rate($property, $gateway);

        if ($rate === null) {
            return null;
        }

        $fee = $amount * $rate['percent'] / 100 + $rate['fixed'];

        return round($fee * (1 + $rate['tax_percent'] / 100), 2);
    }

    /**
     * The gateway's configured rate, or null when none is set.
     *
     * @return array{percent: float, fixed: float, tax_percent: float}|null
     */
    public function rate(Property $property, string $gateway): ?array
    {
        $config = collect($this->settings->json($property, 'booking_engine_settings')['allowed_payment_gateways'] ?? [])
            ->first(fn ($item): bool => is_array($item) && ($item['id'] ?? null) === $gateway);
        $percent = (float) ($config['commission_percent'] ?? 0);
        $fixed = (float) ($config['commission_fixed'] ?? 0);

        if ($percent <= 0 && $fixed <= 0) {
            return null;
        }

        return ['percent' => $percent, 'fixed' => $fixed, 'tax_percent' => (float) ($config['commission_tax_percent'] ?? 0)];
    }
}
