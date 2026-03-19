<?php

declare(strict_types=1);

use MariusCucuruz\DAMImporter\Enums\PackageTypes;
use MariusCucuruz\DAMImporter\Enums\PackageInterval;
use MariusCucuruz\DAMImporter\Enums\PackageSyncMode;

return [
    'name'         => 'Web Sweep',
    'description'  => 'Automatically scan and index your website to sync assets into Medialake. (Previously titled Apify)',
    'logo'         => 'apify.svg',
    'active'       => env('WEBSWEEP_ACTIVE', true),
    'type'         => PackageTypes::SOURCE->value,
    'instructions' => 'docs/readme.md',

    'api_token'   => env('WEBSWEEP_API_TOKEN'),
    'webhook_uri' => env('WEBSWEEP_WEBHOOK_URL') ?? url(path: '/webhooks/websweep', secure: true),

    'base_url'         => 'https://api.apify.com',
    'default_actor_id' => env('WEBSWEEP_ACTOR_ID'),
    'second_actor_id'  => env('WEBSWEEP_ACTOR_2_ID'),
    'third_actor_id'   => env('WEBSWEEP_ACTOR_3_ID'),

    'timeout'             => env('WEBSWEEP_TIMEOUT', (30 * 60)),
    'max_depth'           => env('WEBSWEEP_MAX_DEPTH', 4),
    'max_redirects'       => env('WEBSWEEP_MAX_REDIRECTS', 25),
    'requests_per_crawl'  => env('WEBSWEEP_MAX_REQUESTS', 200),
    'days_before_respawn' => env('WEBSWEEP_DAYS_BEFORE_RESPAWN', 7),

    'settings_required' => env('WEBSWEEP_SETTINGS_REQUIRED', true),

    // HTTP client tuning for dataset fetching and retries
    'http' => [
        'page_size'          => env('WEBSWEEP_PAGE_SIZE', 100),
        'max_retries'        => env('WEBSWEEP_MAX_RETRIES', 5),
        'initial_backoff_ms' => env('WEBSWEEP_INITIAL_BACKOFF_MS', 500),
        'max_backoff_ms'     => env('WEBSWEEP_MAX_BACKOFF_MS', 8000),
        'request_timeout'    => env('WEBSWEEP_REQUEST_TIMEOUT', 60),
        'connect_timeout'    => env('WEBSWEEP_CONNECT_TIMEOUT', 15),
    ],

    'defaults' => [
        'sync_mode'     => PackageSyncMode::AUTOMATIC,
        'sync_interval' => PackageInterval::EVERY_WEEK,
        'sync_options'  => [
            PackageInterval::EVERY_WEEK,
            PackageInterval::EVERY_MONTH,
        ],
    ],

    'settings' => [
        'WEBSWEEP_START_URL' => [
            'name'        => 'WEBSWEEP_START_URL',
            'placeholder' => 'Enter your WebSweep start URL to crawl here without any space.',
            'description' => 'Web Sweep Start URL',
            'type'        => 'text',
            'rules'       => [
                'required',
                'min:6', // e.g. `app.io`
                // 'regex:/^(?:(?:www\.)?(?:[\w\-]+(?:\.[\w\-]+)+)\.[a-zA-Z]{2,6}|(?:\d{1,3}\.){3}\d{1,3}:\d{2,5})(?:\/[^\s]*)?$/',
            ],
        ],

        'WEBSWEEP_ACTOR' => [
            'name'        => 'WEBSWEEP_ACTOR',
            'placeholder' => 'Choose the Crawl Agent type.',
            'description' => 'The WebSweep crawl agent (i.e. Actor)',
            'type'        => 'text',
            'rules'       => [
                'required',
                'min:6',
            ],
        ],

        'WEBSWEEP_TIMEOUT' => [
            'name'        => 'WEBSWEEP_TIMEOUT',
            'placeholder' => 'Enter a timeout for the crawl (default 30 minutes = 1800)',
            'description' => 'Number of seconds before the crawl times out. Default is 1800, max is 3600.',
            'type'        => 'numeric',
            'rules'       => [
                'sometimes',
                'min:1',
                'max:3600',
            ],
        ],
        'WEBSWEEP_MAX_DEPTH' => [
            'name'        => 'WEBSWEEP_MAX_DEPTH',
            'placeholder' => 'How many linked pages from root (default 4).',
            'description' => 'To only crawl the given URL, and not follow any links, enter 1. Default is 4, max is 25.',
            'type'        => 'numeric',
            'rules'       => [
                'sometimes',
                'numeric',
                'min:1',
                'max:25',
            ],
        ],
        'WEBSWEEP_MAX_REDIRECTS' => [
            'name'        => 'WEBSWEEP_MAX_REDIRECTS',
            'placeholder' => 'Enter the max number of links the crawl should follow (default 25).',
            'description' => 'To only crawl the given URL, and not follow any links, enter 1. Default is 25, max is 60.',
            'type'        => 'numeric',
            'rules'       => [
                'sometimes',
                'numeric',
                'min:1',
                'max:60',
            ],
        ],
        'WEBSWEEP_MAX_REQUESTS' => [
            'name'        => 'WEBSWEEP_MAX_REQUESTS',
            'placeholder' => 'Enter the max number of requests the crawl may make (default 200).',
            'description' => 'To only crawl the given URL, and not follow any links, enter 1. Default is 200, max is 500.',
            'type'        => 'numeric',
            'rules'       => [
                'sometimes',
                'numeric',
                'min:1',
                'max:500',
            ],
        ],
    ],
];
