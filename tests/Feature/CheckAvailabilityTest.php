<?php

use App\Console\Commands\CheckAvailability;
use App\Models\Card;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command as Exit_;

uses(RefreshDatabase::class);

/**
 * Runs the command and hands back its exit code and everything it printed.
 *
 * Asserted against the whole output rather than through `expectsOutputToContain`
 * deliberately: that helper matches each expectation against the output in
 * order and consumes the line it matched, so asserting on a record's name and
 * then on what it needs looks for the second half on a line the first half
 * already ate.
 *
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function runTheCheck(array $options = []): array
{
    $exit = Artisan::call('availability:check', $options);

    return [$exit, Artisan::output()];
}

it('agrees about every row in a database with every awkward state in it', function () {
    Card::factory()->watchable()->translatedInto('es')->create();
    Card::factory()->withoutImage()->create();
    Card::factory()->unpublished()->watchable()->create();
    Card::factory()->withoutCopy()->create();
    Card::factory()->videoFailed()->create();
    tap(Card::factory()->watchable()->create(), fn (Card $card) => $card->delete());

    Product::factory()->create();
    Product::factory()->unpublished()->create();
    Product::factory()->create(['price_gbp' => 0]);
    tap(Product::factory()->pass()->create(), fn (Product $product) => $product->delete());

    [$exit, $output] = runTheCheck(['--locale' => ['en', 'es']]);

    expect($exit)->toBe(Exit_::SUCCESS)
        ->and($output)->toContain('agree about every row')
        // Every row was actually looked at, in both languages, rather than the
        // command finding nothing to check and reporting success for it.
        ->and($output)->toContain('Cards, English')
        ->and($output)->toContain('Spanish')
        ->and($output)->toContain('6 rows, agreed')
        ->and($output)->not->toContain('disagreed');
});

it('runs against an empty database without deciding anything is wrong', function () {
    [$exit, $output] = runTheCheck();

    expect($exit)->toBe(Exit_::SUCCESS)
        ->and($output)->toContain('agree about every row');
});

it('names what each record is waiting for when asked to explain', function () {
    Card::factory()->withoutImage()->create(['name' => ['en' => 'The Tower']]);
    Product::factory()->create(['price_gbp' => 0, 'name' => ['en' => 'Month Ahead']]);

    [$exit, $output] = runTheCheck(['--locale' => ['en'], '--explain' => true]);

    expect($exit)->toBe(Exit_::SUCCESS)
        ->and($output)->toContain('The Tower')
        ->and($output)->toContain('needs a picture')
        ->and($output)->toContain('Month Ahead')
        ->and($output)->toContain('needs a price in every currency')
        // The Viewing Room is asked separately, and reads the website last.
        ->and($output)->toContain('needs a video and to be on the website first');
});

it('asks about a language nothing is translated into', function () {
    Card::factory()->create(['name' => ['en' => 'The Fool']]);

    // The check is worth little if it only ever asks about English, which is
    // the language everything is finished in by definition.
    [$exit, $output] = runTheCheck(['--locale' => ['es'], '--explain' => true]);

    expect($exit)->toBe(Exit_::SUCCESS)
        ->and($output)->toContain('The Fool')
        ->and($output)->toContain('needs a name');
});

it('says which database and environment it asked, since that is the whole point', function () {
    [, $output] = runTheCheck();

    // A clean run against the wrong database is the failure mode this command
    // has, and the only defence is saying out loud where it looked.
    expect($output)->toContain('testing')
        ->and($output)->toContain('the_world_tarot_testing');
});

/**
 * The judgement the command makes, proved without a database.
 *
 * A run that reports nothing is only worth something if the thing reporting can
 * tell a disagreement from agreement. No row this codebase can produce
 * disagrees any more, which is the point of the refactor and also why the
 * denial path cannot be reached through the models.
 */
it('says nothing when all three renderings answer the same way', function (bool $answer) {
    expect(CheckAvailability::disagreement($answer, $answer, $answer))->toBeNull();
})->with([true, false]);

it('names every way the three renderings can fall out of step', function (bool $query, bool $predicate, bool $prose, string $expected) {
    $found = CheckAvailability::disagreement($query, $predicate, $prose);

    expect($found)->not->toBeNull()
        ->and($found)->toContain($expected);
})->with([
    // The one that used to happen: a removed record absent from every listing
    // and still answering yes when asked directly.
    'listing no, record yes' => [false, true, true, 'the listing says no, a record in hand says yes'],
    // The one the panel showed: off the website with nothing to say why.
    'off the website, panel silent' => [false, false, true, 'the panel says it is finished'],
    'on the website, panel complaining' => [true, true, false, 'the panel says it is waiting for something'],
    'listing yes, record no' => [true, false, false, 'the listing says yes, a record in hand says no'],
    'two against one' => [true, false, true, 'a record in hand says no'],
]);
