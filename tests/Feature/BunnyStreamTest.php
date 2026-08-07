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
    // **The only assertion in this file with any authority.**
    //
    // Captured 7 August 2026 from the deployed staging application, after IP
    // binding was removed and `ZoneSecurityIncludeHashRemoteIP` was turned off
    // to match. The URL below was minted by the running app and then fetched
    // from the real zone at three levels, all answering 200:
    //
    //   master manifest  ->  200
    //   rendition        ->  200  (360p/video.m3u8)
    //   segment          ->  200  (360p/video0.ts)
    //
    // All three, deliberately. A wrongly scoped token loads the manifest and
    // then 403s on everything it points at, which from a single request is
    // indistinguishable from working, and that is how M4 shipped five days of
    // total failure.
    //
    // **It was also minted over IPv6 and fetched over IPv4**, which is the exact
    // journey that was broken from 3 to 7 August: the API is behind a dual-stack
    // Cloudflare and the zone has no AAAA record, so every visitor with working
    // IPv6 had a token signed for an address the zone never saw.
    //
    // The vector this replaced included the viewer's IP and was captured on
    // 3 August. It was not recomputed from the new code, it was thrown away and
    // a new one taken from the live zone, because a vector recomputed from the
    // implementation it exists to check protects nothing.
    //
    // Regenerating this means proving the new one against the live zone first,
    // across all three levels. See .claude/testing.md.
    config()->set('services.bunny.token_key', '0cb734b7-053a-49d1-be0c-b8211a76ad9d');
    config()->set('services.bunny.cdn_hostname', 'vz-9f79c10d-ea5.b-cdn.net');

    $url = app(BunnyStream::class)->playbackUrl(
        '295593e0-b166-4f90-aded-eff7d9ba794c',
        Carbon::createFromTimestamp(1786121766),
    );

    expect($url)->toBe(
        'https://vz-9f79c10d-ea5.b-cdn.net'
        .'/bcdn_token=V_o2EjDrfC94kb0xbW5e7oSJ0HcWLOU1ssh5b0gql_8'
        .'&expires=1786121766'
        .'&token_path=%2F295593e0-b166-4f90-aded-eff7d9ba794c%2F'
        .'/295593e0-b166-4f90-aded-eff7d9ba794c/playlist.m3u8'
    );
});

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
