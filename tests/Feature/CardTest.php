<?php

use App\Models\Card;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('stores translatable columns as jsonb, not json', function () {
    $types = collect(DB::select(
        "select column_name, data_type
         from information_schema.columns
         where table_name = 'cards'
           and column_name in ('name', 'short_description', 'long_description')"
    ))->pluck('data_type', 'column_name');

    // json is not usefully indexable on Postgres. See .claude/translation.md.
    expect($types)->each->toBe('jsonb')
        ->and($types)->toHaveCount(3);
});

it('has no slug column, since a card is addressed by its id', function () {
    expect(Schema::hasColumn('cards', 'slug'))->toBeFalse();
});

it('stores and returns copy per locale', function () {
    $card = Card::factory()->create([
        'name' => ['en' => 'The Fool', 'es' => 'El Loco'],
    ]);

    expect($card->getTranslation('name', 'en'))->toBe('The Fool')
        ->and($card->getTranslation('name', 'es'))->toBe('El Loco');
});

it('reads a missing translation as an empty string, not null', function () {
    $card = Card::factory()->create(['name' => ['en' => 'The Fool']]);

    // The quirk every completeness check is written around. Testing for a
    // missing translation with === null silently passes for every record.
    expect($card->getTranslation('name', 'es', false))->toBe('')
        ->and($card->getTranslation('name', 'es', false))->not->toBeNull();
});

it('refuses two cards with the same number', function () {
    Card::factory()->create(['number' => 7]);

    expect(fn () => Card::factory()->create(['number' => 7]))
        ->toThrow(QueryException::class);
});

it('lets a deleted card give its number back', function () {
    $card = Card::factory()->create(['number' => 7]);
    $card->delete();

    // The number is unique only among cards that are not deleted. Without
    // that, removing a card added by mistake and adding it again fails on a
    // constraint, in the one resource Jennifer creates rows in.
    $replacement = Card::factory()->create(['number' => 7]);

    expect($replacement->exists)->toBeTrue()
        ->and(Card::where('number', 7)->count())->toBe(1);
});

it('keeps a soft deleted card so its video is not orphaned', function () {
    $card = Card::factory()->create();
    $card->delete();

    expect(Card::find($card->id))->toBeNull()
        ->and(Card::withTrashed()->find($card->id))->not->toBeNull();
});

it('is on the website when it is finished in the language asked for', function () {
    $card = Card::factory()->translatedInto('es')->create();

    expect($card->isAvailableIn('en'))->toBeTrue()
        ->and($card->isAvailableIn('es'))->toBeTrue()
        ->and(Card::availableIn('es')->pluck('id'))->toContain($card->id);
});

it('is absent from a language it is not finished in', function () {
    $card = Card::factory()->create();

    expect($card->isAvailableIn('en'))->toBeTrue()
        ->and($card->isAvailableIn('es'))->toBeFalse()
        ->and(Card::availableIn('es')->pluck('id'))->not->toContain($card->id);
});

it('is absent everywhere when it is unfinished in the original language', function (string $missing) {
    $card = Card::factory()->translatedInto('es')->create([$missing => ['es' => 'Algo']]);

    // A card that is not finished in English is not finished at all. It does
    // not get to exist in Spanish alone.
    expect($card->isAvailableIn('en'))->toBeFalse()
        ->and($card->isAvailableIn('es'))->toBeFalse()
        ->and(Card::availableIn('es')->count())->toBe(0);
})->with(['name', 'short_description', 'long_description']);

it('treats copy that is only whitespace as missing', function () {
    $card = Card::factory()->create(['short_description' => ['en' => '   ']]);

    expect($card->isAvailableIn('en'))->toBeFalse()
        ->and(Card::availableIn('en')->count())->toBe(0);
});

it('is absent everywhere until it has a picture', function () {
    $card = Card::factory()->withoutImage()->translatedInto('es')->create();

    // A card needs its copy and its picture, the way a reading needs its copy
    // and a price in every currency.
    expect($card->isAvailableIn('en'))->toBeFalse()
        ->and($card->isAvailableIn('es'))->toBeFalse()
        ->and(Card::availableIn('en')->count())->toBe(0)
        ->and($card->whatIsMissing('en'))->toBe(['a picture']);
});

it('holds the path alone, so the bucket can move without touching a row', function () {
    $card = Card::factory()->create(['image_path' => 'cards/the-fool.jpg']);

    expect($card->image_path)->toBe('cards/the-fool.jpg')
        ->and($card->imageUrl())->toContain('cards/the-fool.jpg')
        ->and(Card::factory()->withoutImage()->create()->imageUrl())->toBeNull();
});

it('is absent when unpublished, however finished it is', function () {
    $card = Card::factory()->unpublished()->translatedInto('es')->create();

    expect($card->isAvailableIn('en'))->toBeFalse()
        ->and(Card::availableIn('en')->count())->toBe(0);
});

it('says which fields a language is still waiting for', function () {
    $card = Card::factory()->create([
        'name' => ['en' => 'The Fool', 'es' => 'El Loco'],
    ]);

    expect($card->missingTranslations('es'))
        ->toBe(['short_description', 'long_description'])
        ->and($card->missingTranslations('en'))->toBe([]);
});

it('filters in the query and in PHP identically', function () {
    Card::factory()->translatedInto('es')->create();
    Card::factory()->create();
    Card::factory()->unpublished()->create();
    Card::factory()->withoutCopy()->create();

    // The scope is what listings use, the method is what a single record uses.
    // If they ever disagree, one of them leaks a card that is not on the
    // website.
    foreach (['en', 'es'] as $locale) {
        $byQuery = Card::availableIn($locale)->pluck('id')->sort()->values();
        $byMethod = Card::withoutGlobalScopes()->get()
            ->filter(fn (Card $card): bool => $card->isAvailableIn($locale))
            ->pluck('id')->sort()->values();

        expect($byQuery->all())->toBe($byMethod->all());
    }
});
