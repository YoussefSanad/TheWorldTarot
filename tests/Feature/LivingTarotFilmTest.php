<?php

use App\Contracts\FilmSource;
use App\Models\Card;
use App\Models\Customer;
use App\Services\Reveal\FullFilmRevealVideo;
use App\Services\ViewingRoom\ViewingRoomFilm;
use App\Support\Playback;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\FakeFilmSource;
use Tests\Fixtures\NarrowedLivingTarotFilm;

uses(RefreshDatabase::class);

/**
 * One implementation, two named adapters, per ADR-0001.
 *
 * This was two test files proving the same behaviour twice, which is what the
 * duplicate implementation cost in tests rather than in code. The shared
 * behaviour is written once here and asked of both names, so a subclass that
 * quietly overrides more than its expiry key fails rather than passes.
 *
 * What is genuinely not shared, and what the two names exist to protect, is
 * asserted separately at the bottom: each caller's credential carries its own
 * expiry, and each config key replaces its own caller and nothing else.
 */
beforeEach(function () {
    // Never a real request, per .claude/testing.md.
    Http::preventStrayRequests();

    config()->set('services.bunny.token_key', 'a-token-key');
    config()->set('services.bunny.cdn_hostname', 'vz-example.b-cdn.net');
    config()->set('services.bunny.playback_ttl', 1800);
    config()->set('services.bunny.reveal_ttl', 1800);
});

/**
 * Both names, so the shared behaviour is proved through each of them.
 *
 * A subclass declaring nothing but its expiry key is the whole claim of
 * ADR-0001, and asking both is what holds it.
 */
dataset('film adapters', [
    'the reveal' => FullFilmRevealVideo::class,
    'the Viewing Room' => ViewingRoomFilm::class,
]);

/**
 * Every shape a card's film comes in, named, so a failure says which row broke.
 *
 * Shared by the two tests that ask one rule both ways across all of them. The
 * card whose film is being replaced is the one that matters: it is playable by
 * id and `Processing` by status, so a source asking the wrong question gets it
 * wrong and only that row shows it.
 *
 * @return array<string, callable(): Card>
 */
function everyFilmState(): array
{
    return [
        'with a film ready' => fn (): Card => Card::factory()->watchable()->create(),
        'with a first film still being prepared' => fn (): Card => Card::factory()->videoProcessing()->create(),
        'with a film that failed' => fn (): Card => Card::factory()->videoFailed()->create(),
        'with a film being replaced' => fn (): Card => Card::factory()->videoBeingReplaced()->create(),
        'with no film at all' => fn (): Card => Card::factory()->create(),
    ];
}

it('offers only cards with a film somebody could actually watch', function (string $adapter) {
    $watchable = Card::factory()->watchable()->create();
    Card::factory()->videoProcessing()->create();
    Card::factory()->create();

    // A first upload still transcoding and a card with no video at all are each
    // a card with nothing to serve, pass or no pass.
    $offered = app($adapter)->eligible(Card::query())->pluck('id');

    expect($offered)->toHaveCount(1)
        ->and($offered->first())->toBe($watchable->id);
})->with('film adapters');

it('keeps offering a card whose film is being replaced', function (string $adapter) {
    // The two columns exist for exactly this: the live film keeps playing while
    // its replacement transcodes, so the card must not fall out of the pool.
    // Paying for the pass must not mean losing a card the moment Jennifer
    // uploads a better take of it either.
    $card = Card::factory()->videoBeingReplaced()->create();

    expect(app($adapter)->eligible(Card::query())->pluck('id'))->toContain($card->id);
})->with('film adapters');

it('narrows the language gate rather than replacing it', function (string $adapter) {
    $onTheWebsite = Card::factory()->watchable()->create();
    Card::factory()->watchable()->unpublished()->create();
    Card::factory()->watchable()->withoutCopy()->create();
    Card::factory()->watchable()->withoutImage()->create();

    // Applied on top of the gate, never instead of it. A film does not put a
    // card on the website, and a source must not be able to reach past that to
    // serve something unpublished, unwritten or unpictured. A pass buys the
    // Viewing Room, not a way past the language gate.
    $offered = app($adapter)->eligible(Card::availableIn('en'))->pluck('id');

    expect($offered)->toHaveCount(1)
        ->and($offered->first())->toBe($onTheWebsite->id);
})->with('film adapters');

it('agrees with itself about which cards it can play', function (string $adapter) {
    // One condition rendered two ways, asked both ways on real rows. The
    // declaration takes `in_viewing_room` and the panel's wording from the
    // predicate while the pool comes from the query, so a disagreement here is
    // a card that says it is watchable and 404s when watched.
    $states = everyFilmState();

    $source = app($adapter);

    foreach ($states as $state => $makeCard) {
        $card = $makeCard();

        $query = Card::query()->whereKey($card->id);
        $source->whatItCanPlay()->constrain($query, 'en');

        expect($query->exists())->toBe(
            $source->whatItCanPlay()->isMetBy($card, 'en'),
            "{$adapter} disagreed with itself about a card {$state}",
        );
    }

    // The same rows, not merely the same answer a row at a time. This is what
    // the pool is built from, and it is derived from the condition rather than
    // written beside it.
    $everyCard = Card::all();

    expect($source->eligible(Card::query())->pluck('id')->sort()->values()->all())
        ->toBe($everyCard
            ->filter(fn (Card $card): bool => $source->whatItCanPlay()->isMetBy($card, 'en'))
            ->pluck('id')->sort()->values()->all());
})->with('film adapters');

it('says what a card it cannot play is waiting for, in her words', function (string $adapter) {
    // The third rendering. It finishes the same sentence every other condition
    // finishes, so the panel reads it in the same list rather than beside it.
    $condition = app($adapter)->whatItCanPlay();

    expect($condition->whatIsMissing(Card::factory()->create(), 'en'))
        ->toBe(['a video'])
        ->and($condition->whatIsMissing(Card::factory()->videoProcessing()->create(), 'en'))
        ->toBe(['its video to finish being prepared'])
        ->and($condition->whatIsMissing(Card::factory()->videoFailed()->create(), 'en'))
        ->toBe(['its video uploading again, because the last one went wrong']);
})->with('film adapters');

it('signs the card live film and says when it expires', function (string $adapter) {
    $card = Card::factory()->watchable()->create();

    $playback = app($adapter)->playbackFor($card);

    expect($playback)->toBeInstanceOf(Playback::class)
        ->and($playback->url)->toContain($card->bunny_video_id)
        ->and($playback->url)->toContain('bcdn_token=')
        ->and($playback->url)->toContain('expires='.$playback->expiresAt->getTimestamp())
        ->and($playback->expiresAt->timestamp)->toEqual(now()->addSeconds(1800)->timestamp);
})->with('film adapters');

it('has nothing to offer for a card with no watchable film', function (string $adapter) {
    // Not an error. A card this source has nothing for, which the caller turns
    // into a 404 in the Viewing Room and an empty pool at the reveal.
    expect(app($adapter)->playbackFor(
        Card::factory()->videoProcessing()->create(),
    ))->toBeNull();
})->with('film adapters');

it('refuses to play what it has just said it cannot play', function (string $adapter) {
    // The guard and the declaration are one rule, asked twice. `playbackFor`
    // used to ask `Card::isPlayable()` directly, which is the same answer
    // `filmReadyToPlay()` gives and not the same question: the two agreed by
    // coincidence, which is the arrangement #28 and #32 exist to end. A guard
    // that stops following the condition serves a film for a card the Viewing
    // Room declaration has already stopped calling watchable.
    $states = everyFilmState();

    $source = app($adapter);

    foreach ($states as $state => $makeCard) {
        $card = $makeCard();

        expect($source->playbackFor($card) !== null)->toBe(
            $source->whatItCanPlay()->isMetBy($card, 'en'),
            "{$adapter} played a card {$state} its own condition refuses, or refused one it allows",
        );
    }
})->with('film adapters');

it('follows the condition rather than the column when a subclass narrows', function () {
    // The real subclasses declare nothing but an expiry key, so they cannot ask
    // this question: their condition and `Card::isPlayable()` give the same
    // answer for every row. This one narrows the way a purpose-made clip would.
    //
    // A card whose film is being replaced is playable by id and `Processing` by
    // status, so the two disagree about a row that exists.
    $card = Card::factory()->videoBeingReplaced()->create();
    $source = app(NarrowedLivingTarotFilm::class);

    expect($source->whatItCanPlay()->isMetBy($card, 'en'))->toBeFalse()
        ->and($card->isPlayable())->toBeTrue()
        ->and($source->playbackFor($card))->toBeNull();
});

it('hands two viewers of the same card the same url', function (string $adapter) {
    $card = Card::factory()->watchable()->create();
    $source = app($adapter);

    // The reverse of what this asserted until 7 August 2026, and the point of
    // the change. A URL signed for one address refused everybody reaching the
    // video zone from a different one, which is every visitor with IPv6, since
    // the zone is IPv4 only and this API is not.
    expect($source->playbackFor($card)?->url)->toBe($source->playbackFor($card)?->url);
})->with('film adapters');

/**
 * Below is what the two names buy, and the only thing that is not shared.
 */
it('gives each caller a credential with its own life', function () {
    $card = Card::factory()->watchable()->create();

    // The two hold the same number today and are not the same decision.
    // Lengthening what a paying viewer gets must never lengthen a public
    // credential, and shortening the public reveal must not reach into what
    // somebody paid for. One key each is what keeps that from being a code
    // change, and collapsing the implementations must not collapse this.
    config()->set('services.bunny.reveal_ttl', 900);
    config()->set('services.bunny.playback_ttl', 3600);

    $reveal = $this->getJson('/api/v1/en/cards/draw')->assertOk()->json('video.expires_at');

    $viewingRoom = $this->actingAs(Customer::factory()->withViewingRoomAccess()->create(), 'sanctum')
        ->getJson("/api/v1/en/cards/{$card->id}/video")
        ->assertOk()
        ->json('expires_at');

    expect(CarbonImmutable::parse($reveal)->timestamp)
        ->toEqual(now()->addSeconds(900)->timestamp)
        ->and(CarbonImmutable::parse($viewingRoom)->timestamp)
        ->toEqual(now()->addSeconds(3600)->timestamp);
});

it('plays whatever source config/reveal.php names, and only at the reveal', function () {
    $card = Card::factory()->watchable()->create();

    // The whole point of the seam, and the promise config/viewing-room.php
    // makes alongside it. Replacing what anybody can get for free is one config
    // line, and it must leave a paying viewer exactly where they were.
    config()->set('reveal.source', FakeFilmSource::class);

    $this->getJson('/api/v1/en/cards/draw')
        ->assertOk()
        ->assertJsonPath('video.url', FakeFilmSource::URL);

    $paid = $this->actingAs(Customer::factory()->withViewingRoomAccess()->create(), 'sanctum')
        ->getJson("/api/v1/en/cards/{$card->id}/video")
        ->assertOk()
        ->json('url');

    expect($paid)->not->toBe(FakeFilmSource::URL)
        ->and($paid)->toContain('bcdn_token=');
});

it('plays whatever source config/viewing-room.php names, and only in the Viewing Room', function () {
    $card = Card::factory()->watchable()->create();

    // The seam the paywall did not have until the Viewing Room crossed it.
    config()->set('viewing-room.source', FakeFilmSource::class);

    $this->actingAs(Customer::factory()->withViewingRoomAccess()->create(), 'sanctum')
        ->getJson("/api/v1/en/cards/{$card->id}/video")
        ->assertOk()
        ->assertJsonPath('url', FakeFilmSource::URL);

    $public = $this->getJson('/api/v1/en/cards/draw')->assertOk()->json('video.url');

    expect($public)->not->toBe(FakeFilmSource::URL)
        ->and($public)->toContain('bcdn_token=');
});

it('refuses a config value that does not name a film source', function () {
    // `Card::viewingRoom()` resolves the adapter itself, because the panel, the
    // card resource and the availability check all read the declaration with no
    // container context of their own. That route around the contextual binding
    // is the one place a `FilmSource` is resolved outside `AppServiceProvider`,
    // and the controller's constructor type is what guards the other one.
    //
    // So this is typed too, rather than annotated and hoped for. There is no
    // static analyser here, and a config key naming the wrong class used to
    // surface as an undefined method deep inside the declaration.
    config()->set('viewing-room.source', stdClass::class);

    expect(fn () => Card::viewingRoom())->toThrow(TypeError::class);
});

it('refuses to resolve a film source without saying which one', function () {
    // Two adapters satisfy this interface: one serves the public reveal, the
    // other serves the Viewing Room, which is sold. They differ in what they
    // cost somebody, so the wrong one is not a thing to pick up by accident.
    // Collapsing the implementations must not quietly introduce a default.
    expect(fn () => app(FilmSource::class))->toThrow(BindingResolutionException::class);
});
