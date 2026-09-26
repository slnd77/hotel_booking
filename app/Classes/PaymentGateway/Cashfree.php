<?php

namespace App\Classes\PaymentGateway;

use App\Models\Booking;
use App\Models\Property;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Cashfree implements PaymentGatewayInterface
{
    protected string $appId;

    protected string $secretKey;

    protected string $mode;

    public function __construct(?string $appId = null, ?string $secretKey = null, ?string $mode = null)
    {
        $this->appId = $appId ?? config('services.cashfree.app_id', 'cf_test_placeholder');
        $this->secretKey = $secretKey ?? config('services.cashfree.secret_key', '');
        $this->mode = $mode ?? (config('services.cashfree.mode', 'sandbox') ?: 'sandbox');
    }

    public function getName(): string
    {
        return 'cashfree';
    }

    public function getDisplayName(): string
    {
        return 'Cashfree';
    }

    public function isAvailable(Property $property): bool
    {
        return ! empty($this->appId);
    }

    public function createOrder(?Booking $booking, float $amount, string $currency, array $metadata = []): array
    {
        $bookingRefId = $metadata['booking_ref_id'] ?? ($booking->booking_ref_id ?? ('BK-'.strtoupper(Str::random(8))));
        $orderId = 'order_'.Str::random(14);
        $sessionId = 'session_'.Str::random(24);
        $isTestMode = ($this->appId === 'cf_test_placeholder');

        if (! $isTestMode && $this->appId && $this->secretKey) {
            try {
                $endpoint = $this->mode === 'production'
                    ? 'https://api.cashfree.com/pg/orders'
                    : 'https://sandbox.cashfree.com/pg/orders';

                $guest = $metadata['guest'] ?? [];
                $rawPhone = (string) ($guest['phone'] ?? '9999999999');
                $phone = preg_replace('/[^0-9]/', '', $rawPhone) ?: '9999999999';
                if (strlen($phone) > 10) {
                    $phone = substr($phone, -10);
                }
                if (strlen($phone) < 10) {
                    $phone = str_pad($phone, 10, '9', STR_PAD_LEFT);
                }

                $customerEmail = filter_var($guest['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: 'guest@example.com';
                $customerName = trim($guest['name'] ?? '') ?: 'Guest';

                $response = Http::withHeaders([
                    'x-client-id' => $this->appId,
                    'x-client-secret' => $this->secretKey,
                    'x-api-version' => '2023-08-01',
                    'Content-Type' => 'application/json',
                ])->post($endpoint, [
                    'order_id' => $orderId,
                    'order_amount' => $amount,
                    'order_currency' => $currency ?: 'INR',
                    'customer_details' => [
                        'customer_id' => 'cust_'.Str::random(8),
                        'customer_name' => $customerName,
                        'customer_email' => $customerEmail,
                        'customer_phone' => $phone,
                    ],
                    'order_meta' => [
                        'return_url' => url('/booking-engine/cashfree/return?order_id={order_id}'),
                    ],
                    'order_note' => $bookingRefId,
                ]);

                if ($response->successful() && $response->json('payment_session_id')) {
                    $sessionId = $response->json('payment_session_id');
                    $orderId = $response->json('order_id', $orderId);
                } elseif (! $response->successful()) {
                    \Log::warning('Cashfree create order error: '.$response->body());
                    $errorMessage = $response->json('message') ?? 'Failed to create Cashfree payment order with provided credentials.';

                    return [
                        'success' => false,
                        'message' => $errorMessage,
                        'gateway' => $this->getName(),
                    ];
                }
            } catch (\Throwable $e) {
                \Log::error('Cashfree exception: '.$e->getMessage());

                return [
                    'success' => false,
                    'message' => 'Unable to communicate with Cashfree: '.$e->getMessage(),
                    'gateway' => $this->getName(),
                ];
            }
        }

        return [
            'success' => true,
            'gateway' => $this->getName(),
            'app_id' => $this->appId,
            'order_id' => $orderId,
            'payment_session_id' => $sessionId,
            'amount' => $amount,
            'currency' => $currency,
            'booking_ref_id' => $bookingRefId,
            'mode' => $this->mode,
            'is_test_mode' => $isTestMode,
        ];
    }

    public function verifyPayment(array $payload): array
    {
        $transactionId = $payload['transaction_id'] ?? ('CF_'.Str::random(10));

        // With live keys the order's status is asked from Cashfree: only a paid order counts.
        if ($this->appId !== 'cf_test_placeholder' && $this->appId && $this->secretKey) {
            $orderId = (string) ($payload['order_id'] ?? '');
            $endpoint = $this->mode === 'production' ? 'https://api.cashfree.com/pg/orders/' : 'https://sandbox.cashfree.com/pg/orders/';

            try {
                $response = $orderId === '' ? null : Http::withHeaders([
                    'x-client-id' => $this->appId,
                    'x-client-secret' => $this->secretKey,
                    'x-api-version' => '2023-08-01',
                ])->get($endpoint.rawurlencode($orderId));
            } catch (\Throwable $e) {
                \Log::error('Cashfree verify exception: '.$e->getMessage());
                $response = null;
            }

            $paid = $response?->successful() && $response->json('order_status') === 'PAID';

            return [
                'success' => $paid,
                'transaction_id' => $transactionId,
                'order_id' => $orderId,
                'amount' => $paid ? (float) $response->json('order_amount') : null,
                'status' => $paid ? 'paid' : 'failed',
                'message' => $paid ? 'Cashfree payment verified.' : 'The Cashfree payment could not be verified.',
                ...($paid ? $this->settlementFee($endpoint, $orderId) : []),
            ];
        }

        // Without keys the payment is only simulated: never accepted in production.
        return [
            'success' => ! app()->isProduction(),
            'transaction_id' => $transactionId,
            'order_id' => $payload['order_id'] ?? null,
            'status' => app()->isProduction() ? 'failed' : 'paid',
            'message' => app()->isProduction() ? 'Cashfree is not properly configured.' : 'Cashfree payment recorded successfully (test mode).',
        ];
    }

    /**
     * What Cashfree kept on the order (service charge and its tax), from the order's settlement.
     * Cashfree reports it once the order is settled, so it's usually missing right after payment
     * and the gateway's configured commission rate is used instead.
     *
     * @return array{fee?: float, fee_tax?: float}
     */
    private function settlementFee(string $endpoint, string $orderId): array
    {
        try {
            $response = Http::withHeaders([
                'x-client-id' => $this->appId,
                'x-client-secret' => $this->secretKey,
                'x-api-version' => '2023-08-01',
            ])->timeout(10)->get($endpoint.rawurlencode($orderId).'/settlements');
        } catch (\Throwable $e) {
            \Log::warning('Cashfree settlement lookup failed: '.$e->getMessage());

            return [];
        }

        if (! $response->successful() || ! is_numeric($response->json('service_charge'))) {
            return [];
        }

        return ['fee' => round((float) $response->json('service_charge'), 2), 'fee_tax' => round((float) ($response->json('service_tax') ?? 0), 2)];
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
            'refund_id' => 'cf_rfnd_'.Str::random(10),
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
            'event' => $payload['type'] ?? 'unknown',
            'order_id' => $payload['data']['order']['order_id'] ?? null,
            'transaction_id' => $payload['data']['payment']['cf_payment_id'] ?? null,
            'status' => 'processed',
        ];
    }
}
