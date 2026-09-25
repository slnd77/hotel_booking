<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID', 'rzp_test_placeholder'),
        'key_secret' => env('RAZORPAY_KEY_SECRET', ''),
    ],

    'cashfree' => [
        'app_id' => env('CASHFREE_APP_ID', 'cf_test_placeholder'),
        'secret_key' => env('CASHFREE_SECRET_KEY', ''),
        'mode' => env('CASHFREE_MODE', 'sandbox'),
    ],

    // SMS and WhatsApp messages (two-factor codes): "log" or "twilio".
    'text_messages' => [
        'driver' => env('TEXT_MESSAGES_DRIVER', 'log'),
    ],

    // Channel manager. Staging: https://staging.channex.io; live: https://app.channex.io.
    'channex' => [
        'api_key' => env('CHANNEX_API_KEY'),
        'base_url' => env('CHANNEX_BASE_URL', 'https://staging.channex.io/api/v1'),
        'app_url' => env('CHANNEX_APP_URL', 'https://staging.channex.io'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'sms_from' => env('TWILIO_SMS_FROM'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
    ],

    'test_gateway' => [
        'enabled' => (bool) env('ENABLE_TEST_PAYMENT_GATEWAY', false),
    ],

    // "Continue with Google" for staff sign-in and sign-up (OAuth client of type "Web application").
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // Defaults to /auth/google/callback on APP_URL; add it to the client's authorized redirect URIs.
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // ID document scanning (Add/Update Guest → "Scan ID"): the image is read by Claude and the
    // guest's details filled in. Empty key: the button is hidden. The image is never stored.
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
    ],

    // Guest ID scanning on this server, before the AI reader: an Aadhaar's Secure QR code
    // (zbarimg, package zbar-tools) and a passport's machine-readable zone (tesseract, package
    // tesseract-ocr). Either is skipped when its program isn't installed.
    'id_scan' => [
        'zbarimg' => env('ID_SCAN_ZBARIMG_PATH', 'zbarimg'),
        'tesseract' => env('ID_SCAN_TESSERACT_PATH', 'tesseract'),
        'timeout' => (int) env('ID_SCAN_TIMEOUT', 20),
    ],

    // Public booking engine requests a minute, per visitor IP and property.
    'booking_engine' => [
        'quote_per_minute' => (int) env('BOOKING_ENGINE_QUOTE_PER_MINUTE', 60),
        'checkout_per_minute' => (int) env('BOOKING_ENGINE_CHECKOUT_PER_MINUTE', 15),
    ],

    // QZ Tray (POS printing): the site certificate and the private key that signs print requests.
    // Files kept out of the repository (storage/app/private is ignored); see `php artisan pos:qz-certificate`.
    'qz_tray' => [
        'certificate' => env('QZ_TRAY_CERTIFICATE') ?: storage_path('app/private/qz-tray/digital-certificate.txt'),
        'private_key' => env('QZ_TRAY_PRIVATE_KEY') ?: storage_path('app/private/qz-tray/private-key.pem'),
        'private_key_passphrase' => env('QZ_TRAY_PRIVATE_KEY_PASSPHRASE'),
    ],
];
