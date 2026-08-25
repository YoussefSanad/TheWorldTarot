<?php

use App\Models\Card;
use App\Models\Customer;
use App\Services\ViewingRoom\ViewingRoomFilm;
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

it('offers only cards with a film somebody could actually watch', function () {
    $watchable = Card::factory()->watchable()->create();
    Card::factory()->videoProcessing()->create();
    Card::factory()->create();

    $offered = app(ViewingRoomFilm::class)->eligible(Card::query())->pluck('id');

    // A first upload still transcoding and a card with no video at all are each
    // a card with nothing to serve, pass or no pass.
    expect($offered)->toHaveCount(1)
        ->and($offered->first())->toBe($watchable->id);
});

it('keeps offering a card whose film is being replaced', function () {
    // The live film keeps playing while its replacement transcodes. Paying for
    // the pass must not mean losing a card the moment Jennifer uploads a better
    // take of it.
    $card = Card::factory()->videoBeingReplaced()->create();

    expect(app(ViewingRoomFilm::class)->eligible(Card::query())->pluck('id'))
        ->toContain($card->id);
});

it('narrows the language gate rather than replacing it', function () {
    $onTheWebsite = Card::factory()->watchable()->create();
    Card::factory()->watchable()->unpublished()->create();
    Card::factory()->watchable()->withoutCopy()->create();
    Card::factory()->watchable()->withoutImage()->create();

    // A pass buys the Viewing Room, not a way past the language gate. Being
    // watchable is built on being in the Library, so a card nobody can read is
    // a card nobody can watch.
    $offered = app(ViewingRoomFilm::class)
        ->eligible(Card::availableIn('en'))
        ->pluck('id');

    expect($offered)->toHaveCount(1)
        ->and($offered->first())->toBe($onTheWebsite->id);
});

it('signs the card live film and says when it expires', function () {
    $card = Card::factory()->watchable()->create();

    $playback = app(ViewingRoomFilm::class)->playbackFor($card);

    expect($playback)->toBeInstanceOf(Playback::class)
        ->and($playback->url)->toContain($card->bunny_video_id)
        ->and($playback->url)->toContain('bcdn_token=')
        ->and($playback->url)->toContain('expires='.$playback->expiresAt->getTimestamp())
        ->and($playback->expiresAt->timestamp)->toEqual(now()->addSeconds(1800)->timestamp);
});

it('reads its own expiry key and not the reveals', function () {
    // The two hold the same number today and are not the same decision.
    // Shortening what the public gets must never reach into what a paying
    // viewer gets, which is the whole reason there are two keys.
    config()->set('services.bunny.playback_ttl', 3600);

    $playback = app(ViewingRoomFilm::class)
        ->playbackFor(Card::factory()->watchable()->create());

    expect($playback->expiresAt->timestamp)->toEqual(now()->addSeconds(3600)->timestamp)
        ->and(config('services.bunny.reveal_ttl'))->toBe(1800);
});

it('has nothing to offer for a card with no watchable film', function () {
    // Not an error. A card this source has nothing for, which the caller turns
    // into the 404 a pass holder sees.
    expect(app(ViewingRoomFilm::class)->playbackFor(
        Card::factory()->videoProcessing()->create(),
    ))->toBeNull();
});

it('hands two pass holders of the same card the same url', function () {
    $card = Card::factory()->watchable()->create();
    $source = app(ViewingRoomFilm::class);

    // Nothing here is bound to who asked. A URL signed for one address refused
    // everybody reaching the video zone from a different one, which is every
    // visitor with IPv6, since the zone is IPv4 only and this API is not.
    expect($source->playbackFor($card)?->url)
        ->toBe($source->playbackFor($card)?->url);
});

it('plays whatever source config/viewing-room.php names', function () {
    $card = Card::factory()->watchable()->create();

    // The seam the paywall did not have until the Viewing Room crossed it.
    // Replacing what a pass buys is one config line, and the endpoint does not
    // learn which adapter it got.
    config()->set('viewing-room.source', FakeFilmSource::class);

    $this->actingAs(Customer::factory()->withViewingRoomAccess()->create(), 'sanctum')
        ->getJson("/api/v1/en/cards/{$card->id}/video")
        ->assertOk()
        ->assertJsonPath('url', FakeFilmSource::URL);
});

it('does not let the public reveal reach into what a pass buys', function () {
    $card = Card::factory()->watchable()->create();

    // The other half of the promise config/viewing-room.php makes. Shortening
    // or swapping what anybody can get for free must leave a paying viewer
    // exactly where they were.
    config()->set('reveal.source', FakeFilmSource::class);

    $url = $this->actingAs(Customer::factory()->withViewingRoomAccess()->create(), 'sanctum')
        ->getJson("/api/v1/en/cards/{$card->id}/video")
        ->assertOk()
        ->json('url');

    expect($url)->not->toBe(FakeFilmSource::URL)
        ->and($url)->toContain('bcdn_token=');
});

it('agrees with itself about which cards it can play', function () {
    // One condition rendered two ways, asked both ways on real rows. The video
    // endpoint takes its 404 from the query while `in_viewing_room` and the
    // panel's subheading take theirs from the predicate, so a disagreement
    // here is a card that says it is watchable and 404s when watched.
    $states = [
        'with a film ready' => fn (): Card => Card::factory()->watchable()->create(),
        'with a first film still being prepared' => fn (): Card => Card::factory()->videoProcessing()->create(),
        'with a film that failed' => fn (): Card => Card::factory()->videoFailed()->create(),
        'with a film being replaced' => fn (): Card => Card::factory()->videoBeingReplaced()->create(),
        'with no film at all' => fn (): Card => Card::factory()->create(),
    ];

    $source = app(ViewingRoomFilm::class);

    foreach ($states as $state => $makeCard) {
        $card = $makeCard();

        $query = Card::query()->whereKey($card->id);
        $source->whatItCanPlay()->constrain($query, 'en');

        expect($query->exists())->toBe(
            $source->whatItCanPlay()->isMetBy($card, 'en'),
            "the Viewing Room disagreed with itself about a card {$state}",
        );
    }

    // The same rows, not merely the same answer a row at a time. This is what
    // `eligible` hands the endpoint, and it is derived from the condition
    // rather than written beside it.
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
    $condition = app(ViewingRoomFilm::class)->whatItCanPlay();

    expect($condition->whatIsMissing(Card::factory()->create(), 'en'))
        ->toBe(['a video'])
        ->and($condition->whatIsMissing(Card::factory()->videoProcessing()->create(), 'en'))
        ->toBe(['its video to finish being prepared'])
        ->and($condition->whatIsMissing(Card::factory()->videoFailed()->create(), 'en'))
        ->toBe(['its video uploading again, because the last one went wrong']);
});
