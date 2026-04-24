<?php

use Illuminate\Support\Str;

return [

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    // Uses the 'default' Redis connection key from config/database.php.
    'use' => 'default',

    'prefix' => env('HORIZON_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'),

    'middleware' => ['web'],

    'waits' => [
        'redis:stripe'        => 30,
        'redis:integrations'  => 60,
        'redis:notifications' => 60,
        'redis:default'       => 60,
        'redis:analytics'     => 300,
        'redis:images'        => 300,
        'redis:mail'          => 120,
        'redis:gdpr'          => 600,
    ],

    'trim' => [
        'recent'        => 60,
        'pending'       => 60,
        'completed'     => 60,
        'recent_failed' => 10080,
        'failed'        => 10080,
        'monitored'     => 10080,
    ],

    'silenced' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job'   => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    'defaults' => [
        'supervisor-stripe' => [
            'connection'  => 'redis',
            'queue'       => ['stripe'],
            'balance'     => false,
            'maxProcesses' => 2,
            'maxTime'     => 0,
            'maxJobs'     => 0,
            'memory'      => 128,
            'tries'       => 1,
            'timeout'     => 180,
            'nice'        => 0,
        ],
        'supervisor-integrations' => [
            'connection'   => 'redis',
            'queue'        => ['integrations'],
            'balance'      => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 4,
            'maxTime'      => 0,
            'maxJobs'      => 0,
            'memory'       => 128,
            'tries'        => 1,
            'timeout'      => 90,
            'nice'         => 0,
        ],
        'supervisor-notifications' => [
            'connection'   => 'redis',
            'queue'        => ['notifications', 'mail'],
            'balance'      => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'maxTime'      => 0,
            'maxJobs'      => 0,
            'memory'       => 128,
            'tries'        => 1,
            'timeout'      => 60,
            'nice'         => 0,
        ],
        'supervisor-default' => [
            'connection'   => 'redis',
            'queue'        => ['default'],
            'balance'      => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'maxTime'      => 0,
            'maxJobs'      => 0,
            'memory'       => 128,
            'tries'        => 1,
            'timeout'      => 60,
            'nice'         => 0,
        ],
        // Capped to prevent analytics/image backlogs from starving critical queues.
        // nice=10 also deprioritises these at the OS scheduler level.
        'supervisor-analytics' => [
            'connection'   => 'redis',
            'queue'        => ['analytics', 'images'],
            'balance'      => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxTime'      => 0,
            'maxJobs'      => 0,
            'memory'       => 256,
            'tries'        => 1,
            'timeout'      => 300,
            'nice'         => 10,
        ],
        'supervisor-gdpr' => [
            'connection'   => 'redis',
            'queue'        => ['gdpr'],
            'balance'      => false,
            'maxProcesses' => 1,
            'maxTime'      => 0,
            'maxJobs'      => 0,
            'memory'       => 128,
            'tries'        => 1,
            'timeout'      => 120,
            'nice'         => 0,
        ],
        // Videos use a dedicated Redis connection with a 1-hour retry_after.
        // Run separately via: php artisan queue:work redis_video --queue=videos --timeout=3600
        'supervisor-videos' => [
            'connection'   => 'redis_video',
            'queue'        => ['videos'],
            'balance'      => false,
            'maxProcesses' => 2,
            'maxTime'      => 0,
            'maxJobs'      => 0,
            'memory'       => 512,
            'tries'        => 1,
            'timeout'      => 3600,
            'nice'         => 5,
        ],
    ],

    'environments' => [

        'production' => [
            'supervisor-stripe'        => ['maxProcesses' => 2],
            'supervisor-integrations'  => ['minProcesses' => 1, 'maxProcesses' => 4],
            'supervisor-notifications' => ['minProcesses' => 1, 'maxProcesses' => 3],
            'supervisor-default'       => ['minProcesses' => 1, 'maxProcesses' => 3],
            'supervisor-analytics'     => ['minProcesses' => 1, 'maxProcesses' => 2],
            'supervisor-gdpr'          => ['maxProcesses' => 1],
            'supervisor-videos'        => ['maxProcesses' => 2],
        ],

        'local' => [
            // Single supervisor for local dev — processes all queues in priority order.
            'supervisor-1' => [
                'connection'   => 'redis',
                'queue'        => ['stripe', 'integrations', 'notifications', 'mail', 'default', 'analytics', 'images', 'gdpr'],
                'balance'      => 'simple',
                'maxProcesses' => 3,
                'tries'        => 1,
                'timeout'      => 300,
            ],
            'supervisor-videos' => [
                'connection'   => 'redis_video',
                'queue'        => ['videos'],
                'balance'      => false,
                'maxProcesses' => 1,
                'timeout'      => 3600,
            ],
        ],

    ],

];
