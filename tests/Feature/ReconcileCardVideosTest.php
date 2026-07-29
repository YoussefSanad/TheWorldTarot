<?php

use App\Enums\VideoStatus;
use App\Models\Card;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
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

it('finishes a card whose callback never arrived', function () {
    Http::fake(['video.bunnycdn.com/*' => Http::response(['status' => 4])]);
    $card = Card::factory()->videoProcessing()->create();
    $incoming = $card->bunny_incoming_video_id;

    // The failure this exists for. Without it, a lost callback leaves a card at
    // "being prepared" until somebody notices, and one stuck card is a
    // twenty-second of the Viewing Room missing.
    $this->artisan('cards:reconcile-videos')->assertSuccessful();

    expect($card->fresh()->bunny_video_id)->toBe($incoming)
        ->and($card->fresh()->isViewableIn('en'))->toBeTrue();
});

it('asks about a card whose upload was never even sent', function () {
    Http::fake(['video.bunnycdn.com/*' => Http::response(['status' => 4])]);
    $card = Card::factory()->videoProcessing()->create(['video_status' => VideoStatus::Pending]);

    $this->artisan('cards:reconcile-videos')->assertSuccessful();

    expect($card->fresh()->video_status)->toBe(VideoStatus::Ready);
});

it('asks nothing when nothing is in flight', function () {
    Http::fake();
    Card::factory()->watchable()->create();
    Card::factory()->create();

    $this->artisan('cards:reconcile-videos')
        ->expectsOutputToContain('Nothing is waiting')
        ->assertSuccessful();

    // A sweep that wakes a third party to ask about videos nobody is waiting on
    // is a sweep that costs money for nothing.
    Http::assertNothingSent();
});

it('leaves a watchable card alone', function () {
    Http::fake();
    $card = Card::factory()->watchable()->create();

    $this->artisan('cards:reconcile-videos')->assertSuccessful();

    expect($card->fresh()->bunny_video_id)->toBe($card->bunny_video_id);
    Http::assertNothingSent();
});

it('does not keep asking about a video bunny already rejected', function () {
    Http::fake();
    Card::factory()->videoFailed()->create();

    // Bunny has given its answer. Retrying is her uploading the file again, not
    // us asking the same question every five minutes forever.
    $this->artisan('cards:reconcile-videos')->assertSuccessful();

    Http::assertNothingSent();
});

it('carries on when one card cannot be asked about', function () {
    $failing = Card::factory()->videoProcessing()->create(['number' => 90]);
    $working = Card::factory()->videoProcessing()->create(['number' => 91]);

    Http::fake([
        'video.bunnycdn.com/library/12345/videos/'.$failing->bunny_incoming_video_id => Http::response('down', 500),
        'video.bunnycdn.com/*' => Http::response(['status' => 4]),
    ]);

    $this->artisan('cards:reconcile-videos')->assertSuccessful();

    // One unreachable video must not stop the other twenty-one being finished.
    expect($failing->fresh()->video_status)->toBe(VideoStatus::Processing)
        ->and($working->fresh()->video_status)->toBe(VideoStatus::Ready);
});

it('reaches a card that was deleted while its video transcoded', function () {
    Http::fake(['video.bunnycdn.com/*' => Http::response(['status' => 4])]);
    $card = Card::factory()->videoProcessing()->create();
    $card->delete();

    $this->artisan('cards:reconcile-videos')->assertSuccessful();

    // So that restoring it later gives back a card that works, rather than one
    // stuck at "being prepared" for a transcode that finished months ago.
    expect(Card::withTrashed()->find($card->id)->video_status)->toBe(VideoStatus::Ready);
});
