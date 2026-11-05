<?php

return [

    /*
    | Business details shown in the website footer, on tickets, in emails and
    | in the legal documents. Fill these in from your trade licence before launch.
    */
    'business' => [
        'trade_name' => env('BUSINESS_TRADE_NAME', 'Bemengede'),
        'legal_name' => env('BUSINESS_LEGAL_NAME', '[REGISTERED COMPANY NAME]'),
        'tin' => env('BUSINESS_TIN', '[TIN]'),
        'trade_license_no' => env('BUSINESS_TRADE_LICENSE', '[TRADE LICENCE NO.]'),
        'address' => env('BUSINESS_ADDRESS', '[STREET, SUB-CITY, WOREDA], Addis Ababa, Ethiopia'),
        'email' => env('BUSINESS_EMAIL', 'support@bemengede.et'),
        'privacy_email' => env('BUSINESS_PRIVACY_EMAIL', 'privacy@bemengede.et'),
        'phone' => env('BUSINESS_PHONE', '[+251 PHONE]'),
        'support_hours' => env('BUSINESS_SUPPORT_HOURS', 'Every day, 06:00–22:00 EAT'),
    ],

    'currency' => 'ETB',

    /*
    | Pricing. Every amount is stored in santim (1 ETB = 100 santim).
    | The service fee is charged per seat and is ALWAYS included in the price
    | shown in search results, so the price never grows at checkout.
    */
    'pricing' => [
        'service_fee_per_seat' => (int) env('SERVICE_FEE_PER_SEAT', 1500),
        'child_discount_percent' => 50,
        'student_discount_percent' => 20,
    ],

    'booking' => [
        'hold_minutes' => (int) env('SEAT_HOLD_MINUTES', 10),
        'transfer_hold_minutes' => (int) env('TRANSFER_HOLD_MINUTES', 30),
        'max_seats_per_booking' => 6,
        'checkin_opens_hours' => 24,
    ],

    'privacy' => [
        // Bookers and account holders must be adults. Children travel as
        // passengers on an adult's booking; we never ask for a birth date.
        'minimum_account_age' => 18,
        // A deletion request can be cancelled for this many hours before it runs.
        'deletion_cooling_off_hours' => (int) env('DELETION_COOLING_OFF_HOURS', 72),
        // Financial records are kept (with personal data removed) for this long.
        'financial_retention_years' => (int) env('FINANCIAL_RETENTION_YEARS', 10),
        'otp_ttl_minutes' => 5,
    ],

    /*
    | Legal document versions. Bump a version when the text changes in a way
    | that needs fresh consent; the apps then ask users to accept it again.
    */
    'legal' => [
        'privacy' => ['version' => '2026-11-05', 'effective' => '2026-11-05'],
        'terms' => ['version' => '2026-11-04', 'effective' => '2026-11-04'],
        'refund' => ['version' => '2026-11-04', 'effective' => '2026-11-04'],
        'cookies' => ['version' => '2026-11-05', 'effective' => '2026-11-05'],
    ],

    'payments' => [
        // "mock" simulates every provider locally; use "live" only with real credentials.
        'mode' => env('PAYMENTS_MODE', 'mock'),
        'platform_commission_percent' => (float) env('PLATFORM_COMMISSION_PERCENT', 5),
        // Commission is charged on fares sold online. Counter (cash) sales are commission-free
        // unless this is switched on in the partner agreement.
        'commission_on_counter_sales' => (bool) env('COMMISSION_ON_COUNTER_SALES', false),
        // Gateway fees in percent of the amount charged. Set these from your provider contracts.
        // The platform pays gateway fees out of the service fee; operators never see them deducted.
        'gateway_fee_percent' => [
            'chapa' => (float) env('CHAPA_FEE_PERCENT', 0),
            'telebirr' => (float) env('TELEBIRR_FEE_PERCENT', 0),
            'transfer' => 0.0,
            'cash' => 0.0,
        ],

        'chapa' => [
            'base_url' => env('CHAPA_BASE_URL', 'https://api.chapa.co/v1'),
            'secret_key' => env('CHAPA_SECRET_KEY'),
            'webhook_secret' => env('CHAPA_WEBHOOK_SECRET'),
        ],

        'telebirr' => [
            'base_url' => env('TELEBIRR_BASE_URL'),
            'fabric_app_id' => env('TELEBIRR_FABRIC_APP_ID'),
            'app_secret' => env('TELEBIRR_APP_SECRET'),
            'merchant_app_id' => env('TELEBIRR_MERCHANT_APP_ID'),
            'merchant_code' => env('TELEBIRR_MERCHANT_CODE'),
            'private_key_path' => env('TELEBIRR_PRIVATE_KEY_PATH'),
            'public_key_path' => env('TELEBIRR_PUBLIC_KEY_PATH'),
            'web_checkout_url' => env('TELEBIRR_WEB_CHECKOUT_URL'),
        ],

        'verify_et' => [
            'base_url' => env('VERIFY_ET_BASE_URL', 'https://verify.et'),
            'api_key' => env('VERIFY_ET_API_KEY'),
            'webhook_secret' => env('VERIFY_ET_WEBHOOK_SECRET'),
            'signature_header' => env('VERIFY_ET_SIGNATURE_HEADER', 'X-Verify-Signature'),
            'banks' => ['CBE', 'CBE_BIRR', 'TELEBIRR', 'BOA', 'AWASH', 'DASHEN', 'SIINQEE', 'MPESA', 'KAAFI_EBIRR', 'COOPAY_EBIRR'],
        ],
    ],

    'sms' => [
        // "log" writes SMS to the log in development. Add a real gateway driver for production.
        'driver' => env('SMS_DRIVER', 'log'),
    ],

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
];
