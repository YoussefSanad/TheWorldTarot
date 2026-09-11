<?php

use App\Models\Card;
use App\Models\Product;
use App\Support\TranslationCoverage;
use Database\Seeders\CardSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProductSeeder;
use Database\Seeders\SpanishContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function seedEnglish(): void
{
    (new ProductSeeder)->run();
    (new CardSeeder)->run();
}

function seedSpanish(): SpanishContentSeeder
{
    $seeder = new SpanishContentSeeder;
    $seeder->run();

    return $seeder;
}

it('fills Spanish product copy from the data file', function () {
    seedEnglish();
    seedSpanish();

    $product = Product::where('key', 'three-card')->first();

    // `false` disables the fallback, so this asks what Spanish actually holds
    // rather than what it would display. Without it every assertion here would
    // pass against a row with no Spanish in it at all.
    expect($product->getTranslation('name', 'es', false))->toBe('LECTURA DE 3 CARTAS')
        ->and($product->getTranslation('short_description', 'es', false))->toBe('Una Pregunta, Tres Cartas')
        ->and($product->getTranslation('long_description', 'es', false))->not->toBeEmpty();
});

it('leaves every product complete in Spanish, which is what the gate asks', function () {
    seedEnglish();
    seedSpanish();

    Product::all()->each(function (Product $product) {
        expect($product->missingTranslations('es'))->toBe([]);
    });
});

it('translates the card names', function () {
    seedEnglish();
    seedSpanish();

    expect(Card::where('number', 0)->first()->getTranslation('name', 'es', false))->toBe('El Loco')
        ->and(Card::where('number', 21)->first()->getTranslation('name', 'es', false))->toBe('El Mundo');
});

it('never touches English', function () {
    seedEnglish();

    $before = Product::orderBy('key')->get()
        ->mapWithKeys(fn (Product $p) => [$p->key->value => $p->getTranslation('name', 'en', false)]);

    seedSpanish();

    $after = Product::orderBy('key')->get()
        ->mapWithKeys(fn (Product $p) => [$p->key->value => $p->getTranslation('name', 'en', false)]);

    expect($after->all())->toBe($before->all());
});

it('leaves a Spanish value somebody has already written', function () {
    seedEnglish();

    // Somebody's edit, however unpromising. Fill-only-what-is-blank is the rule
    // the rest of the codebase follows and the reason this seeder needs no
    // stamp column: it cannot overrule an edit made after it.
    $product = Product::where('key', 'one-card')->first();
    $product->setTranslation('name', 'es', 'UNA CARTA, YA REVISADA');
    $product->save();

    seedSpanish();

    expect(Product::where('key', 'one-card')->first()->getTranslation('name', 'es', false))
        ->toBe('UNA CARTA, YA REVISADA');
});

it('is safe to run twice', function () {
    seedEnglish();
    seedSpanish();
    seedSpanish();

    expect(Product::where('key', 'three-card')->first()->getTranslation('name', 'es', false))
        ->toBe('LECTURA DE 3 CARTAS');
});

it('saves nothing on a second run, having nothing left to fill', function () {
    seedEnglish();
    seedSpanish();

    // Eloquent fires `saved` even when nothing changed, and
    // TranslatableContentObserver answers every `saved` by walking every
    // published record. A save with nothing in it would do that for nothing,
    // once per product and card.
    $saves = 0;
    Event::listen('eloquent.saved: *', function () use (&$saves) {
        $saves++;
    });

    seedSpanish();

    expect($saves)->toBe(0);
});

it('refuses a data file that is missing Spanish for published copy that has English', function () {
    seedEnglish();

    // A partial data file is a language that cannot go live for reasons this
    // seeder would otherwise have hidden: the gate refuses, and nothing points
    // back here. So it fails at run time, naming what is absent.
    $seeder = new SpanishContentSeeder;

    expect(fn () => $seeder->fillFrom(
        Product::where('key', 'one-card')->first(),
        ['name' => 'LECTURA DE 1 CARTA'],
        'one-card',
    ))->toThrow(RuntimeException::class, 'short_description');
});

it('writes nothing at all when it refuses', function () {
    seedEnglish();

    // A published card with English copy and no line in the data file.
    // Products are filled before cards, so a run that saved as it went would
    // refuse with five products already in Spanish and the report never printed.
    Card::factory()->create(['number' => 22, 'name' => ['en' => 'The Twenty-Third']]);

    expect(fn () => seedSpanish())->toThrow(RuntimeException::class, 'card 22');

    expect(Product::where('key', 'three-card')->first()->getTranslation('name', 'es', false))
        ->toBeEmpty();
});

it('does not refuse over a draft the gate does not count', function () {
    seedEnglish();

    // The gate counts published records only, so a card still being written
    // cannot hold Spanish back, and refusing over it would stop everything
    // else loading for a reason the Languages screen would never show.
    Card::factory()->create([
        'number' => 22,
        'name' => ['en' => 'The Twenty-Third'],
        'is_published' => false,
    ]);

    seedSpanish();

    expect(Card::where('number', 0)->first()->getTranslation('name', 'es', false))->toBe('El Loco')
        ->and(Card::where('number', 22)->first()->getTranslation('name', 'es', false))->toBeEmpty();
});

it('does not demand Spanish for copy that has no English', function () {
    seedEnglish();

    // CardSeeder seeds names only. A card description that does not exist in
    // English is not waiting on a translator, so demanding one here would be
    // demanding a translation of nothing, and writing one would leave a
    // Spanish library holding 22 cards and an English one holding none.
    $seeder = seedSpanish();

    expect($seeder->waitingOnEnglish)->not->toBeEmpty()
        ->and(Card::where('number', 0)->first()->getTranslation('short_description', 'es', false))
        ->toBeEmpty();
});

it('reports every card description still waiting on English', function () {
    seedEnglish();

    $seeder = seedSpanish();

    // 22 cards, two descriptions each, and nothing else in the deck.
    expect($seeder->waitingOnEnglish)->toHaveCount(44);
});

it('does not report a draft as holding Spanish back', function () {
    seedEnglish();

    // The report says Spanish cannot go live until each line is written. For a
    // draft that is false, since the gate does not count one.
    Card::factory()->create([
        'number' => 22,
        'name' => ['en' => 'The Twenty-Third'],
        'short_description' => ['en' => ''],
        'long_description' => ['en' => ''],
        'is_published' => false,
    ]);

    $seeder = seedSpanish();

    expect($seeder->waitingOnEnglish)->toHaveCount(44);
});

it('stops rather than inventing a product row the seeder does not own', function () {
    // ProductSeeder owns creation. A missing row means it has not run here, and
    // creating one would race it.
    expect(fn () => seedSpanish())
        ->toThrow(RuntimeException::class, 'Run ProductSeeder first');
});

it('is deliberately not registered to run on every deploy', function () {
    // The two seeders in DatabaseSeeder carry a stamp because "fill what is
    // empty" repeated on every deploy also undoes emptying something, and
    // clearing a description is how the panel takes a record off the site in a
    // language. This one runs when a person asks, so it needs no stamp, and
    // that only holds while it stays out of here.
    $registered = (new ReflectionClass(DatabaseSeeder::class))->getFileName();

    expect(file_get_contents($registered))->not->toContain('SpanishContentSeeder');
});

it('does not finish Spanish while the cards have no English', function () {
    seedEnglish();
    seedSpanish();

    // The honest end state today: products are done, cards are not, and the
    // gate names them. Spanish going live is blocked on Jennifer's English
    // card copy, not on this seeder.
    expect(TranslationCoverage::isComplete('es'))->toBeFalse()
        ->and(TranslationCoverage::progress('es'))->toBe(['complete' => 5, 'total' => 27]);
});
