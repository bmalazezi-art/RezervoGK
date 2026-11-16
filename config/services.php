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

    'cloudflare_web_analytics' => [
        // Public beacon token from the Cloudflare snippet; it is visible in page HTML and is not a secret.
        'token' => env('CLOUDFLARE_WEB_ANALYTICS_TOKEN', 'b986a4a614f049b68b3e885b48fea484'),
        // Off in local/testing unless explicitly enabled; on by default only when APP_ENV=production.
        // Booleans are parsed strictly: true/1/on/yes enable; anything else (false/0/off/no/empty/typos) disables.
        'enabled' => filter_var(env('CLOUDFLARE_WEB_ANALYTICS_ENABLED', env('APP_ENV') === 'production'), FILTER_VALIDATE_BOOL),
        // Profile URLs contain a name-based slug or profile id, so they are excluded unless opted in.
        'track_public_profiles' => filter_var(env('CLOUDFLARE_WEB_ANALYTICS_TRACK_PROFILES', false), FILTER_VALIDATE_BOOL),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
