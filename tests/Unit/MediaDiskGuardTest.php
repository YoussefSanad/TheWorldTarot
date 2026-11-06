<?php

use App\Support\MediaDiskGuard;
use Tests\TestCase;

// Unit tests are not extended with the framework TestCase in Pest.php, and the
// guard reads config and the app environment.
uses(TestCase::class);

// Config only. The guard must never build the disk: an s3 disk with no bucket
// throws a type error from inside Flysystem.
function guardDisk(string $driver, ?string $bucket = null): string
{
    config()->set('filesystems.disks.guarded', [
        'driver' => $driver,
        'bucket' => $bucket,
        'root' => storage_path('app/guarded'),
    ]);

    return 'guarded';
}

it('refuses a local disk outside local and testing', function () {
    app()->detectEnvironment(fn () => 'production');

    $reason = app(MediaDiskGuard::class)->refusalFor(guardDisk('local'));

    expect($reason)->toBeString()->toContain('wiped on every deploy');
});

it('allows a local disk in the local environment', function () {
    app()->detectEnvironment(fn () => 'local');

    expect(app(MediaDiskGuard::class)->refusalFor(guardDisk('local')))->toBeNull();
});

it('allows a local disk in the testing environment', function () {
    app()->detectEnvironment(fn () => 'testing');

    expect(app(MediaDiskGuard::class)->refusalFor(guardDisk('local')))->toBeNull();
});

it('refuses an s3 disk with no bucket configured', function () {
    app()->detectEnvironment(fn () => 'production');

    $reason = app(MediaDiskGuard::class)->refusalFor(guardDisk('s3', null));

    expect($reason)->toBeString()->toContain('no bucket configured');
});

it('refuses an s3 disk whose bucket is an empty string', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(app(MediaDiskGuard::class)->refusalFor(guardDisk('s3', '')))
        ->toBeString()->toContain('no bucket configured');
});

it('allows an s3 disk with a bucket', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(app(MediaDiskGuard::class)->refusalFor(guardDisk('s3', 'a-bucket')))->toBeNull();
});
