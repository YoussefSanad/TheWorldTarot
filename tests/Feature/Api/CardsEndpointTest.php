<?php

use App\Enums\LocaleStatus;
use App\Models\Admin;
use App\Models\Card;
use App\Models\Locale;
use App\Support\Locales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The reveal signs a URL, so these tests need Bunny configured even though
    // signing never leaves the process. Never a real request, per
    // .claude/testing.md.
    Http::preventStrayRequests();

    config()->set('services.bunny.token_key', 'a-token-key');
    config()->set('services.bunny.cdn_hostname', 'vz-example.b-cdn.net');
    config()->set('services.bunny.reveal_ttl', 1800);
});

function cardCopy(int $number): array
{
    return [
        'number' => $number,
        'name' => ['en' => 'The Fool'],
        'short_description' => ['en' => 'A beginning.'],
        'long_description' => ['en' => 'The longer meaning of the card.'],
    ];
}

function writtenCard(int $number, array $attributes = []): Card
{
    return Card::factory()->create(array_merge(cardCopy($number), $attributes));
}

/**
 * A card the reveal can draw: written and pictured, with a film to play.
 *
 * The reveal draws from a narrower pool than the Library since 2 August 2026, so
 * being on the website is no longer enough for it.
 */
function revealableCard(int $number, array $attributes = []): Card
{
    return Card::factory()->watchable()->create(array_merge(cardCopy($number), $attributes));
}

it('returns the agreed shape for a card on the website', function () {
    $card = writtenCard(0);

    $this->getJson('/api/v1/en/cards')
        ->assertOk()
        ->assertExactJson([
            [
                'id' => $card->id,
                'number' => 0,
                'name' => 'The Fool',
                'short_description' => 'A beginning.',
                'long_description' => 'The longer meaning of the card.',
                'image' => $card->imageUrl(),
                'in_viewing_room' => false,
            ],
        ]);
});

it('carries the long copy in the listing, so opening a card costs nothing', function () {
    writtenCard(0);

    // The opposite of the readings listing, and deliberately so: the Library
    // opens a card in a pop-up on a page that already holds the whole deck.
    $this->getJson('/api/v1/en/cards')
        ->assertOk()
        ->assertJsonPath('0.long_description', 'The longer meaning of the card.');
});

it('returns the same shape from all three endpoints', function () {
    $card = revealableCard(0);

    $listed = $this->getJson('/api/v1/en/cards')->json('0');
    $shown = $this->getJson("/api/v1/en/cards/{$card->id}")->json();
    $drawn = $this->getJson('/api/v1/en/cards/draw')->json();

    // One shape means the frontend renders one component wherever a card came
    // from. The reveal adds to it and changes nothing in it, so a card is still
    // a card whatever endpoint handed it over.
    expect($shown)->toBe($listed)
        ->and(collect($drawn)->except('video')->all())->toBe($listed);
});

it('lists the deck in order', function () {
    writtenCard(3);
    writtenCard(0);
    writtenCard(11);

    $this->getJson('/api/v1/en/cards')
        ->assertOk()
        ->assertJsonPath('0.number', 0)
        ->assertJsonPath('1.number', 3)
        ->assertJsonPath('2.number', 11);
});

it('leaves out a card that is not written yet', function () {
    writtenCard(0);
    Card::factory()->withoutCopy()->create(['number' => 1]);

    $this->getJson('/api/v1/en/cards')
        ->assertOk()
        ->assertJsonCount(1);
});

it('leaves out a card with no picture, however well written', function () {
    writtenCard(0);
    $unpictured = writtenCard(1, ['image_path' => null]);

    // The Library shows the card. One with no picture is not something anyone
    // can be shown.
    $this->getJson('/api/v1/en/cards')->assertJsonCount(1);
    $this->getJson("/api/v1/en/cards/{$unpictured->id}")->assertNotFound();
});

it('always carries a real picture address, never null', function () {
    writtenCard(0);

    // A card with no picture never reaches the response at all, so the
    // frontend never has to handle a missing image.
    $this->getJson('/api/v1/en/cards')
        ->assertOk()
        ->assertJsonPath('0.image', fn (?string $image): bool => filled($image));
});

it('leaves out a card that is not published', function () {
    writtenCard(0);
    $hidden = writtenCard(1, ['is_published' => false]);

    $this->getJson('/api/v1/en/cards')->assertJsonCount(1);
    $this->getJson("/api/v1/en/cards/{$hidden->id}")->assertNotFound();
});

it('is a 404 for a card that does not exist', function () {
    $this->getJson('/api/v1/en/cards/999')->assertNotFound();
});

it('is a 404 for an id that is not a number', function () {
    writtenCard(0);

    // The route only matches digits, so a made up word is not a card and never
    // reaches the controller.
    $this->getJson('/api/v1/en/cards/the-fool')->assertNotFound();
});

it('shows a card in the language asked for and hides it in the one it is missing', function () {
    Locale::where('code', 'es')->update(['status' => LocaleStatus::Live]);
    Locales::forget();

    $translated = writtenCard(0, [
        'name' => ['en' => 'The Fool', 'es' => 'El Loco'],
        'short_description' => ['en' => 'A beginning.', 'es' => 'Un comienzo.'],
        'long_description' => ['en' => 'The longer one.', 'es' => 'La larga.'],
    ]);

    $this->getJson("/api/v1/es/cards/{$translated->id}")
        ->assertOk()
        ->assertJsonPath('name', 'El Loco');

    // Spanish only reaches the API while every card is translated, so the
    // untranslated one arrives after the language is live.
    $english = writtenCard(1);

    $this->getJson("/api/v1/en/cards/{$english->id}")->assertOk();
});

it('serves no card at all in a language that is not public', function () {
    writtenCard(0);

    // Spanish is a draft. A language that is not public is not an address.
    $this->getJson('/api/v1/es/cards')->assertNotFound();
    $this->getJson('/api/v1/es/cards/draw')->assertNotFound();
    $this->getJson('/api/v1/fr/cards')->assertNotFound();
});

it('serves a language in preview to a signed in admin only', function () {
    Locale::where('code', 'es')->update(['status' => LocaleStatus::Preview]);
    Locales::forget();

    writtenCard(0, [
        'name' => ['en' => 'The Fool', 'es' => 'El Loco'],
        'short_description' => ['en' => 'A beginning.', 'es' => 'Un comienzo.'],
        'long_description' => ['en' => 'The longer one.', 'es' => 'La larga.'],
    ]);

    $this->getJson('/api/v1/es/cards')->assertNotFound();

    $this->actingAs(Admin::factory()->create(), 'admin')
        ->getJson('/api/v1/es/cards')
        ->assertOk()
        ->assertJsonPath('0.name', 'El Loco');
});

it('draws a card at random from the ones on the website', function () {
    revealableCard(0);
    revealableCard(1);
    Card::factory()->watchable()->withoutCopy()->create(['number' => 2]);
    revealableCard(3, ['image_path' => null]);

    $drawn = collect(range(1, 20))
        ->map(fn (): int => $this->getJson('/api/v1/en/cards/draw')->json('number'));

    // A card nobody has written must never be the one revealed, film or no film.
    expect($drawn->unique()->sort()->values()->all())->toBe([0, 1]);
});

it('draws only cards with a film to play', function () {
    revealableCard(0);
    writtenCard(1);
    Card::factory()->videoProcessing()->create(cardCopy(2));

    $drawn = collect(range(1, 20))
        ->map(fn (): int => $this->getJson('/api/v1/en/cards/draw')->json('number'));

    // A card with no film and one still transcoding are each a reveal with
    // nothing to show.
    expect($drawn->unique()->all())->toBe([0]);
});

it('never draws a card with a film and no picture', function () {
    revealableCard(0);
    $unpictured = revealableCard(1, ['image_path' => null]);

    $drawn = collect(range(1, 20))
        ->map(fn (): int => $this->getJson('/api/v1/en/cards/draw')->json('number'));

    // The reveal shows the card as well as playing its film, so one with
    // nothing to show is a reveal with a hole in it. Since 7 August 2026 the
    // picture is also what a player rests on, the poster frame having left the
    // system, which makes this the only still there is.
    //
    // Worth its own test rather than leaning on the random-draw one: the gate
    // lives in `availableIn` on the caller's side, and `FullFilmRevealVideo`
    // narrows only on the film. A source that was handed a bare query, or a new
    // source written later, would widen the pool with nothing here failing.
    expect($drawn->unique()->all())->toBe([0])
        ->and($unpictured->isAvailableIn('en'))->toBeFalse();
});

it('refuses to be cached, so the reveal is not the same card for everybody', function () {
    revealableCard(0);

    $response = $this->getJson('/api/v1/en/cards/draw')->assertOk();

    // Laravel adds `private` of its own accord. What matters is that no-store
    // is in there, so no CDN and no browser keeps the answer.
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    // And that it is this endpoint only. The Library is meant to be cached.
    $listing = $this->getJson('/api/v1/en/cards')->assertOk();

    expect($listing->headers->get('Cache-Control'))->not->toContain('no-store');
});

it('is a 404 when there is no card to reveal', function () {
    Card::factory()->withoutCopy()->create(['number' => 0]);

    $this->getJson('/api/v1/en/cards/draw')
        ->assertNotFound()
        ->assertJsonPath('message', 'There are no cards on the website in this language yet.');
});

it('is a 404 when the deck is on the website with no films yet', function () {
    writtenCard(0);
    writtenCard(1);

    // No fallback to a card without a film, decided 2 August 2026 on the basis
    // that one will not exist in the system. Recorded as a test because it is
    // the state a freshly seeded environment is in, and the homepage reveal is
    // a 404 in it rather than a card with nothing to play.
    $this->getJson('/api/v1/en/cards/draw')
        ->assertNotFound()
        ->assertJsonPath('message', 'No card on the website has a film to play yet.');
});

it('says which kind of nothing it has, so a 404 here is not a mystery', function () {
    // Staging spent 7 August 2026 answering `{"message": ""}`, which reads like
    // a routing mistake and is not one. These are the only two ways to reach
    // this 404 and they need different things done about them, so they must not
    // look the same from outside.
    $empty = $this->getJson('/api/v1/en/cards/draw')->assertNotFound()->json('message');

    writtenCard(0);

    $filmless = $this->getJson('/api/v1/en/cards/draw')->assertNotFound()->json('message');

    expect($empty)->not->toBe('')
        ->and($filmless)->not->toBe('')
        ->and($empty)->not->toBe($filmless);
});

it('is not mistaken for a card called draw', function () {
    revealableCard(0);

    // The literal route sits above the id route. Without that, the draw is a
    // lookup for a card that cannot exist.
    $this->getJson('/api/v1/en/cards/draw')
        ->assertOk()
        ->assertJsonPath('number', 0);
});

it('hands the drawn card a signed film that expires', function () {
    $card = revealableCard(0);

    $response = $this->getJson('/api/v1/en/cards/draw')->assertOk();

    expect($response->json('video.url'))
        ->toContain($card->bunny_video_id)
        ->toContain('token=')
        ->and($response->json('video.expires_at'))
        ->toBe(now()->addSeconds(1800)->toIso8601String());
});

it('keeps the film credential out of every endpoint that is meant to be cached', function () {
    $card = revealableCard(0);

    // **The rule this whole resource split exists for.** The Library carries
    // every card and is meant to be cached, so twenty-two short-lived
    // credentials in a held response would be a leak and a caching bug at once.
    // Asserted directly because it is the thing a careless change would break
    // quietly, and because a signed URL in a cached listing is not the kind of
    // fault anybody notices by looking at the site.
    $listed = $this->getJson('/api/v1/en/cards')->assertOk()->json('0');
    $shown = $this->getJson("/api/v1/en/cards/{$card->id}")->assertOk()->json();

    expect($listed)->not->toHaveKey('video')
        ->and($shown)->not->toHaveKey('video')
        ->and(json_encode([$listed, $shown]))->not->toContain('token=');
});

it('limits how fast one caller can be handed films', function () {
    revealableCard(0);

    // Not the paywall and not pretending to be one: the deck is twenty-two
    // requests and the per-visitor limit lives in a frontend cookie. This stops
    // one caller signing thousands of URLs an hour, which is all it claims.
    collect(range(1, 30))->each(fn () => $this->getJson('/api/v1/en/cards/draw')->assertOk());

    $this->getJson('/api/v1/en/cards/draw')->assertStatus(429);
});

it('says which cards have a film', function () {
    $watchable = Card::factory()->watchable()->create(['number' => 30]);
    $unwatchable = writtenCard(31);

    $library = $this->getJson('/api/v1/en/cards')->assertOk()->json();

    // The Viewing Room is advertised: the site shows all twenty-two, locked,
    // and invites people to buy the pass. This is what lets it do that in one
    // request rather than asking for every video and reading the refusals.
    $filmed = collect($library)->firstWhere('id', $watchable->id);
    $unfilmed = collect($library)->firstWhere('id', $unwatchable->id);

    expect($filmed['in_viewing_room'])->toBeTrue()
        ->and($unfilmed['in_viewing_room'])->toBeFalse();
});

it('does not call a card watchable while its video is being prepared', function () {
    $card = Card::factory()->videoProcessing()->create();

    // Advertising a film that cannot be played yet would send a pass holder to
    // an endpoint that answers 404.
    $this->getJson('/api/v1/en/cards')
        ->assertOk()
        ->assertJsonPath('0.in_viewing_room', false);

    expect($card->isViewableIn('en'))->toBeFalse();
});

it('never hands out the video itself alongside the library', function () {
    $card = Card::factory()->watchable()->create();

    $listed = $this->getJson('/api/v1/en/cards')->assertOk()->json('0');

    // The listing is cached and public. A playback URL is a credential with a
    // two hour life, and twenty-two of them in a cached response is both a leak
    // and a caching bug.
    expect($listed)->not->toHaveKey('url')
        ->and($listed)->not->toHaveKey('video')
        ->and(json_encode($listed))->not->toContain($card->bunny_video_id);
});
