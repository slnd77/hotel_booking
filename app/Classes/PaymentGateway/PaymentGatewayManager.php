<?php

namespace App\Classes\PaymentGateway;

class PaymentGatewayManager
{
    /**
     * @var array<string, class-string<PaymentGatewayInterface>>
     */
    protected static array $gateways = [
        'razorpay' => Razorpay::class,
        'cashfree' => Cashfree::class,
        'test' => TestGateway::class,
    ];

    public static function resolve(string $name): PaymentGatewayInterface
    {
        if (! isset(self::$gateways[$name])) {
            throw new \InvalidArgumentException("Payment gateway [{$name}] is not supported.");
        }

        $class = self::$gateways[$name];

        return new $class;
    }

    /**
     * @return array<int, string>
     */
    public static function allowedGatewayNames(): array
    {
        $names = ['razorpay', 'cashfree'];
        if (config('services.test_gateway.enabled')) {
            $names[] = 'test';
        }

        return $names;
    }

    public static function isAllowed(string $name): bool
    {
        return in_array($name, self::allowedGatewayNames(), true);
    }
}
