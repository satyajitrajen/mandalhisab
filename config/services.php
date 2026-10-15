<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
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

    'fcm' => [
        'enabled' => env('FIREBASE_ENABLED', false),
        'credentials' => env('FIREBASE_CREDENTIALS', 'storage/app/firebase-service-account.json'),
        'project_id' => env('FIREBASE_PROJECT_ID'),
    ],

    'razorpay' => [
        'key_id' => env('RAZORPAY_KEY_ID'),
        'key_secret' => env('RAZORPAY_KEY_SECRET'),
        'registration_amount_paise' => (int) env('RAZORPAY_REGISTRATION_AMOUNT_PAISE', 10100),
        'currency' => env('RAZORPAY_CURRENCY', 'INR'),
        'renewal_window_days' => (int) env('REGISTRATION_RENEWAL_WINDOW_DAYS', 30),
    ],

    'apple_iap' => [
        'enabled' => env('APPLE_IAP_ENABLED', false),
        'environment' => env('APPLE_IAP_ENVIRONMENT', 'sandbox'),
        'bundle_id' => env('APPLE_IAP_BUNDLE_ID'),
        'issuer_id' => env('APPLE_IAP_ISSUER_ID'),
        'key_id' => env('APPLE_IAP_KEY_ID'),
        // .p8 key from App Store Connect; keep it outside the repo.
        'private_key_path' => env('APPLE_IAP_PRIVATE_KEY_PATH'),
        'product_id' => env('APPLE_IAP_PRODUCT_ID', 'mandal.registration.yearly'),
    ],

];
