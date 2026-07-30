<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | One-Time Password (OTP)
    |--------------------------------------------------------------------------
    |
    | OTP is used to verify email + mobile at registration and as the
    | step-up "signature" on legally significant actions. Values are
    | configuration-driven so they can be tuned without code changes.
    |
    */

    'otp' => [
        'length' => (int) env('DPC_OTP_LENGTH', 6),
        'ttl_seconds' => (int) env('DPC_OTP_TTL', 600),
        'max_attempts' => (int) env('DPC_OTP_MAX_ATTEMPTS', 5),

        // When set (test/dev only), the generated code is forced to this
        // value so flows can be asserted deterministically. Never set in prod.
        'fake_code' => env('DPC_OTP_FAKE_CODE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS
    |--------------------------------------------------------------------------
    |
    | Delivery driver for SMS (OTP + credential issuance). "log" writes to
    | the application log for local/dev; a real gateway is pluggable later.
    |
    */

    'sms' => [
        'driver' => env('DPC_SMS_DRIVER', 'log'),
        'sender_id' => env('DPC_SMS_SENDER_ID', 'CGPSC'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Digital Signature
    |--------------------------------------------------------------------------
    |
    | Legally significant actions are signed through a provider abstraction so
    | business logic never depends on the concrete mechanism. "mock" is used in
    | development; production will use CSC DigiSign / CSC eSign. Signed files are
    | stored on the configured filesystem disk (S3 in production).
    |
    */

    'signature' => [
        'provider' => env('DPC_SIGNATURE_PROVIDER', 'mock'),
        'disk' => env('DPC_SIGNATURE_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Administrative-area Directory (LG Directory)
    |--------------------------------------------------------------------------
    |
    | "seed" serves a static Chhattisgarh district list in dev; a live LGD-API
    | provider replaces it later without changing calling code.
    |
    */

    'directory' => [
        'provider' => env('DPC_DIRECTORY_PROVIDER', 'seed'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Document Storage
    |--------------------------------------------------------------------------
    |
    | Disk for uploaded documents (e.g. recruitment-rule Government Orders).
    | "local" in dev; "s3" in production. Only metadata is stored in MySQL.
    |
    */

    'documents' => [
        'disk' => env('DPC_DOCUMENTS_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Seeded CGPSC Admin
    |--------------------------------------------------------------------------
    |
    | Bootstrap credentials for the CGPSC Admin account. Change the password
    | immediately in any non-local environment.
    |
    */

    'admin' => [
        'name' => env('DPC_ADMIN_NAME', 'CGPSC Administrator'),
        'email' => env('DPC_ADMIN_EMAIL', 'admin@cgpsc.gov.in'),
        'mobile' => env('DPC_ADMIN_MOBILE', '9990000000'),
        'password' => env('DPC_ADMIN_PASSWORD', 'ChangeMe@12345'),
    ],

];
