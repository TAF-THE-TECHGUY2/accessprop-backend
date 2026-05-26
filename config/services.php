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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

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

    'persona' => [
        'api_key' => env('PERSONA_API_KEY'),
        'api_version' => env('PERSONA_API_VERSION', '2023-01-05'),
        'base_url' => env('PERSONA_BASE_URL', 'https://api.withpersona.com/api/v1'),
        'template_id' => env('PERSONA_TEMPLATE_ID'),
        'environment_id' => env('PERSONA_ENVIRONMENT_ID'),
        'webhook_secret' => env('PERSONA_WEBHOOK_SECRET'),
    ],

    'verifyinvestor' => [
        'api_key' => env('VERIFYINVESTOR_API_KEY'),
        'base_url' => env('VERIFYINVESTOR_BASE_URL', 'https://www.verifyinvestor.com/api/v1'),
        'verification_type' => env('VERIFYINVESTOR_VERIFICATION_TYPE', 'third_party_letter'),
        'webhook_secret' => env('VERIFYINVESTOR_WEBHOOK_SECRET'),
    ],

];
