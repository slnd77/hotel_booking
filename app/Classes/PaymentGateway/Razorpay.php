<?php

namespace App\Classes\PaymentGateway;

use App\Models\Booking;
use App\Models\Property;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Razorpay implements PaymentGatewayInterface
{
    protected string $keyId;

    protected string $keySecret;

    public function __construct(?string $keyId = null, ?string $keySecret = null)
    {
        $this->keyId = $keyId ?? config('services.razorpay.key_id', 'rzp_test_placeholder');
        $this->keySecret = $keySecret ?? config('services.razorpay.key_secret', '');
    }

    public function getName(): string
    {
        return 'razorpay';
    }

    public function getDisplayName(): string
    {
        return 'Razorpay';
    }

    public function isAvailable(Property $property): bool
    {
        return ! empty($this->keyId);
    }

    public function createOrder(?Booking $booking, float $amount, string $currency, array $metadata = []): array
    {
        $bookingRefId = $metadata['booking_ref_id'] ?? ($booking->booking_ref_id ?? ('BK-'.strtoupper(Str::random(8))));
        $amountInSubunits = (int) round($amount * 100);
        $orderId = 'order_'.Str::random(14);
        $isTestMode = ($this->keyId === 'rzp_test_placeholder');

        if (! $isTestMode && $this->keyId && $this->keySecret) {
            try {
                $response = Http::withBasicAuth($this->keyId, $this->keySecret)
                    ->post('https://api.razorpay.com/v1/orders', [
                        'amount' => $amountInSubunits,
                        'currency' => $currency,
                        'receipt' => $bookingRefId,
                        'notes' => array_merge([
                            'booking_ref_id' => $bookingRefId,
                            'property_id' => $metadata['property_id'] ?? null,
                        ], $metadata['notes'] ?? []),
                    ]);

                if ($response->successful() && $response->json('id')) {
                    $orderId = $response->json('id');
                } elseif (! $response->successful()) {
                    \Log::warning('Razorpay Order creation failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                    $errorMessage = $response->json('error.description') ?? 'Failed to create Razorpay order with provided credentials.';

                    return [
                        'success' => false,
                        'message' => $errorMessage,
                        'gateway' => $this->getName(),
                    ];
                }
            } catch (\Throwable $e) {
                \Log::error('Razorpay exception: '.$e->getMessage());

                return [
                    'success' => false,
                    'message' => 'Unable to communicate with Razorpay: '.$e->getMessage(),
                    'gateway' => $this->getName(),
                ];
            }
        }

        return [
            'success' => true,
            'gateway' => $this->getName(),
            'key_id' => $this->keyId,
            'order_id' => $orderId,
            'amount' => $amountInSubunits,
            'currency' => $currency,
            'booking_ref_id' => $bookingRefId,
            'is_test_mode' => $isTestMode,
        ];
    }

    public function verifyPayment(array $payload): array
    {
        $transactionId = $payload['transaction_id'] ?? ('RP_'.Str::random(10));

        if (! empty($payload['order_id']) && ! empty($payload['transaction_id']) && ! empty($payload['signature']) && ! empty($this->keySecret)) {
            $expectedSignature = hash_hmac('sha256', $payload['order_id'].'|'.$payload['transaction_id'], $this->keySecret);
            $verified = hash_equals($expectedSignature, $payload['signature']);

            return [
                'success' => $verified,
                'transaction_id' => $payload['transaction_id'],
                'order_id' => $payload['order_id'],
                'status' => $verified ? 'paid' : 'failed',
                'message' => $verified ? 'Payment signature verified successfully.' : 'Signature verification failed.',
                ...($verified ? $this->paymentFee((string) $payload['transaction_id']) : []),
            ];
        }

        // With live keys a payment is only proven by its signature; without keys it is only
        // simulated, which production never accepts.
        $isLive = $this->keyId !== 'rzp_test_placeholder' && ! empty($this->keySecret);
        $simulated = ! $isLive && ! app()->isProduction();

        return [
            'success' => $simulated,
            'transaction_id' => $transactionId,
            'order_id' => $payload['order_id'] ?? null,
            'status' => $simulated ? 'paid' : 'failed',
            'message' => app()->isProduction() ? 'Razorpay signature is missing or gateway not configured.' : ($isLive ? 'The payment signature is missing.' : 'Payment recorded successfully.'),
        ];
    }

    /**
     * What Razorpay kept from the payment: its fee (tax included, in paise) split into fee
     * and tax. Empty when it can't be read; the configured rate is used then.
     *
     * @return array{fee?: float, fee_tax?: float}
     */
    private function paymentFee(string $paymentId): array
    {
        if ($this->keyId === 'rzp_test_placeholder' || empty($this->keySecret)) {
            return [];
        }

        try {
            $response = Http::withBasicAuth($this->keyId, $this->keySecret)->timeout(10)->get('https://api.razorpay.com/v1/payments/'.rawurlencode($paymentId));
        } catch (\Throwable $e) {
            \Log::warning('Razorpay fee lookup failed: '.$e->getMessage());

            return [];
        }

        if (! $response->successful() || ! is_numeric($response->json('fee'))) {
            return [];
        }

        $tax = (float) ($response->json('tax') ?? 0);

        return ['fee' => round(((float) $response->json('fee') - $tax) / 100, 2), 'fee_tax' => round($tax / 100, 2)];
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
            'refund_id' => 'rfnd_'.Str::random(10),
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
            'event' => $payload['event'] ?? 'unknown',
            'booking_ref_id' => $payload['payload']['payment']['entity']['notes']['booking_ref_id'] ?? null,
            'transaction_id' => $payload['payload']['payment']['entity']['id'] ?? null,
            'status' => 'processed',
        ];
    }
}
