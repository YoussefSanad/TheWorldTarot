<?php

use App\Enums\VideoStatus;
use App\Services\Bunny\BunnyRequestFailed;
use App\Services\Bunny\BunnyStream;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Never a real request, per .claude/testing.md. Stray requests fail the test
    // rather than quietly reaching Bunny with a test title.
    Http::preventStrayRequests();

    config()->set('services.bunny', [
        'key' => 'an-api-key',
        'library_id' => '12345',
        'token_key' => 'a-token-key',
        'cdn_hostname' => 'vz-example.b-cdn.net',
        'api_url' => 'https://video.bunnycdn.com',
        'upload_timeout' => 300,
    ]);
});

it('creates a video and returns the id bunny gave it', function () {
    Http::fake([
        'video.bunnycdn.com/library/12345/videos' => Http::response(['guid' => 'a-video-id']),
    ]);

    expect(app(BunnyStream::class)->createVideo('The Fool'))->toBe('a-video-id');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://video.bunnycdn.com/library/12345/videos'
        && $request->header('AccessKey')[0] === 'an-api-key'
        && $request['title'] === 'The Fool');
});

it('refuses to continue when bunny returns no id', function () {
    Http::fake([
        '*' => Http::response(['not' => 'an id']),
    ]);

    // A created video with no id cannot be uploaded to, and pretending otherwise
    // means an upload that goes nowhere.
    expect(fn () => app(BunnyStream::class)->createVideo('The Fool'))
        ->toThrow(BunnyRequestFailed::class);
});

it('uploads the bytes to the video that was created', function () {
    Http::fake(['*' => Http::response()]);

    $file = fopen('php://temp', 'r+');
    fwrite($file, 'pretend this is a film');
    rewind($file);

    app(BunnyStream::class)->uploadVideo('a-video-id', $file);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://video.bunnycdn.com/library/12345/videos/a-video-id'
        && $request->body() === 'pretend this is a film');
});

it('reads the status bunny reports', function (int $reported, VideoStatus $expected) {
    Http::fake(['*' => Http::response(['status' => $reported])]);

    expect(app(BunnyStream::class)->fetchStatus('a-video-id'))->toBe($expected);
})->with([
    'created' => [0, VideoStatus::Processing],
    'uploaded' => [1, VideoStatus::Processing],
    'processing' => [2, VideoStatus::Processing],
    'transcoding' => [3, VideoStatus::Processing],
    'finished' => [4, VideoStatus::Ready],
    'error' => [5, VideoStatus::Failed],
    'upload failed' => [6, VideoStatus::Failed],
]);

it('treats a status it does not recognise as still working', function () {
    Http::fake(['*' => Http::response(['status' => 99])]);

    // Guessing "ready" would put an unfinished video in front of a paying
    // customer. Guessing "failed" would throw away a good one.
    expect(app(BunnyStream::class)->fetchStatus('a-video-id'))->toBe(VideoStatus::Processing);
});

it('throws when bunny refuses a request', function (int $status) {
    Http::fake(['*' => Http::response('nope', $status)]);

    expect(fn () => app(BunnyStream::class)->fetchStatus('a-video-id'))
        ->toThrow(BunnyRequestFailed::class);
})->with([[401], [404], [500]]);

it('says which setting is missing rather than letting bunny say no', function () {
    config()->set('services.bunny.key', null);

    // A missing credential produces a 401 that reads like a revoked key and
    // sends somebody looking in the wrong dashboard.
    expect(fn () => app(BunnyStream::class)->createVideo('The Fool'))
        ->toThrow(BunnyRequestFailed::class, 'BUNNY_API_KEY is missing');
});

it('signs a playback url over the video and its expiry', function () {
    $expires = now()->addHours(2);

    $url = app(BunnyStream::class)->playbackUrl('a-video-id', $expires);

    expect($url)->toStartWith('https://vz-example.b-cdn.net/a-video-id/playlist.m3u8')
        ->and($url)->toContain('expires='.$expires->getTimestamp())
        ->and($url)->toContain('token=');
});

it('signs differently for a different video or a different deadline', function () {
    $bunny = app(BunnyStream::class);
    $expires = now()->addHours(2);

    $token = fn (string $url): string => (string) str($url)->after('token=')->before('&');

    // A token that survived being moved to another video, or having its deadline
    // edited, would not be protecting anything.
    expect($token($bunny->playbackUrl('one-video', $expires)))
        ->not->toBe($token($bunny->playbackUrl('another-video', $expires)))
        ->and($token($bunny->playbackUrl('one-video', $expires)))
        ->not->toBe($token($bunny->playbackUrl('one-video', $expires->copy()->addHour())));
});

it('signs the same way twice for the same request', function () {
    $bunny = app(BunnyStream::class);
    $expires = now()->addHours(2);

    // Not a property anyone asked for, but a signature that changed per call
    // would break caching and make a failure impossible to reproduce.
    expect($bunny->playbackUrl('a-video-id', $expires))
        ->toBe($bunny->playbackUrl('a-video-id', $expires));
});

it('never signs with the api key by mistake', function () {
    $url = app(BunnyStream::class)->playbackUrl('a-video-id', now()->addHours(2));

    // The token key and the API key are different values, and confusing them
    // produces a 403 that reads like an expiry problem.
    expect($url)->toContain(hash('sha256', 'a-token-key'.'a-video-id'.now()->addHours(2)->getTimestamp()))
        ->and($url)->not->toContain('an-api-key');
});
