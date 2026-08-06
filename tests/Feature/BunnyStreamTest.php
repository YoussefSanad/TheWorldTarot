<?php

use App\Enums\VideoStatus;
use App\Services\Bunny\BunnyRequestFailed;
use App\Services\Bunny\BunnyStream;
use Carbon\Carbon;
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

it('reproduces a url bunny actually accepted', function () {
    // **The only assertion here with any authority.** Every input below was sent
    // to the real library on 3 August 2026 and answered 200, for the master
    // manifest, a rendition and a segment. If this fails, our signing has
    // drifted from what Bunny accepts and playback is broken, whatever the rest
    // of this file says.
    //
    // The tests this replaced asserted that the URL contained the token our own
    // code had just produced. They agreed with the implementation rather than
    // with Bunny, stayed green for a week, and playback never worked once in
    // that time. A self-consistent assertion about somebody else's format
    // proves nothing. Do not replace this with one.
    //
    // Regenerating this vector means proving the new one against the live zone
    // first. See docs/plans/bunny-playback-fix-plan.md.
    config()->set('services.bunny.token_key', '0cb734b7-053a-49d1-be0c-b8211a76ad9d');
    config()->set('services.bunny.cdn_hostname', 'vz-9f79c10d-ea5.b-cdn.net');

    $url = app(BunnyStream::class)->playbackUrl(
        '295593e0-b166-4f90-aded-eff7d9ba794c',
        Carbon::createFromTimestamp(1893456000),
        '31.151.68.82',
    );

    expect($url)->toBe(
        'https://vz-9f79c10d-ea5.b-cdn.net'
        .'/bcdn_token=uXBW6XfLNv3hkzTfnLb_JE1DgqYoIkb_fNoH3fzpg88'
        .'&expires=1893456000'
        .'&token_path=%2F295593e0-b166-4f90-aded-eff7d9ba794c%2F'
        .'/295593e0-b166-4f90-aded-eff7d9ba794c/playlist.m3u8'
    );
});

it('scopes the token to the whole video, not just the manifest', function () {
    $url = app(BunnyStream::class)->playbackUrl('a-video-id', now()->addHours(2), '203.0.113.9');

    // HLS resolves renditions and segments relative to the manifest, so a token
    // scoped to playlist.m3u8 alone loads the manifest and then 403s on
    // everything it points at. From curl that is indistinguishable from working.
    expect($url)->toContain('token_path='.rawurlencode('/a-video-id/'))
        ->and($url)->toEndWith('/a-video-id/playlist.m3u8');
});

it('signs differently for a different video, deadline or viewer', function () {
    $bunny = app(BunnyStream::class);
    $expires = now()->addHours(2);
    $ip = '203.0.113.9';

    $token = fn (string $url): string => (string) str($url)->after('bcdn_token=')->before('&');

    $baseline = $token($bunny->playbackUrl('one-video', $expires, $ip));

    // A token that survived being moved to another video, having its deadline
    // edited, or being handed to somebody else would not be protecting anything.
    expect($baseline)
        ->not->toBe($token($bunny->playbackUrl('another-video', $expires, $ip)))
        ->and($baseline)
        ->not->toBe($token($bunny->playbackUrl('one-video', $expires->copy()->addHour(), $ip)))
        ->and($baseline)
        ->not->toBe($token($bunny->playbackUrl('one-video', $expires, '198.51.100.4')));
});

it('signs the same way twice for the same viewer', function () {
    $bunny = app(BunnyStream::class);
    $expires = now()->addHours(2);

    // Not a property anyone asked for, but a signature that changed per call
    // would make a failure impossible to reproduce.
    expect($bunny->playbackUrl('a-video-id', $expires, '203.0.113.9'))
        ->toBe($bunny->playbackUrl('a-video-id', $expires, '203.0.113.9'));
});

it('never signs with the api key by mistake', function () {
    $url = app(BunnyStream::class)->playbackUrl('a-video-id', now()->addHours(2), '203.0.113.9');

    // The token key and the API key are different values, and confusing them
    // produces a 403 that reads like an expiry problem.
    expect($url)->not->toContain('an-api-key');
});
