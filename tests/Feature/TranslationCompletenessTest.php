<?php

use App\Enums\ProductKey;
use App\Models\Product;
use App\Support\TranslationCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A product finished in Spanish.
 */
function translated(array $attributes = []): Product
{
    return Product::factory()->create(array_merge([
        'name' => ['en' => 'One Card Reading', 'es' => 'Lectura de Una Carta'],
        'short_description' => ['en' => 'A single card.', 'es' => 'Una sola carta.'],
        'long_description' => ['en' => 'The long one.', 'es' => 'La larga.'],
    ], $attributes));
}

it('counts a record complete only when every required field is translated', function () {
    $product = translated();

    expect($product->isCompleteIn('es'))->toBeTrue()
        ->and($product->missingTranslations('es'))->toBe([]);
});

it('names the fields a language is still waiting for', function () {
    $product = Product::factory()->create([
        'name' => ['en' => 'One Card Reading', 'es' => 'Lectura de Una Carta'],
        'short_description' => ['en' => 'A single card.'],
        'long_description' => ['en' => 'The long one.'],
    ]);

    // The point of naming them: a refusal that says what to do beats one that
    // says something is wrong.
    expect($product->missingTranslations('es'))
        ->toBe(['short_description', 'long_description'])
        ->and($product->isCompleteIn('es'))->toBeFalse();
});

it('treats an empty translation as missing, not as an answer', function () {
    $product = translated(['short_description' => ['en' => 'A single card.', 'es' => '   ']]);

    // Whitespace is what a paste that went wrong looks like.
    expect($product->missingTranslations('es'))->toBe(['short_description']);
});

it('holds the default language to the same standard as any other', function () {
    $product = Product::factory()->create([
        'name' => ['en' => 'One Card Reading'],
        'short_description' => ['en' => ''],
        'long_description' => ['en' => ''],
    ]);

    // English used to be exempt, on the grounds that a publish switch covered
    // it. That switch is gone, so a reading with no description is not ready to
    // be read in any language, English included.
    expect($product->isCompleteIn('en'))->toBeFalse()
        ->and($product->missingTranslations('en'))
        ->toBe(['short_description', 'long_description']);
});

it('filters incomplete records out of a query rather than after it', function () {
    translated(['key' => ProductKey::OneCard]);
    Product::factory()->create(['key' => ProductKey::ThreeCard]); // English only

    // Filtering in the query is what stops a listing leaking an untranslated
    // record because someone forgot to check.
    expect(Product::completeIn('es')->pluck('key')->all())->toBe([ProductKey::OneCard])
        ->and(Product::completeIn('en')->count())->toBe(2);
});

it('reports a language complete only when nothing published is outstanding', function () {
    translated(['key' => ProductKey::OneCard]);

    expect(TranslationCoverage::isComplete('es'))->toBeTrue();

    Product::factory()->create(['key' => ProductKey::ThreeCard]);

    expect(TranslationCoverage::isComplete('es'))->toBeFalse();
});

it('ignores unpublished records, which block nobody', function () {
    translated(['key' => ProductKey::OneCard]);
    Product::factory()->unpublished()->create(['key' => ProductKey::ThreeCard]);

    // A draft nobody can see is not a reason to hold back a language.
    expect(TranslationCoverage::isComplete('es'))->toBeTrue();
});

it('lists what is outstanding, with the record and the fields', function () {
    translated(['key' => ProductKey::OneCard]);
    Product::factory()->create([
        'key' => ProductKey::ThreeCard,
        'name' => ['en' => 'Three Card Reading'],
    ]);

    $outstanding = TranslationCoverage::outstanding('es');

    expect($outstanding)->toHaveCount(1)
        ->and($outstanding->first()['label'])->toBe('Three Card Reading')
        ->and($outstanding->first()['fields'])
        ->toBe(['name', 'short_description', 'long_description']);
});

it('reports the default language as complete and outstanding nothing', function () {
    Product::factory()->create();

    expect(TranslationCoverage::isComplete('en'))->toBeTrue()
        ->and(TranslationCoverage::outstanding('en'))->toBeEmpty();
});

it('counts progress for the summary line', function () {
    translated(['key' => ProductKey::OneCard]);
    Product::factory()->create(['key' => ProductKey::ThreeCard]);
    Product::factory()->create(['key' => ProductKey::MonthAhead]);

    expect(TranslationCoverage::progress('es'))->toBe(['complete' => 1, 'total' => 3]);
});

it('turns incomplete again when a new product is published after a language is live', function () {
    translated(['key' => ProductKey::OneCard]);
    expect(TranslationCoverage::isComplete('es'))->toBeTrue();

    // The case the whole per-record rule exists for: content added after a
    // language went live.
    Product::factory()->create(['key' => ProductKey::ThreeCard]);

    expect(TranslationCoverage::isComplete('es'))->toBeFalse();
});
