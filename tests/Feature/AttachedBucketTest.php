<?php

/**
 * The platform publishes attached buckets as one JSON blob, and each entry
 * names the disk it replaces. Nothing reads it by itself, so without this the
 * disk stays a local directory and every upload is lost on the next deploy
 * while appearing to work perfectly.
 *
 * The config file is re-required with the variable set, since configuration is
 * built long before any test runs.
 */
function filesystemsWith(?string $blob): array
{
    $previous = $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'] ?? null;

    if ($blob === null) {
        unset($_SERVER['LARAVEL_CLOUD_DISK_CONFIG'], $_ENV['LARAVEL_CLOUD_DISK_CONFIG']);
    } else {
        $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'] = $blob;
        $_ENV['LARAVEL_CLOUD_DISK_CONFIG'] = $blob;
    }

    try {
        return require config_path('filesystems.php');
    } finally {
        unset($_SERVER['LARAVEL_CLOUD_DISK_CONFIG'], $_ENV['LARAVEL_CLOUD_DISK_CONFIG']);

        if ($previous !== null) {
            $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'] = $previous;
            $_ENV['LARAVEL_CLOUD_DISK_CONFIG'] = $previous;
        }
    }
}

function bucketBlob(array $overrides = []): string
{
    return json_encode([array_merge([
        'disk' => 'public',
        'is_default' => true,
        'access_key_id' => 'a-key',
        'access_key_secret' => 'a-secret',
        'bucket' => 'fls-a25eab55',
        'default_region' => 'auto',
        'endpoint' => 'https://367be3a2.r2.cloudflarestorage.com',
        'url' => 'https://fls-a25eab55.laravel.cloud',
        'use_path_style_endpoint' => false,
    ], $overrides)]);
}

it('replaces the disk the attached bucket names', function () {
    $disk = filesystemsWith(bucketBlob())['disks']['public'];

    // The whole point: "public" is a local directory in this file, and the
    // platform says that disk is a bucket. It has to stop being a directory.
    expect($disk['driver'])->toBe('s3')
        ->and($disk['bucket'])->toBe('fls-a25eab55')
        ->and($disk['region'])->toBe('auto')
        ->and($disk['endpoint'])->toBe('https://367be3a2.r2.cloudflarestorage.com')
        ->and($disk['key'])->toBe('a-key')
        ->and($disk['secret'])->toBe('a-secret')
        ->and($disk['use_path_style_endpoint'])->toBeFalse();
});

it('reads pictures from the public address, not the endpoint they are written to', function () {
    $disk = filesystemsWith(bucketBlob())['disks']['public'];

    expect($disk['url'])->toBe('https://fls-a25eab55.laravel.cloud');
});

function twoBuckets(array $secondOverrides = []): string
{
    return json_encode([
        json_decode(bucketBlob(), true)[0],
        json_decode(bucketBlob(array_merge([
            'disk' => 'video_storage_staging',
            'bucket' => 'fls-private',
            'is_default' => false,
        ], $secondOverrides)), true)[0],
    ]);
}

it('applies every bucket attached, not only the first', function () {
    $config = filesystemsWith(twoBuckets());

    // M4 needs two: pictures public and cacheable, video masters private. The
    // blob is a list, and this is what makes a second bucket a dashboard action
    // rather than a code change.
    expect($config['disks']['public']['bucket'])->toBe('fls-a25eab55')
        ->and($config['disks']['video_storage_staging']['bucket'])->toBe('fls-private')
        ->and($config['disks']['video_storage_staging']['driver'])->toBe('s3');
});

it('gives the private bucket a name that does not change between environments', function () {
    $config = filesystemsWith(twoBuckets());

    // The platform names disks after the bucket, so staging says
    // "video_storage_staging" and production will say something else. Whether a
    // bucket is the default one is the only stable handle.
    expect($config['uploads'])->toBe('uploads')
        ->and($config['disks']['uploads']['bucket'])->toBe('fls-private')
        ->and($config['media'])->toBe('public');
});

it('refuses to guess when more than one bucket could be the private one', function () {
    $blob = json_encode([
        json_decode(bucketBlob(), true)[0],
        json_decode(bucketBlob(['disk' => 'video_storage', 'bucket' => 'fls-video', 'is_default' => false]), true)[0],
        json_decode(bucketBlob(['disk' => 'thumbnail_storage', 'bucket' => 'fls-thumbs', 'is_default' => false]), true)[0],
    ]);

    $config = filesystemsWith($blob);

    // Both are attached and neither is the default, so there is nothing to
    // guess. It falls back to the local disk, which media:check refuses in a
    // deployed environment, rather than silently picking one of them.
    expect($config['uploads'])->toBe('local')
        ->and($config['disks'])->not->toHaveKey('uploads');
});

it('lets an explicit setting win over the alias', function () {
    $_SERVER['UPLOADS_DISK'] = 'video_storage_staging';
    $_ENV['UPLOADS_DISK'] = 'video_storage_staging';

    try {
        expect(filesystemsWith(twoBuckets())['uploads'])->toBe('video_storage_staging');
    } finally {
        unset($_SERVER['UPLOADS_DISK'], $_ENV['UPLOADS_DISK']);
    }
});

it('replaces whichever disk is named, not always public', function () {
    $config = filesystemsWith(bucketBlob(['disk' => 's3']));

    expect($config['disks']['s3']['bucket'])->toBe('fls-a25eab55')
        ->and($config['disks']['public']['driver'])->toBe('local');
});

it('leaves every disk alone when no bucket is attached', function () {
    $config = filesystemsWith(null);

    // Which is every environment except Laravel Cloud, this machine included.
    // Empty rather than null, since .env carries AWS_BUCKET= with nothing after
    // it, which is why everything here tests for blank rather than for null.
    expect($config['disks']['public']['driver'])->toBe('local')
        ->and($config['disks']['s3']['bucket'])->toBeEmpty();
});

it('survives a blob it cannot read rather than taking the application down', function (?string $blob) {
    $config = filesystemsWith($blob);

    // This runs while the framework boots, so throwing here would mean an
    // unreadable variable brings down the site rather than the pictures.
    // media:check reports the consequence in words instead.
    expect($config['disks']['public']['driver'])->toBe('local');
})->with([
    'not json at all' => 'this is not json',
    'an empty list' => '[]',
    'json that is not a list' => '{"disk":"public"}',
    'an entry with no disk name' => '[{"bucket":"fls-a25eab55"}]',
]);
