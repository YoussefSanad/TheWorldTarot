<?php

use App\Enums\LocaleStatus;
use App\Models\Admin;
use App\Models\Card;
use App\Models\Locale;
use App\Support\Locales;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function writtenCard(int $number, array $attributes = []): Card
{
    return Card::factory()->create(array_merge([
        'number' => $number,
        'name' => ['en' => 'The Fool'],
        'short_description' => ['en' => 'A beginning.'],
        'long_description' => ['en' => 'The longer meaning of the card.'],
    ], $attributes));
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
    $card = writtenCard(0);

    $listed = $this->getJson('/api/v1/en/cards')->json('0');
    $shown = $this->getJson("/api/v1/en/cards/{$card->id}")->json();
    $drawn = $this->getJson('/api/v1/en/cards/random')->json();

    // One shape means the frontend renders one component wherever a card came
    // from.
    expect($shown)->toBe($listed)
        ->and($drawn)->toBe($listed);
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
    $this->getJson('/api/v1/es/cards/random')->assertNotFound();
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
    writtenCard(0);
    writtenCard(1);
    Card::factory()->withoutCopy()->create(['number' => 2]);
    writtenCard(3, ['image_path' => null]);

    $drawn = collect(range(1, 20))
        ->map(fn (): int => $this->getJson('/api/v1/en/cards/random')->json('number'));

    // A card nobody has written must never be the one revealed.
    expect($drawn->unique()->sort()->values()->all())->toBe([0, 1]);
});

it('refuses to be cached, so the reveal is not the same card for everybody', function () {
    writtenCard(0);

    $response = $this->getJson('/api/v1/en/cards/random')->assertOk();

    // Laravel adds `private` of its own accord. What matters is that no-store
    // is in there, so no CDN and no browser keeps the answer.
    expect($response->headers->get('Cache-Control'))->toContain('no-store');

    // And that it is this endpoint only. The Library is meant to be cached.
    $listing = $this->getJson('/api/v1/en/cards')->assertOk();

    expect($listing->headers->get('Cache-Control'))->not->toContain('no-store');
});

it('is a 404 when there is no card to reveal', function () {
    Card::factory()->withoutCopy()->create(['number' => 0]);

    $this->getJson('/api/v1/en/cards/random')->assertNotFound();
});

it('is not mistaken for a card called random', function () {
    writtenCard(0);

    // The literal route sits above the id route. Without that, the reveal is a
    // lookup for a card that cannot exist.
    $this->getJson('/api/v1/en/cards/random')
        ->assertOk()
        ->assertJsonPath('number', 0);
});
