<?php

/*
 * The buckets Laravel Cloud has attached to this environment.
 *
 * The platform publishes them as one JSON blob rather than as AWS_ variables,
 * and each entry names the disk it is meant to replace. Nothing reads it by
 * itself, so an attached bucket is invisible to the application until this runs
 * and the disk it names quietly stays a local directory.
 *
 * Found on 29 July 2026, after a long hunt for AWS_BUCKET in an environment
 * that was never going to have one. Empty everywhere except Laravel Cloud.
 */
$attachedBuckets = (function (): array {
    $raw = env('LARAVEL_CLOUD_DISK_CONFIG');

    if (blank($raw)) {
        return [];
    }

    $decoded = json_decode((string) $raw, true);

    // Never fail here. A malformed blob must not take the application down at
    // boot, and `php artisan media:check` reports the consequence in words.
    if (! is_array($decoded)) {
        return [];
    }

    return collect($decoded)
        ->filter(fn ($bucket): bool => is_array($bucket) && filled($bucket['disk'] ?? null))
        ->mapWithKeys(fn (array $bucket): array => [$bucket['disk'] => [
            'driver' => 's3',
            'key' => $bucket['access_key_id'] ?? null,
            'secret' => $bucket['access_key_secret'] ?? null,
            'region' => $bucket['default_region'] ?? 'auto',
            'bucket' => $bucket['bucket'] ?? null,

            // What the pictures are read from, which is not the endpoint they
            // are written to.
            'url' => $bucket['url'] ?? null,
            'endpoint' => $bucket['endpoint'] ?? null,
            'use_path_style_endpoint' => $bucket['use_path_style_endpoint'] ?? false,
            'throw' => false,
            'report' => false,
        ]])
        ->all();
})();

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
    | Media Disk
    |--------------------------------------------------------------------------
    |
    | Where anything the client uploads lives: card pictures now, video
    | thumbnails from M4. Named separately from the default disk so one setting
    | moves all of it, and so no upload can quietly land on the application's
    | own filesystem.
    |
    | That matters on Laravel Cloud, where the filesystem is ephemeral. There,
    | the attached bucket replaces whichever disk it names, "public" today, so
    | this setting can stay as it is in every environment: locally it is a
    | directory, deployed it is the bucket.
    |
    | Run `php artisan media:check` in any environment to prove which disk is
    | actually in use and that it can be written to.
    |
    */

    'media' => env('MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Uploads Disk
    |--------------------------------------------------------------------------
    |
    | Where files the public must never reach are kept: the master copy of every
    | Living Tarot video. Separate from the media disk because these two want
    | opposite things. Card pictures are public and want caching; video masters
    | are the asset the whole paywall exists to protect.
    |
    | Locally this is "local", which is storage/app/private and is not served by
    | the web server. Deployed, it is the second, private bucket, and the disk
    | name it replaces is whatever the platform gives it.
    |
    */

    'uploads' => env('UPLOADS_DISK', 'local'),

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
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',

            // Left null on Laravel Cloud, deliberately. The platform hands the
            // SDK temporary credentials through the container credential
            // provider, AWS_CONTAINER_CREDENTIALS_FULL_URI, so static keys are
            // absent by design rather than missing. Null here means the SDK
            // resolves them itself.
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),

            // AWS_REGION is what the container sets. Laravel's own variable
            // wins when present, so a dashboard setting still overrides it.
            'region' => env('AWS_DEFAULT_REGION', env('AWS_REGION')),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Last, so an attached bucket replaces the disk it names. On Laravel
        // Cloud that is "public", which is why nothing above needed changing
        // and why the same MEDIA_DISK setting is right in every environment.
        ...$attachedBuckets,

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
