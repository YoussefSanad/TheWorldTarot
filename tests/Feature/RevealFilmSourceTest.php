<?php

use App\Contracts\FilmSource;
use App\Models\Card;
use App\Services\Reveal\FullFilmRevealVideo;
use App\Support\Playback;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Never a real request, per .claude/testing.md.
    Http::preventStrayRequests();

    config()->set('services.bunny.token_key', 'a-token-key');
    config()->set('services.bunny.cdn_hostname', 'vz-example.b-cdn.net');
    config()->set('services.bunny.playback_ttl', 1800);
    config()->set('services.bunny.reveal_ttl', 1800);
});

it('resolves whatever source the config names', function () {
    expect(app(FilmSource::class))->toBeInstanceOf(FullFilmRevealVideo::class);

    // The whole point of the seam. A replacement is one config line, and nothing
    // that asks for a source has to know which one it got.
    config()->set('reveal.source', FakeFilmSource::class);

    expect(app(FilmSource::class))->toBeInstanceOf(FakeFilmSource::class);
});

it('draws only cards with a film somebody could watch', function () {
    $watchable = Card::factory()->watchable()->create();
    Card::factory()->videoProcessing()->create();
    Card::factory()->create();

    $drawn = app(FullFilmRevealVideo::class)->eligible(Card::query())->pluck('id');

    // A first upload still transcoding and a card with no video at all are each
    // a reveal with nothing to play.
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

    $playback = app(FullFilmRevealVideo::class)->playbackFor($card);

    expect($playback)->toBeInstanceOf(Playback::class)
        ->and($playback->url)->toContain($card->bunny_video_id)
        ->and($playback->url)->toContain('bcdn_token=')
        ->and($playback->url)->toContain('expires='.$playback->expiresAt->getTimestamp())
        ->and($playback->expiresAt->timestamp)->toEqual(now()->addSeconds(1800)->timestamp);
});

it('reads its own expiry key and not the viewing rooms', function () {
    // The two hold the same number today and are not the same decision.
    // Shortening the public reveal must not touch what a paying viewer gets.
    config()->set('services.bunny.reveal_ttl', 900);

    $playback = app(FullFilmRevealVideo::class)
        ->playbackFor(Card::factory()->watchable()->create());

    expect($playback->expiresAt->timestamp)->toEqual(now()->addSeconds(900)->timestamp)
        ->and(config('services.bunny.playback_ttl'))->toBe(1800);
});

it('has nothing to offer for a card with no watchable film', function () {
    // Not an error. A card this source cannot play, which the caller decides
    // what to do about.
    expect(app(FullFilmRevealVideo::class)->playbackFor(
        Card::factory()->videoProcessing()->create(),
    ))->toBeNull();
});

it('hands two viewers of the same card the same url', function () {
    $card = Card::factory()->watchable()->create();
    $source = app(FullFilmRevealVideo::class);

    // The reverse of what this asserted until 7 August 2026, and the point of
    // the change. A URL signed for one address refused everybody reaching the
    // video zone from a different one, which is every visitor with IPv6, since
    // the zone is IPv4 only and this API is not.
    expect($source->playbackFor($card)?->url)
        ->toBe($source->playbackFor($card)?->url);
});

class FakeFilmSource implements FilmSource
{
    public function eligible(Builder $query): Builder
    {
        return $query;
    }

    public function playbackFor(Card $card): ?Playback
    {
        return null;
    }
}
