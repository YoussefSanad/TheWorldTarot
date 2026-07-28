<?php

use Illuminate\Support\Facades\Storage;

it('passes when the media disk can be written to and read back', function () {
    Storage::fake(config('filesystems.media'));

    $this->artisan('media:check')
        ->assertSuccessful();
});

it('leaves nothing behind', function () {
    $disk = Storage::fake(config('filesystems.media'));

    $this->artisan('media:check');

    // A health check that litters the bucket is a health check nobody runs
    // twice.
    expect($disk->allFiles())->toBeEmpty();
});

it('fails when a deployed environment is writing to its own filesystem', function () {
    // The silent failure this command exists for: uploads are accepted, shown,
    // and lost on the next deploy.
    app()->detectEnvironment(fn (): string => 'production');
    config()->set('filesystems.media', 'public');

    $this->artisan('media:check')
        ->expectsOutputToContain('wiped on every deploy')
        ->assertFailed();
});

it('says the bucket is missing rather than throwing from inside flysystem', function () {
    config()->set('filesystems.media', 's3');
    config()->set('filesystems.disks.s3.bucket', null);

    // Building an s3 disk with no bucket is a type error out of Flysystem,
    // which is a poor way to be told an environment variable did not arrive.
    $this->artisan('media:check')
        ->expectsOutputToContain('no bucket configured')
        ->expectsOutputToContain('AWS_BUCKET')
        ->assertFailed();
});

it('accepts a local disk on a developer machine', function () {
    Storage::fake('public');
    app()->detectEnvironment(fn (): string => 'local');
    config()->set('filesystems.media', 'public');

    $this->artisan('media:check')->assertSuccessful();
});
