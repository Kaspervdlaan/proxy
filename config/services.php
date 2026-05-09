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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'storyblok' => [
        'api_token' => env('STORYBLOK_API_TOKEN'),
        'api_base' => env('STORYBLOK_API_BASE', 'https://api.storyblok.com/v2/cdn'),
        'root_slug' => env('STORYBLOK_ROOT_SLUG', ''),
        'cv_slug' => env('STORYBLOK_CV_SLUG', 'cv'),
        'version' => env('STORYBLOK_VERSION', 'published'),
        'cache_version_key' => env('STORYBLOK_CACHE_VERSION_KEY', 'storyblok:cv:latest'),
        'content_cache_prefix' => env('STORYBLOK_CONTENT_CACHE_PREFIX', 'storyblok:story'),
        'content_cache_ttl_seconds' => (int) env('STORYBLOK_CONTENT_CACHE_TTL_SECONDS', 300),
        'stale_cache_ttl_seconds' => (int) env('STORYBLOK_STALE_CACHE_TTL_SECONDS', 86400),
        'serve_stale_on_error' => filter_var(env('STORYBLOK_SERVE_STALE_ON_ERROR', true), FILTER_VALIDATE_BOOL),
        'next_revalidate_url' => env('NEXT_REVALIDATE_URL', ''),
    ],

];
