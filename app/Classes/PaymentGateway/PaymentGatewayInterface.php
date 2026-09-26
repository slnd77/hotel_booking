<?php

namespace App\Classes\PaymentGateway;

use App\Models\Booking;
use App\Models\Property;

/**
 * Interface PaymentGatewayInterface
 *
 * Defines the standard contract for payment gateway integrations
 * (e.g. Razorpay, Cashfree, Test Gateway) in the hotel booking engine.
 */
interface PaymentGatewayInterface
{
    /**
     * Unique identifier for the payment gateway (e.g. 'razorpay', 'cashfree', 'test').
     */
    public function getName(): string;

    /**
     * Human-readable display label for the gateway in UI checkout screens.
     */
    public function getDisplayName(): string;

    /**
     * Check if the gateway is configured with credentials and enabled for the given property.
     */
    public function isAvailable(Property $property): bool;

    /**
     * Initialize an order / checkout session on the gateway.
     * Returns the payload required by the frontend client (e.g. order_id, payment_session_id, amount).
     *
     * @param  array<string, mixed>  $metadata  Optional extra parameters (e.g. customer info, booking ref, room details)
     * @return array<string, mixed>
     */
    public function createOrder(?Booking $booking, float $amount, string $currency, array $metadata = []): array;

    /**
     * Verify payment signature / transaction token returned by the frontend checkout widget.
     *
     * @param  array<string, mixed>  $payload  Gateway response parameters (e.g. transaction_id, order_id, signature)
     * @return array<string, mixed> ['success' => bool, 'transaction_id' => ?string, 'status' => string, 'message' => ?string]
     */
    public function verifyPayment(array $payload): array;

    /**
     * Capture an authorized payment (supports two-step pre-authorization / security holds).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function capturePayment(string $transactionId, float $amount, string $currency, array $options = []): array;

    /**
     * Process a full or partial refund for a booking.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed> ['refund_id' => string, 'amount' => float, 'status' => string]
     */
    public function refund(string $transactionId, float $amount, ?string $reason = null, array $metadata = []): array;

    /**
     * Query the gateway API to retrieve live transaction details and status.
     *
     * @return array<string, mixed> ['status' => string, 'amount' => float, 'currency' => string, 'raw' => mixed]
     */
    public function getPaymentDetails(string $transactionId): array;

    /**
     * Parse and verify incoming asynchronous webhook notifications from the gateway.
     *
     * @param  array<string, mixed>  $payload  Webhook request body
     * @param  array<string, string>  $headers  Webhook headers for signature verification
     * @return array<string, mixed> ['event' => string, 'booking_ref_id' => ?string, 'transaction_id' => ?string, 'status' => string]
     */
    public function handleWebhook(array $payload, array $headers): array;
}
