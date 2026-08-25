<?php

use App\Models\Card;
use App\Services\Reveal\FullFilmRevealVideo;
use App\Support\Playback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\FakeFilmSource;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Never a real request, per .claude/testing.md.
    Http::preventStrayRequests();

    config()->set('services.bunny.token_key', 'a-token-key');
    config()->set('services.bunny.cdn_hostname', 'vz-example.b-cdn.net');
    config()->set('services.bunny.playback_ttl', 1800);
    config()->set('services.bunny.reveal_ttl', 1800);
});

it('plays whatever source config/reveal.php names', function () {
    Card::factory()->watchable()->create();

    // The whole point of the seam. A replacement is one config line, and the
    // endpoint does not learn which one it got.
    config()->set('reveal.source', FakeFilmSource::class);

    // Asserted through the endpoint rather than by resolving the interface,
    // because there is deliberately no bare binding to resolve: two adapters
    // satisfy it and each caller is handed its own. See AppServiceProvider.
    $this->getJson('/api/v1/en/cards/draw')
        ->assertOk()
        ->assertJsonPath('video.url', FakeFilmSource::URL);
});

it('does not let what a pass buys reach into the public reveal', function () {
    Card::factory()->watchable()->create();

    // Two config keys, holding the same film today and not the same decision.
    // Changing what a paying viewer gets must leave the public reveal exactly
    // where it was, and this is what stops that being a promise in a comment.
    config()->set('viewing-room.source', FakeFilmSource::class);

    $url = $this->getJson('/api/v1/en/cards/draw')->assertOk()->json('video.url');

    expect($url)->not->toBe(FakeFilmSource::URL)
        ->and($url)->toContain('bcdn_token=');
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

it('agrees with itself about which cards it can play', function () {
    // One condition rendered two ways, asked both ways on real rows. The reveal
    // takes its pool from the query and, once the declaration reads this, the
    // panel and the resource flag take theirs from the predicate. A
    // disagreement between them is a card that is drawable and unplayable, and
    // nothing failed when the query was the only thing tested.
    $states = [
        'with a film ready' => fn (): Card => Card::factory()->watchable()->create(),
        'with a first film still being prepared' => fn (): Card => Card::factory()->videoProcessing()->create(),
        'with a film that failed' => fn (): Card => Card::factory()->videoFailed()->create(),
        'with a film being replaced' => fn (): Card => Card::factory()->videoBeingReplaced()->create(),
        'with no film at all' => fn (): Card => Card::factory()->create(),
    ];

    $source = app(FullFilmRevealVideo::class);

    foreach ($states as $state => $makeCard) {
        $card = $makeCard();

        $query = Card::query()->whereKey($card->id);
        $source->whatItCanPlay()->constrain($query, 'en');

        expect($query->exists())->toBe(
            $source->whatItCanPlay()->isMetBy($card, 'en'),
            "the reveal disagreed with itself about a card {$state}",
        );
    }

    // The same rows, not merely the same answer a row at a time. This is what
    // `eligible` hands the draw, and it is derived from the condition rather
    // than written beside it.
    $everyCard = Card::all();

    expect($source->eligible(Card::query())->pluck('id')->sort()->values()->all())
        ->toBe($everyCard
            ->filter(fn (Card $card): bool => $source->whatItCanPlay()->isMetBy($card, 'en'))
            ->pluck('id')->sort()->values()->all());
});

it('says what a card it cannot play is waiting for, in her words', function () {
    // The third rendering, which a source has never had. It finishes the same
    // sentence every other condition finishes, so the panel can read it
    // alongside the rest of the list rather than beside it.
    $condition = app(FullFilmRevealVideo::class)->whatItCanPlay();

    expect($condition->whatIsMissing(Card::factory()->create(), 'en'))
        ->toBe(['a video'])
        ->and($condition->whatIsMissing(Card::factory()->videoProcessing()->create(), 'en'))
        ->toBe(['its video to finish being prepared'])
        ->and($condition->whatIsMissing(Card::factory()->videoFailed()->create(), 'en'))
        ->toBe(['its video uploading again, because the last one went wrong']);
});
