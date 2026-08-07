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

it('deletes a video from the library', function () {
    Http::fake(['*' => Http::response(['success' => true])]);

    app(BunnyStream::class)->deleteVideo('a-video-id');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://video.bunnycdn.com/library/12345/videos/a-video-id'
        && $request->header('AccessKey')[0] === 'an-api-key');
});

it('counts a video bunny has already deleted as deleted', function () {
    Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

    // The only caller is a queued job and the queue delivers at least once, so
    // the second run finds the video gone. That is the outcome being asked for.
    // Throwing here would fail every redelivery and park a job in the dead
    // letter queue describing work that succeeded.
    expect(fn () => app(BunnyStream::class)->deleteVideo('a-video-id'))
        ->not->toThrow(BunnyRequestFailed::class);
});

it('throws when bunny refuses to delete a video for any other reason', function (int $status) {
    Http::fake(['*' => Http::response('nope', $status)]);

    // A revoked key must not read as a successful cleanup, or a film stays in
    // the library with nothing left pointing at it and nobody is told.
    expect(fn () => app(BunnyStream::class)->deleteVideo('a-video-id'))
        ->toThrow(BunnyRequestFailed::class);
})->with([[401], [403], [500]]);

it('says which setting is missing rather than letting bunny say no', function () {
    config()->set('services.bunny.key', null);

    // A missing credential produces a 401 that reads like a revoked key and
    // sends somebody looking in the wrong dashboard.
    expect(fn () => app(BunnyStream::class)->createVideo('The Fool'))
        ->toThrow(BunnyRequestFailed::class, 'BUNNY_API_KEY is missing');
});

it('reproduces a url bunny actually accepted', function () {
    // **The only assertion here with any authority, and it is currently blank.**
    //
    // The vector it held was captured from the real library on 3 August 2026 and
    // included the viewer's IP in the hash. IP binding was removed on 7 August
    // 2026, because the video zone is IPv4 only and this API is behind a
    // dual-stack Cloudflare, so a token signed for a visitor's IPv6 address was
    // refused by a zone that only ever saw them arrive over IPv4. That is every
    // visitor with working IPv6, on every film.
    //
    // So the old vector no longer describes what we send, and **it must not be
    // recomputed from the new code**. That is exactly the move that cost five
    // days in M4: a test that agrees with the implementation whatever the
    // implementation does, staying green through total failure. See
    // .claude/testing.md.
    //
    // To restore it, in this order:
    //
    // 1. Turn off `ZoneSecurityIncludeHashRemoteIP` on the pull zone. Until it
    //    is off, the zone still expects an address in the hash and every URL we
    //    now mint is refused
    // 2. Deploy and take a real URL from `GET /{locale}/cards/draw` on staging
    // 3. Fetch its manifest, a rendition and a segment, and confirm all three
    //    answer 200. A manifest alone proves nothing, since a wrongly scoped
    //    token loads it and 403s on everything it points at
    // 4. Pin that exact URL here with the guid and expiry that produced it, and
    //    delete this skip
    //
    // Left skipped rather than deleted, and rather than weakened into something
    // that passes, so the gap is visible in every run.
})->skip('No vector since IP binding was removed on 7 August 2026. Capture one from the live zone, see the steps above.');

it('scopes the token to the whole video, not just the manifest', function () {
    $url = app(BunnyStream::class)->playbackUrl('a-video-id', now()->addMinutes(30));

    // HLS resolves renditions and segments relative to the manifest, so a token
    // scoped to playlist.m3u8 alone loads the manifest and then 403s on
    // everything it points at. From curl that is indistinguishable from working.
    expect($url)->toContain('token_path='.rawurlencode('/a-video-id/'))
        ->and($url)->toEndWith('/a-video-id/playlist.m3u8');
});

it('signs differently for a different video or deadline', function () {
    $bunny = app(BunnyStream::class);
    $expires = now()->addMinutes(30);

    $token = fn (string $url): string => (string) str($url)->after('bcdn_token=')->before('&');

    $baseline = $token($bunny->playbackUrl('one-video', $expires));

    // A token that survived being moved to another video or having its deadline
    // edited would not be protecting anything. The viewer used to be a third
    // case here, and is not, which is the change of 7 August 2026: the expiry
    // is now the only thing limiting a copied link.
    expect($baseline)
        ->not->toBe($token($bunny->playbackUrl('another-video', $expires)))
        ->and($baseline)
        ->not->toBe($token($bunny->playbackUrl('one-video', $expires->copy()->addHour())));
});

it('signs the same way twice for the same video and deadline', function () {
    $bunny = app(BunnyStream::class);
    $expires = now()->addMinutes(30);

    // Not a property anyone asked for, but a signature that changed per call
    // would make a failure impossible to reproduce.
    expect($bunny->playbackUrl('a-video-id', $expires))
        ->toBe($bunny->playbackUrl('a-video-id', $expires));
});

it('never signs with the api key by mistake', function () {
    $url = app(BunnyStream::class)->playbackUrl('a-video-id', now()->addMinutes(30));

    // The token key and the API key are different values, and confusing them
    // produces a 403 that reads like an expiry problem.
    expect($url)->not->toContain('an-api-key');
});
