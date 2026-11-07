<?php

namespace App\Classes\PaymentGateway;

use App\Models\Booking;
use App\Models\Property;
use Illuminate\Support\Str;

class TestGateway implements PaymentGatewayInterface
{
    public function getName(): string
    {
        return 'test';
    }

    public function getDisplayName(): string
    {
        return 'Test Gateway';
    }

    public function isAvailable(Property $property): bool
    {
        return (bool) config('services.test_gateway.enabled', false);
    }

    public function createOrder(?Booking $booking, float $amount, string $currency, array $metadata = []): array
    {
        $bookingRefId = $metadata['booking_ref_id'] ?? ($booking?->booking_ref_id ?? ('BK-'.strtoupper(Str::random(8))));

        return [
            'success' => true,
            'gateway' => $this->getName(),
            'order_id' => 'test_order_'.Str::random(12),
            'amount' => $amount,
            'currency' => $currency,
            'booking_ref_id' => $bookingRefId,
            'is_test_mode' => true,
        ];
    }

    public function verifyPayment(array $payload): array
    {
        $transactionId = $payload['transaction_id'] ?? ('TXN_TEST_'.strtoupper(Str::random(8)));

        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'order_id' => $payload['order_id'] ?? null,
            'status' => 'paid',
            'message' => 'Test gateway payment simulated successfully.',
        ];
    }

    public function capturePayment(string $transactionId, float $amount, string $currency, array $options = []): array
    {
        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'captured',
        ];
    }

    public function refund(string $transactionId, float $amount, ?string $reason = null, array $metadata = []): array
    {
        return [
            'refund_id' => 'test_rfnd_'.Str::random(8),
            'transaction_id' => $transactionId,
            'amount' => $amount,
            'status' => 'processed',
            'reason' => $reason,
        ];
    }

    public function getPaymentDetails(string $transactionId): array
    {
        return [
            'status' => 'paid',
            'transaction_id' => $transactionId,
            'gateway' => $this->getName(),
        ];
    }

    public function handleWebhook(array $payload, array $headers): array
    {
        return [
            'event' => 'payment.success',
            'status' => 'processed',
        ];
    }
}
