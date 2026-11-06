<?php

use Database\Seeders\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/*
 * Step 13 of the card pictures plan (card copy amendment): criterion 31.
 *
 * This file validates the SHAPE of database/seed-copy/cards.en.json, not its
 * wording. A description under the wrong card number passes every assertion
 * here; the user's read of the pull request diff is the only check of the
 * words, and that approval is recorded on #95.
 *
 * Keys are checked by walking the raw text, not by json_decode, because
 * json_decode silently keeps the last of a repeated key. The walk tracks string
 * state, escapes and object depth, so it holds whatever the file's layout,
 * pretty-printed or minified.
 */

function seedCopyAssetPath(): string
{
    return database_path('seed-copy/cards.en.json');
}

function seedCopyAssetText(): string
{
    $path = seedCopyAssetPath();
    expect(is_file($path))->toBeTrue('missing: '.$path);

    return (string) @file_get_contents($path);
}

/**
 * The keys of the top-level object, and of each object directly inside it, in
 * the order they appear in the raw text, repeats included.
 *
 * @return array{top: array<int, string>|null, entries: array<int, array{0: string|null, 1: array<int, string>}>}
 */
function seedCopyAssetKeys(string $json): array
{
    $stack = [];
    $top = null;
    $entries = [];
    $length = strlen($json);
    $i = 0;

    while ($i < $length) {
        $char = $json[$i];

        if ($char === '"') {
            $end = $i + 1;

            while ($end < $length && $json[$end] !== '"') {
                $end += $json[$end] === '\\' ? 2 : 1;
            }

            $string = json_decode(substr($json, $i, $end - $i + 1));
            $next = $end + 1;

            while ($next < $length && ctype_space($json[$next])) {
                $next++;
            }

            // A string followed by a colon inside an object is a key.
            if ($next < $length && $json[$next] === ':' && $stack !== [] && $stack[count($stack) - 1]['type'] === 'object') {
                $stack[count($stack) - 1]['keys'][] = $string;
                $stack[count($stack) - 1]['last'] = $string;
            }

            $i = $end + 1;

            continue;
        }

        if ($char === '{' || $char === '[') {
            $stack[] = [
                'type' => $char === '{' ? 'object' : 'array',
                'keys' => [],
                'last' => null,
                'parent' => $stack === [] ? null : $stack[count($stack) - 1]['last'],
            ];
        } elseif ($char === '}' || $char === ']') {
            $frame = array_pop($stack);

            if ($frame !== null && $frame['type'] === 'object') {
                if ($stack === []) {
                    $top = $frame['keys'];
                } elseif (count($stack) === 1) {
                    $entries[] = [$frame['parent'], $frame['keys']];
                }
            }
        }

        $i++;
    }

    return ['top' => $top, 'entries' => $entries];
}

/** @return array<int, string> every repeated value, once each */
function seedCopyAssetRepeats(array $keys): array
{
    // array_count_values turns "0" into the integer 0, so cast the keys back.
    return array_map('strval', array_keys(array_filter(array_count_values($keys), fn (int $count): bool => $count > 1)));
}

/** @return array<int, string> "0" to "21" */
function seedCopyAssetNumbers(): array
{
    return array_map('strval', array_keys(CardSeeder::MAJOR_ARCANA));
}

// The walk itself, on inline samples, so a walk that misses a repeat is caught
// without touching the committed file.
it('finds a repeated key in a minified sample, at the top and inside an entry', function () {
    $keys = seedCopyAssetKeys('{"0":{"short":"a","long":"b","short":"c"},"1":{"short":"d","long":"e"},"0":{"short":"f","long":"g"}}');

    expect(seedCopyAssetRepeats($keys['top']))->toBe(['0'])
        ->and(seedCopyAssetRepeats($keys['entries'][0][1]))->toBe(['short'])
        ->and($keys['entries'][0][0])->toBe('0')
        ->and($keys['entries'][2][0])->toBe('0');
});

it('does not take a quoted colon inside a value for a key', function () {
    $keys = seedCopyAssetKeys('{"0": {"short": "say \"long\": twice", "long": "a \\\\"}}');

    expect($keys['top'])->toBe(['0'])
        ->and($keys['entries'])->toBe([['0', ['short', 'long']]]);
});

it('is a JSON object', function () {
    expect(json_decode(seedCopyAssetText()))->toBeInstanceOf(stdClass::class);
});

it('has exactly the keys 0 to 21, each once, whatever the layout', function () {
    $top = seedCopyAssetKeys(seedCopyAssetText())['top'] ?? [];
    $sorted = $top;
    sort($sorted, SORT_NUMERIC);

    expect(seedCopyAssetRepeats($top))->toBe([], 'repeated: '.implode(', ', seedCopyAssetRepeats($top)))
        ->and($sorted)->toBe(seedCopyAssetNumbers());
});

it('holds exactly short and long in each entry, each once', function () {
    $entries = seedCopyAssetKeys(seedCopyAssetText())['entries'];

    expect($entries)->toHaveCount(22);

    foreach ($entries as [$number, $keys]) {
        $sorted = $keys;
        sort($sorted);

        expect($sorted)->toBe(['long', 'short'], 'entry "'.$number.'" holds: '.implode(', ', $keys));
    }
});

it('holds plain single-line text of the agreed length in every field', function () {
    $entries = (array) json_decode(seedCopyAssetText());

    expect($entries)->toHaveCount(22);

    foreach ($entries as $number => $entry) {
        foreach (['short' => [40, 200], 'long' => [400, 1500]] as $field => [$min, $max]) {
            $text = $entry->{$field} ?? null;
            $where = 'entry "'.$number.'" '.$field;

            expect($text)->toBeString($where.' is not a string');

            $length = mb_strlen($text);

            expect(trim($text))->toBe($text, $where.' has leading or trailing whitespace')
                ->and(preg_match('/[\r\n]/', $text))->toBe(0, $where.' has a line break')
                ->and(strip_tags($text))->toBe($text, $where.' has a tag')
                ->and(str_contains($text, "\u{2014}"))->toBeFalse($where.' has an em dash')
                ->and(str_contains($text, "\u{2013}"))->toBeFalse($where.' has an en dash')
                ->and($length >= $min && $length <= $max)->toBeTrue($where.' is '.$length.' characters, not '.$min.' to '.$max);
        }
    }
});

it('writes all 22 cards from the committed file on the deck the seeder places', function () {
    Queue::fake();
    $this->seed(CardSeeder::class);

    $exit = Artisan::call('cards:seed-copy');
    $output = Artisan::output();

    expect($exit)->toBe(0, $output)
        ->and(preg_match_all('/^\|\s*\d+\s*\|.*\|\s*written\s*\|/m', $output))->toBe(22, $output);
});
