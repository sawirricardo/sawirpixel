<?php

declare(strict_types=1);

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

    'pixelforge' => [
        'url' => env('PIXELFORGE_URL', 'https://pixelforge.promptedit.dev/v1'),
        'key' => env('PIXELFORGE_API_KEY'),
        'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),
        'webhook_tolerance' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE', 300),
        'timeout' => (int) env('PIXELFORGE_TIMEOUT', 15),
        'markup' => env('PIXELFORGE_MARKUP', '1.25'),
        // Pixelforge's per-account limits: generations queued or processing at once, and API requests
        // per minute (kept a little under the documented 60 to leave headroom).
        'max_concurrent_generations' => (int) env('PIXELFORGE_MAX_CONCURRENT', 3),
        'requests_per_minute' => (int) env('PIXELFORGE_REQUESTS_PER_MINUTE', 55),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    // Mail goes through SES with its own credentials (an IAM user that can only send as the app's
    // domain), so the AWS_* settings stay free for anything else, like storage.
    'ses' => [
        'key' => env('SES_KEY', env('AWS_ACCESS_KEY_ID')),
        'secret' => env('SES_SECRET', env('AWS_SECRET_ACCESS_KEY')),
        'region' => env('SES_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
