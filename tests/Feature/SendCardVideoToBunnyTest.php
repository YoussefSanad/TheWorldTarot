<?php

use App\Enums\VideoStatus;
use App\Jobs\SendCardVideoToBunny;
use App\Models\Card;
use App\Services\Bunny\BunnyRequestFailed;
use App\Services\Bunny\BunnyStream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();

    // The queue runs inline in tests, so uploading a master would send it to
    // Bunny during setup. Every test here runs the job by hand, at the moment
    // it means to, and the dispatch itself is asserted separately.
    Queue::fake();

    config()->set('services.bunny', [
        'key' => 'an-api-key',
        'library_id' => '12345',
        'token_key' => 'a-token-key',
        'cdn_hostname' => 'vz-example.b-cdn.net',
        'api_url' => 'https://video.bunnycdn.com',
        'upload_timeout' => 300,
    ]);
});

/**
 * A card with a master sitting in the private bucket, waiting to be sent.
 */
function cardAwaitingUpload(array $attributes = []): Card
{
    Storage::fake(config('filesystems.uploads'));

    $card = Card::factory()->create($attributes);
    Storage::disk(config('filesystems.uploads'))->put('masters/the-fool.mp4', 'pretend this is a film');
    $card->update(['video_master_path' => 'masters/the-fool.mp4']);

    return $card->fresh();
}

function bunnyAccepts(string $videoId = 'a-new-video'): void
{
    Http::fake([
        'video.bunnycdn.com/library/12345/videos' => Http::response(['guid' => $videoId]),
        'video.bunnycdn.com/library/12345/videos/*' => Http::response(),
    ]);
}

it('is dispatched when a master is uploaded', function () {
    $card = cardAwaitingUpload();

    Queue::assertPushed(SendCardVideoToBunny::class, fn (SendCardVideoToBunny $job): bool => $job->card->is($card));
});

it('is not dispatched by an ordinary edit', function () {
    $card = Card::factory()->watchable()->create();

    $card->update(['name' => ['en' => 'The Fool, renamed']]);

    // Every save would otherwise re-upload a 40MB file to a third party.
    Queue::assertNothingPushed();
});

it('creates the video before sending any bytes, and stores its id', function () {
    bunnyAccepts();
    $card = cardAwaitingUpload();

    (new SendCardVideoToBunny($card))->handle(app(BunnyStream::class));

    expect($card->fresh()->bunny_incoming_video_id)->toBe('a-new-video')
        ->and($card->fresh()->video_status)->toBe(VideoStatus::Processing)
        // Still nothing to watch. Bunny decides that, and the webhook asks.
        ->and($card->fresh()->bunny_video_id)->toBeNull()
        ->and($card->fresh()->isViewableIn('en'))->toBeFalse();

    // Method and address only. The job closes the stream after sending, as it
    // should, so the recorded body is no longer readable here. That the right
    // bytes go is asserted in BunnyStreamTest, where nothing closes it.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://video.bunnycdn.com/library/12345/videos/a-new-video');
});

it('names the video after the card, for whoever opens bunny', function () {
    bunnyAccepts();
    $card = cardAwaitingUpload(['number' => 0, 'name' => ['en' => 'The Fool']]);

    (new SendCardVideoToBunny($card))->handle(app(BunnyStream::class));

    Http::assertSent(fn (Request $request): bool => $request->method() !== 'POST'
        || $request['title'] === '0. The Fool');
});

it('uploads to the same video when the queue runs it twice', function () {
    bunnyAccepts();
    $card = cardAwaitingUpload();
    $job = new SendCardVideoToBunny($card);

    $job->handle(app(BunnyStream::class));
    $job->handle(app(BunnyStream::class));

    // The point of creating the video first. At-least-once delivery would
    // otherwise leave three copies of The Fool in her library.
    Http::assertSentCount(3);
    expect($card->fresh()->bunny_incoming_video_id)->toBe('a-new-video');

    $creates = 0;
    Http::recorded(function (Request $request) use (&$creates): void {
        if ($request->method() === 'POST') {
            $creates++;
        }
    });

    expect($creates)->toBe(1);
});

it('leaves the old video playing while the replacement goes up', function () {
    bunnyAccepts('the-replacement');
    $card = cardAwaitingUpload(['poster_path' => 'posters/the-fool.jpg']);
    $card->update(['bunny_video_id' => 'the-original', 'video_status' => VideoStatus::Ready]);

    Storage::disk(config('filesystems.uploads'))->put('masters/again.mp4', 'a better film');
    $card->update(['video_master_path' => 'masters/again.mp4']);

    (new SendCardVideoToBunny($card))->handle(app(BunnyStream::class));

    $card->refresh();

    expect($card->bunny_video_id)->toBe('the-original')
        ->and($card->bunny_incoming_video_id)->toBe('the-replacement')
        ->and($card->isViewableIn('en'))->toBeTrue();
});

it('says so when the master is not in the bucket', function () {
    Storage::fake(config('filesystems.uploads'));
    Http::fake();

    $card = Card::factory()->create();
    $card->updateQuietly(['video_master_path' => 'masters/never-uploaded.mp4', 'video_status' => VideoStatus::Pending]);

    (new SendCardVideoToBunny($card))->handle(app(BunnyStream::class));

    // Retrying cannot conjure a file, so it stops rather than failing three
    // times over.
    expect($card->fresh()->video_status)->toBe(VideoStatus::Failed);
    Http::assertNothingSent();
});

it('does nothing at all for a card with no master', function () {
    Storage::fake(config('filesystems.uploads'));
    Http::fake();

    $card = Card::factory()->create();

    (new SendCardVideoToBunny($card))->handle(app(BunnyStream::class));

    Http::assertNothingSent();
    expect($card->fresh()->video_status)->toBeNull();
});

it('throws when bunny refuses, so the queue retries', function () {
    Http::fake(['*' => Http::response('nope', 500)]);
    $card = cardAwaitingUpload();

    expect(fn () => (new SendCardVideoToBunny($card))->handle(app(BunnyStream::class)))
        ->toThrow(BunnyRequestFailed::class);
});

it('marks the card failed once every attempt has been used', function () {
    $card = cardAwaitingUpload();

    (new SendCardVideoToBunny($card))->failed(new BunnyRequestFailed('Bunny said no'));

    $card->refresh();

    // Failed has to look different from still being prepared, or she waits for
    // something that is never coming.
    expect($card->video_status)->toBe(VideoStatus::Failed)
        // And the master survives, because uploading it again is how she retries
        // and it is the only copy that is not on her laptop.
        ->and($card->video_master_path)->toBe('masters/the-fool.mp4');

    Storage::disk(config('filesystems.uploads'))->assertExists('masters/the-fool.mp4');
});

it('survives the card being deleted before it runs', function () {
    bunnyAccepts();
    $card = cardAwaitingUpload();
    $card->forceDelete();

    // At-least-once delivery means a job can outlive the thing it was about.
    (new SendCardVideoToBunny($card))->handle(app(BunnyStream::class));

    Http::assertNothingSent();
});
