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

function bunnySays(int $status): void
{
    Http::fake(['video.bunnycdn.com/*' => Http::response(['status' => $status])]);
}

it('makes a card watchable when bunny says the video is finished', function () {
    bunnySays(4);
    $card = Card::factory()->videoProcessing()->create();
    $incoming = $card->bunny_incoming_video_id;

    $this->postJson('/api/v1/webhooks/bunny', ['VideoGuid' => $incoming])
        ->assertOk()
        ->assertExactJson(['received' => true]);

    $card->refresh();

    expect($card->bunny_video_id)->toBe($incoming)
        ->and($card->bunny_incoming_video_id)->toBeNull()
        ->and($card->video_status)->toBe(VideoStatus::Ready)
        ->and($card->isViewableIn('en'))->toBeTrue();
});

it('ignores a forged callback, because bunny is the one asked', function () {
    // Bunny says it is still transcoding. The callback claiming otherwise
    // achieves nothing, which is what makes an unauthenticated endpoint safe.
    bunnySays(2);
    $card = Card::factory()->videoProcessing()->create();

    $this->postJson('/api/v1/webhooks/bunny', [
        'VideoGuid' => $card->bunny_incoming_video_id,
        'Status' => 4,
    ])->assertOk();

    expect($card->fresh()->bunny_video_id)->toBeNull()
        ->and($card->fresh()->video_status)->toBe(VideoStatus::Processing)
        ->and($card->fresh()->isViewableIn('en'))->toBeFalse();
});

it('marks a card failed when bunny says the video went wrong', function () {
    bunnySays(5);
    $card = Card::factory()->videoProcessing()->create();

    $this->postJson('/api/v1/webhooks/bunny', ['VideoGuid' => $card->bunny_incoming_video_id])
        ->assertOk();

    expect($card->fresh()->video_status)->toBe(VideoStatus::Failed);
});

it('leaves the old video playing when a replacement went wrong', function () {
    bunnySays(5);
    $card = Card::factory()->videoBeingReplaced()->create();
    $live = $card->bunny_video_id;

    $this->postJson('/api/v1/webhooks/bunny', ['VideoGuid' => $card->bunny_incoming_video_id])
        ->assertOk();

    $card->refresh();

    // A failed replacement costs her a retry, never a card.
    expect($card->bunny_video_id)->toBe($live)
        ->and($card->video_status)->toBe(VideoStatus::Failed)
        ->and($card->isViewableIn('en'))->toBeTrue();
});

it('is harmless when the same callback arrives twice', function () {
    bunnySays(4);
    $card = Card::factory()->videoProcessing()->create();
    $incoming = $card->bunny_incoming_video_id;

    $this->postJson('/api/v1/webhooks/bunny', ['VideoGuid' => $incoming])->assertOk();
    $this->postJson('/api/v1/webhooks/bunny', ['VideoGuid' => $incoming])->assertOk();

    // Assume every webhook will be delivered more than once, per .claude/api.md.
    // The second one names the live video and finds nothing in flight.
    expect($card->fresh()->bunny_video_id)->toBe($incoming)
        ->and($card->fresh()->video_status)->toBe(VideoStatus::Ready);
});

it('accepts a callback for a video it has never heard of', function () {
    Http::fake();

    $this->postJson('/api/v1/webhooks/bunny', ['VideoGuid' => 'not-ours'])
        ->assertOk();

    // Another environment sharing a library, or a card since deleted. A non-2xx
    // would only make Bunny try again, and nothing would improve.
    Http::assertNothingSent();
});

it('does not fall over on a payload it cannot use', function (array $payload) {
    Http::fake();

    $this->postJson('/api/v1/webhooks/bunny', $payload)->assertOk();

    Http::assertNothingSent();
})->with([
    'empty' => [[]],
    'no video' => [['Status' => 4]],
    'a video that is not a string' => [['VideoGuid' => ['nested']]],
    'an empty video' => [['VideoGuid' => '']],
]);

it('needs no authentication, since bunny holds no credentials of ours', function () {
    bunnySays(4);
    $card = Card::factory()->videoProcessing()->create();

    // No token, no session, no CSRF.
    $this->postJson('/api/v1/webhooks/bunny', ['VideoGuid' => $card->bunny_incoming_video_id])
        ->assertOk();
});

it('leaves a card alone when bunny cannot be reached', function () {
    Http::fake(['*' => Http::response('down', 500)]);
    $card = Card::factory()->videoProcessing()->create();

    $this->postJson('/api/v1/webhooks/bunny', ['VideoGuid' => $card->bunny_incoming_video_id])
        ->assertOk();

    // A failure to ask says nothing about the video. Marking it failed here
    // would take a good video off the site because of our own outage.
    expect($card->fresh()->video_status)->toBe(VideoStatus::Processing);
});

it('answers a card that was deleted while its video transcoded', function () {
    bunnySays(4);
    $card = Card::factory()->videoProcessing()->create();
    $card->delete();

    $this->postJson('/api/v1/webhooks/bunny', ['VideoGuid' => $card->bunny_incoming_video_id])
        ->assertOk();

    expect(Card::withTrashed()->find($card->id)->video_status)->toBe(VideoStatus::Ready);
});
