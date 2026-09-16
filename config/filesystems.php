<?php

// Laravel Cloud injects object-storage credentials as a single JSON-encoded env
// var rather than discrete AWS_* vars — decode it here so the rest of this file
// can read it as a normal fallback source. Safe to call this early (before the
// app container boots): collect()/json_decode() don't need it.
$laravelCloudDisk = null;
if ($rawCloudDiskConfig = env('LARAVEL_CLOUD_DISK_CONFIG')) {
    $disks = json_decode($rawCloudDiskConfig, true) ?: [];
    $laravelCloudDisk = collect($disks)->firstWhere('is_default', true) ?? ($disks[0] ?? null);
}

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

        // Every uploaded-image accessor/controller in this app addresses this disk by
        // name ("public"), not the framework default — so it's driven directly by
        // FILESYSTEM_DISK rather than a separate disk the app never references. Local
        // dev stays on the "local" driver (disk storage); Laravel Cloud (and any other
        // ephemeral/scaled host) sets FILESYSTEM_DISK=s3 so uploads land in object
        // storage instead of a container-local disk that doesn't persist or replicate.
        'public' => [
            'driver' => env('FILESYSTEM_DISK', 'local'),
            'root' => storage_path('app/public'),
            // Only fall back to the local "/storage" URL when we're actually on the
            // local driver — on s3, no configured url should leave this null so
            // Flysystem builds a proper bucket URL instead of a broken local path.
            'url' => $laravelCloudDisk['url']
                ?? env('AWS_URL')
                ?? (env('FILESYSTEM_DISK', 'local') === 'local'
                    ? rtrim(env('APP_URL', 'http://localhost'), '/').'/storage'
                    : null),
            'visibility' => 'public',
            'throw' => false,
            'report' => false,

            // S3-only keys — ignored when driver is "local". Laravel Cloud's disk
            // config (see above) takes priority; plain AWS_* env vars still work
            // for any other S3-compatible host (or a manual Laravel Cloud setup).
            'key' => $laravelCloudDisk['access_key_id'] ?? env('AWS_ACCESS_KEY_ID'),
            'secret' => $laravelCloudDisk['access_key_secret'] ?? env('AWS_SECRET_ACCESS_KEY'),
            'region' => $laravelCloudDisk['default_region'] ?? env('AWS_DEFAULT_REGION'),
            'bucket' => $laravelCloudDisk['bucket'] ?? env('AWS_BUCKET'),
            'endpoint' => $laravelCloudDisk['endpoint'] ?? env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => $laravelCloudDisk['use_path_style_endpoint']
                ?? env('AWS_USE_PATH_STYLE_ENDPOINT', false),
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
