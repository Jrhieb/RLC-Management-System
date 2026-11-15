<?php

return [

    'brevo' => [
        'sms_enabled' => env('BREVO_SMS_ENABLED', false),
        'sms_key' => env('BREVO_SMS_API_KEY', env('BREVO_API_KEY')),
        'sms_sender' => env('BREVO_SMS_SENDER'),
        'sms_country_codes' => array_filter(array_map('trim', explode(',', (string) env('BREVO_SMS_COUNTRY_CODES', '63')))),
        'sms_daily_limit' => env('BREVO_SMS_DAILY_LIMIT', 0),
    ],

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

];
