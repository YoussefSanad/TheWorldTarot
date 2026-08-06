<?php

use App\Contracts\RevealVideoSource;
use App\Models\Card;
use App\Services\Reveal\FullFilmRevealVideo;
use App\Services\Reveal\RevealPlayback;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Never a real request, per .claude/testing.md.
    Http::preventStrayRequests();

    config()->set('services.bunny.token_key', 'a-token-key');
    config()->set('services.bunny.cdn_hostname', 'vz-example.b-cdn.net');
    config()->set('services.bunny.playback_ttl', 7200);
    config()->set('services.bunny.reveal_ttl', 7200);
});

it('resolves whatever source the config names', function () {
    expect(app(RevealVideoSource::class))->toBeInstanceOf(FullFilmRevealVideo::class);

    // The whole point of the seam. A replacement is one config line, and nothing
    // that asks for a source has to know which one it got.
    config()->set('reveal.source', FakeRevealVideoSource::class);

    expect(app(RevealVideoSource::class))->toBeInstanceOf(FakeRevealVideoSource::class);
});

it('draws only cards with a film somebody could watch', function () {
    $watchable = Card::factory()->watchable()->create();
    Card::factory()->videoProcessing()->create();
    Card::factory()->withoutPoster()->create();
    Card::factory()->create();

    $drawn = app(FullFilmRevealVideo::class)->eligible(Card::query())->pluck('id');

    // A first upload still transcoding, a film with no poster frame, and a card
    // with no video at all are each a reveal with nothing to play.
    expect($drawn)->toHaveCount(1)
        ->and($drawn->first())->toBe($watchable->id);
});

it('keeps drawing a card whose film is being replaced', function () {
    // The two columns exist for exactly this: the live film keeps playing while
    // its replacement transcodes, so the card must not fall out of the pool.
    $card = Card::factory()->videoBeingReplaced()->create();

    expect(app(FullFilmRevealVideo::class)->eligible(Card::query())->pluck('id'))
        ->toContain($card->id);
});

it('narrows the language gate rather than replacing it', function () {
    $onTheWebsite = Card::factory()->watchable()->create();
    Card::factory()->watchable()->unpublished()->create();
    Card::factory()->watchable()->withoutCopy()->create();
    Card::factory()->watchable()->withoutImage()->create();

    // Applied on top of the gate, never instead of it. A film does not put a
    // card on the website, and a source must not be able to reach past that to
    // reveal something unpublished, unwritten or unpictured.
    $drawn = app(FullFilmRevealVideo::class)
        ->eligible(Card::availableIn('en'))
        ->pluck('id');

    expect($drawn)->toHaveCount(1)
        ->and($drawn->first())->toBe($onTheWebsite->id);
});

it('signs the card live film and says when it expires', function () {
    $card = Card::factory()->watchable()->create();

    $playback = app(FullFilmRevealVideo::class)->playbackFor($card, '203.0.113.9');

    expect($playback)->toBeInstanceOf(RevealPlayback::class)
        ->and($playback->url)->toContain($card->bunny_video_id)
        ->and($playback->url)->toContain('bcdn_token=')
        ->and($playback->url)->toContain('expires='.$playback->expiresAt->getTimestamp())
        ->and($playback->expiresAt->timestamp)->toEqual(now()->addSeconds(7200)->timestamp);
});

it('reads its own expiry key and not the viewing rooms', function () {
    // The two hold the same number today and are not the same decision.
    // Shortening the public reveal must not touch what a paying viewer gets.
    config()->set('services.bunny.reveal_ttl', 900);

    $playback = app(FullFilmRevealVideo::class)
        ->playbackFor(Card::factory()->watchable()->create(), '203.0.113.9');

    expect($playback->expiresAt->timestamp)->toEqual(now()->addSeconds(900)->timestamp)
        ->and(config('services.bunny.playback_ttl'))->toBe(7200);
});

it('has nothing to offer for a card with no watchable film', function () {
    // Not an error. A card this source cannot play, which the caller decides
    // what to do about.
    expect(app(FullFilmRevealVideo::class)->playbackFor(
        Card::factory()->videoProcessing()->create(),
        '203.0.113.9',
    ))->toBeNull();
});

it('signs for the viewer it was given, not for whoever asks next', function () {
    $card = Card::factory()->watchable()->create();
    $source = app(FullFilmRevealVideo::class);

    // Bunny binds a playback token to an address, so two viewers must never
    // receive the same URL. If they did, one of them would be watching on the
    // other's signature and both would break the moment either moved network.
    expect($source->playbackFor($card, '203.0.113.9')?->url)
        ->not->toBe($source->playbackFor($card, '198.51.100.4')?->url);
});

class FakeRevealVideoSource implements RevealVideoSource
{
    public function eligible(Builder $query): Builder
    {
        return $query;
    }

    public function playbackFor(Card $card, string $viewerIp): ?RevealPlayback
    {
        return null;
    }
}
