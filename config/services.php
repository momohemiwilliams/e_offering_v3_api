<?php

return [

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | Login OTP delivery. "mail" generates the code here and emails it; "fmdq" uses the
    | FMDQ OTP master API (generator / validator) that the legacy platform called.
    */
    'otp' => [
        'driver' => env('OTP_DRIVER', 'mail'),
        'url' => env('OTP_URL'),
        'app_id' => env('OTP_APP_ID'),
        'username' => env('OTP_USERNAME'),
        'password' => env('OTP_PASSWORD'),
        'type' => env('OTP_TYPE', 'numeric'),
        'length' => (int) env('OTP_LENGTH', 6),
    ],

    /*
    | Retail onboarding BVN check through iGree (FMDQ-hosted). The consent page returns a
    | temporary code which is exchanged server-side for the BVN record; the browser never
    | supplies BVN data directly. "manual" lets local/test environments skip iGree.
    */
    'igree' => [
        'driver' => env('IGREE_DRIVER', 'manual'),
        'url' => env('IGREE_URL'),
        'api' => env('IGREE_API'),
        'client' => env('IGREE_CLIENT'),
        'secret' => env('IGREE_SECRET'),
    ],

    // Annual TSP platform fee gateways (CR s3.2).
    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'url' => env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co'),
    ],

    'flutterwave' => [
        'public_key' => env('FLW_PUBLIC_KEY'),
        'secret_key' => env('FLW_SECRET_KEY'),
        'url' => env('FLW_PAYMENT_URL', 'https://api.flutterwave.com/v3'),
    ],

    // CSD lodgement transmission (CR s5.4). "file" generates the report for download only.
    'csd' => [
        'driver' => env('CSD_DRIVER', 'file'),
        'url' => env('CSD_LODGEMENT_URL'),
        'token' => env('CSD_LODGEMENT_TOKEN'),
        'notify_email' => env('CSD_NOTIFY_EMAIL'),
    ],

];

