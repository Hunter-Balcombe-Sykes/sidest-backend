<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
        |----------------------------------------------------------------------
        | Media disk – S3-compatible (Laravel Cloud R2 / Cloudflare R2 / AWS S3)
        |----------------------------------------------------------------------
        | All user-uploaded images (originals + WebP variants) live here.
        | On Laravel Cloud this maps to a zero-egress R2 bucket.
        | Set MEDIA_DISK_URL to the public CDN/custom-domain URL for the bucket.
        */
        'media' => [
            'driver' => 's3',
            'key' => env('MEDIA_DISK_KEY', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('MEDIA_DISK_SECRET', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('MEDIA_DISK_REGION', env('AWS_DEFAULT_REGION', 'auto')),
            'bucket' => env('MEDIA_DISK_BUCKET', 'comet-media'),
            'url' => env('MEDIA_DISK_URL'),               // e.g. https://media.cometpages.com
            'endpoint' => env('MEDIA_DISK_ENDPOINT'),      // e.g. https://<account>.r2.cloudflarestorage.com
            'use_path_style_endpoint' => env('MEDIA_DISK_PATH_STYLE', false),
            'throw' => true,
            'report' => true,
            'visibility' => 'public',
            'options' => [
                'CacheControl' => 'public, max-age=31536000, immutable',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
