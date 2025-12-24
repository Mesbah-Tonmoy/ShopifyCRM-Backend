<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook Secret
    |--------------------------------------------------------------------------
    |
    | This secret is used to verify webhook signatures from your Shopify apps.
    | Make sure to use a strong, random secret and keep it secure.
    |
    */

    'secret' => env('WEBHOOK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Webhook Timeout
    |--------------------------------------------------------------------------
    |
    | Maximum time (in seconds) to wait for webhook processing.
    |
    */

    'timeout' => env('WEBHOOK_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Webhook Retry Settings
    |--------------------------------------------------------------------------
    |
    | Configure retry behavior for failed webhooks.
    |
    */

    'retry' => [
        'enabled' => env('WEBHOOK_RETRY_ENABLED', true),
        'max_attempts' => env('WEBHOOK_RETRY_MAX_ATTEMPTS', 3),
        'delay' => env('WEBHOOK_RETRY_DELAY', 60), // seconds
    ],

];