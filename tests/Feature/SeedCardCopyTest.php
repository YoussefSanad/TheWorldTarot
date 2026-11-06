<?php

use App\Models\Card;
use Database\Seeders\CardSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * Step 10 of the card pictures plan (card copy amendment). Assumptions the
 * implementer must match:
 *  - the command is `cards:seed-copy --file=<path>`, and only that option
 *  - it prints a table with the columns `#`, `Card`, `Short`, `Long`, `Result`
 *    (step 12 adds `Live?` as a sixth; these tests compare the first five), one row per number in
 *    CardSeeder::MAJOR_ARCANA, the number in the first cell and the result last
 *  - field cells: `fill`, `keep`, `same`; results: `written`, `kept`,
 *    `unchanged`, `conflict`, `missing card`, `no entry`,
 *    `failed: unexpected value in <field>`
 *  - the file-level refusals print one error line, worded as the constants in
 *    the helpers below (the plan fixes the content, not the sentence, so the
 *    sentences are this file's choice and the implementer matches them)
 *  - a stray top-level key is named once on one line containing `ignored`
 *  - `no entry`, `conflict`, `failed` and any refusal exit non-zero
 *  - every test runs against rows placed by CardSeeder, whose fields hold the
 *    jsonb `[]`, because that is the shape the `->en` write form cannot handle
 */

beforeEach(function () {
    Queue::fake();

    $this->seed(CardSeeder::class);
    $this->file = sys_get_temp_dir().'/seed-copy-'.bin2hex(random_bytes(6)).'.json';
    seedCopyWriteFile($this->file, seedCopyEntries());
});

afterEach(function () {
    File::delete($this->file);
    Carbon::setTestNow();
    seedCopyWrapperRemove();
});

/** @return array<string, array{short: string, long: string}> keyed by the number as a string */
function seedCopyEntries(): array
{
    $entries = [];

    foreach (array_keys(CardSeeder::MAJOR_ARCANA) as $number) {
        $entries[(string) $number] = [
            'short' => 'Short copy for card '.$number.', written for this test.',
            'long' => 'Long copy for card '.$number.', written for this test.',
        ];
    }

    return $entries;
}

function seedCopyWriteFile(string $path, array $entries): void
{
    // PHP turns the numeric string keys '0'..'21' into integers, and json_encode
    // would then emit a list. The command refuses a list, so force a JSON object.
    file_put_contents($path, json_encode((object) $entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** @return array{0: int, 1: string} */
function seedCopyRun(string $file): array
{
    $exit = Artisan::call('cards:seed-copy', ['--file' => $file]);

    return [$exit, Artisan::output()];
}

/**
 * The cells of one table row, trimmed. When the row is missing it returns six
 * nulls, so a test indexing a cell fails on its assertion instead of erroring
 * with "Undefined array key" (a red test must fail on an assertion).
 */
function seedCopyRow(string $output, int $number): array
{
    if (! preg_match('/^\|\s*'.$number.'\s*\|.*$/m', $output, $match)) {
        return array_fill(0, 6, null);
    }

    // Padded to six, so a table without the Live? column fails on an assertion.
    return array_pad(array_map('trim', explode('|', substr(rtrim($match[0]), 1, -1))), 6, null);
}

/** Places a raw jsonb value on the live card with this number, as DB::table would, bypassing Spatie. */
function seedCopySetRaw(int $number, string $field, string $json): void
{
    DB::update('update cards set '.$field.' = ?::jsonb where number = ? and deleted_at is null', [$json, $number]);
}

function seedCopyRaw(int $number, string $field): string
{
    return DB::selectOne('select '.$field.'::text as value from cards where number = ? and deleted_at is null', [$number])->value;
}

/** The English text as Postgres sees it, null for anything that is not an object holding a string. */
function seedCopyEn(int $number, string $field): ?string
{
    return DB::selectOne('select '.$field."->>'en' as en from cards where number = ? and deleted_at is null", [$number])->en;
}

/** Every column of every row, trashed included, for "nothing changed". */
function seedCopySnapshot(): array
{
    return DB::table('cards')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
}

function seedCopyUpdatedAt(): array
{
    return DB::table('cards')->orderBy('number')->pluck('updated_at', 'number')->all();
}

/** @return array<int, int> the numbers of live cards whose two English fields equal the file's */
function seedCopyWrittenNumbers(array $entries): array
{
    $written = [];

    foreach ($entries as $key => $entry) {
        $number = (int) $key;

        if (seedCopyEn($number, 'short_description') === $entry['short']
            && seedCopyEn($number, 'long_description') === $entry['long']) {
            $written[] = $number;
        }
    }

    return $written;
}

/** Numbers 0 to 21 without the ones given. */
function seedCopyAllExcept(array $except): array
{
    return array_values(array_diff(array_keys(CardSeeder::MAJOR_ARCANA), $except));
}

/**
 * Changes a row through DB::table after the command has read that card, once.
 *
 * @param  Closure(Card): void  $change
 */
function seedCopyListenOnce(int $number, Closure $change): void
{
    $fired = false;

    Event::listen('eloquent.retrieved: '.Card::class, function (Card $card) use (&$fired, $number, $change) {
        if ($fired || $card->number !== $number) {
            return;
        }

        $fired = true;
        $change($card);
    });
}

/**
 * Counts every SELECT against the cards table from now on. A query listener
 * sees model reads and builder reads alike, unlike the eloquent.retrieved
 * event, which a raw DB::table read would slip past. Writes (update ...) do
 * not match, so only reads count.
 */
function seedCopyCardReads(): stdClass
{
    $counter = new stdClass;
    $counter->count = 0;

    DB::listen(function ($query) use ($counter) {
        if (preg_match('/\bfrom\s+"?cards"?/i', $query->sql)) {
            $counter->count++;
        }
    });

    return $counter;
}

// Criterion 18, and the Jennifer trap: CardSeeder rows hold `[]`, and a write
// through `short_description->en` fails on every one of them.
it('writes the English copy of all 22 cards the seeder placed, and says written', function () {
    $entries = seedCopyEntries();
    $before = Card::all()->mapWithKeys(fn (Card $c) => [$c->number => [
        'name' => $c->getRawOriginal('name'),
        'is_published' => $c->is_published,
        'image_path' => $c->image_path,
    ]])->all();

    [$exit, $output] = seedCopyRun($this->file);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Short')
        ->and($output)->toContain('Long')
        ->and($output)->toContain('Live?')
        ->and(seedCopyRow($output, 0))->toHaveCount(6);

    foreach ($entries as $key => $entry) {
        $number = (int) $key;
        $card = Card::where('number', $number)->first();

        expect(array_slice(seedCopyRow($output, $number), 0, 5))->toBe([(string) $number, CardSeeder::MAJOR_ARCANA[$number], 'fill', 'fill', 'written'])
            ->and($card->getTranslation('short_description', 'en', false))->toBe($entry['short'])
            ->and($card->getTranslation('long_description', 'en', false))->toBe($entry['long'])
            ->and($card->getTranslations('short_description'))->toBe(['en' => $entry['short']])
            ->and($card->getRawOriginal('name'))->toBe($before[$number]['name'])
            ->and($card->is_published)->toBe($before[$number]['is_published'])
            ->and($card->image_path)->toBe($before[$number]['image_path']);
    }

    // The builder write skips model events on purpose: Card's hooks act on
    // video_master_path only, so a copy write must dispatch nothing.
    Queue::assertNothingPushed();
});

it('keeps another language, an unpublished flag and a picture while writing English', function () {
    seedCopySetRaw(5, 'short_description', '{"es":"Hola"}');
    seedCopySetRaw(5, 'long_description', '{"es":"Largo","fr":"Long"}');
    Card::where('number', 7)->update(['is_published' => false]);
    Card::where('number', 3)->update(['image_path' => 'cards/her-own.webp']);
    $entries = seedCopyEntries();

    [$exit] = seedCopyRun($this->file);

    expect($exit)->toBe(0);

    $five = Card::where('number', 5)->first();
    expect($five->getTranslations('short_description'))->toEqual(['es' => 'Hola', 'en' => $entries['5']['short']])
        ->and($five->getTranslations('long_description'))->toEqual(['es' => 'Largo', 'fr' => 'Long', 'en' => $entries['5']['long']])
        ->and(Card::where('number', 7)->first()->is_published)->toBeFalse()
        ->and(Card::where('number', 3)->first()->image_path)->toBe('cards/her-own.webp');
});

// Criterion 18, critique: raw jsonb equality, so a nested array never becomes
// an object and a nested {} never becomes [] (the JSON_FORCE_OBJECT and assoc
// decode traps).
it('leaves other languages equal as raw jsonb, array-valued and nested ones included', function () {
    $others = [
        2 => ['short_description' => '{"es":["a","b"]}', 'long_description' => '{"es":["c"],"fr":"Long"}'],
        4 => ['short_description' => '{"es":{"a":{},"b":[]}}', 'long_description' => '{"es":{"k":[1,{}],"m":{}}}'],
    ];

    foreach ($others as $number => $fields) {
        foreach ($fields as $field => $json) {
            seedCopySetRaw($number, $field, $json);
        }
    }

    $entries = seedCopyEntries();
    [$exit] = seedCopyRun($this->file);

    expect($exit)->toBe(0);

    foreach ($others as $number => $fields) {
        foreach ($fields as $field => $json) {
            $equal = DB::selectOne(
                'select ('.$field.' - \'en\'::text) = ?::jsonb as equal from cards where number = ? and deleted_at is null',
                [$json, $number],
            )->equal;

            expect($equal)->toBeTrue("card {$number} {$field} lost its other locales")
                ->and(seedCopyEn($number, $field))->toBe($entries[(string) $number][$field === 'short_description' ? 'short' : 'long']);
        }
    }
});

// Criterion 18a, and the Jennifer trap's second half. Every case is a value
// the command must refuse to rewrite, for each of the two fields.
it('fails a card whose field holds a value it cannot rewrite, and writes nothing for it', function (string $json, string $field) {
    $other = $field === 'short_description' ? 'long_description' : 'short_description';
    $entries = seedCopyEntries();
    seedCopySetRaw(4, $field, $json);
    $before = [
        $field => seedCopyRaw(4, $field),
        $other => seedCopyRaw(4, $other),
        'updated_at' => seedCopyUpdatedAt()[4],
    ];

    $this->travel(5)->minutes();
    [$exit, $output] = seedCopyRun($this->file);

    expect($exit)->not->toBe(0)
        ->and(seedCopyRow($output, 4)[4])->toBe('failed: unexpected value in '.$field)
        ->and(seedCopyRaw(4, $field))->toBe($before[$field])
        ->and(seedCopyRaw(4, $other))->toBe($before[$other])
        ->and(seedCopyUpdatedAt()[4])->toBe($before['updated_at'])
        ->and(seedCopyWrittenNumbers($entries))->toBe(seedCopyAllExcept([4]));

    // The other cards were still processed and said so.
    expect(seedCopyRow($output, 5)[4])->toBe('written');
})->with([
    'non-empty top-level array' => '["x"]',
    'string' => '"hello"',
    'number' => '5',
    'boolean' => 'true',
    'en as an array' => '{"en":["x"],"es":"Hola"}',
    'en as an object' => '{"en":{"a":"b"}}',
    'en as a number' => '{"en":5}',
    'en as a boolean' => '{"en":false}',
])->with(['short_description', 'long_description']);

it('accepts JSON null as a top-level value and a null English key, and fills both', function () {
    seedCopySetRaw(4, 'short_description', 'null');
    seedCopySetRaw(4, 'long_description', '{"en":null,"es":"Largo"}');
    $entries = seedCopyEntries();

    [$exit, $output] = seedCopyRun($this->file);

    expect($exit)->toBe(0)
        ->and(array_slice(seedCopyRow($output, 4), 0, 5))->toBe(['4', CardSeeder::MAJOR_ARCANA[4], 'fill', 'fill', 'written'])
        ->and(json_decode(seedCopyRaw(4, 'short_description'), true))->toBe(['en' => $entries['4']['short']])
        ->and(json_decode(seedCopyRaw(4, 'long_description'), true))->toEqual(['en' => $entries['4']['long'], 'es' => 'Largo']);
});

// Criterion 19.
it('keeps an English field that is not empty and still fills its empty sibling', function () {
    seedCopySetRaw(2, 'short_description', '{"en":"Jennifer wrote this","es":"Hola"}');
    $entries = seedCopyEntries();

    [$exit, $output] = seedCopyRun($this->file);

    expect($exit)->toBe(0)
        ->and(array_slice(seedCopyRow($output, 2), 0, 5))->toBe(['2', CardSeeder::MAJOR_ARCANA[2], 'keep', 'fill', 'written'])
        ->and(json_decode(seedCopyRaw(2, 'short_description'), true))->toEqual(['en' => 'Jennifer wrote this', 'es' => 'Hola'])
        ->and(seedCopyEn(2, 'long_description'))->toBe($entries['2']['long']);
});

it('says same for an English field equal to the file, and fills the empty one', function () {
    $entries = seedCopyEntries();
    seedCopySetRaw(4, 'short_description', json_encode(['en' => $entries['4']['short']]));

    [, $output] = seedCopyRun($this->file);

    expect(array_slice(seedCopyRow($output, 4), 0, 5))->toBe(['4', CardSeeder::MAJOR_ARCANA[4], 'same', 'fill', 'written'])
        ->and(seedCopyEn(4, 'long_description'))->toBe($entries['4']['long']);
});

it('says kept and writes no row for a card with nothing to fill and something kept', function () {
    seedCopySetRaw(3, 'short_description', '{"en":"Her short"}');
    seedCopySetRaw(3, 'long_description', '{"en":"Her long"}');
    $before = seedCopyUpdatedAt();
    $beforeRaw = [seedCopyRaw(3, 'short_description'), seedCopyRaw(3, 'long_description')];

    $this->travel(5)->minutes();
    [$exit, $output] = seedCopyRun($this->file);

    expect($exit)->toBe(0)
        ->and(array_slice(seedCopyRow($output, 3), 0, 5))->toBe(['3', CardSeeder::MAJOR_ARCANA[3], 'keep', 'keep', 'kept'])
        ->and(seedCopyUpdatedAt()[3])->toBe($before[3])
        ->and([seedCopyRaw(3, 'short_description'), seedCopyRaw(3, 'long_description')])->toBe($beforeRaw)
        ->and(seedCopyRow($output, 4)[4])->toBe('written');
});

it('fills an English field that is empty in each of its four forms', function (string $json) {
    $entries = seedCopyEntries();
    seedCopySetRaw(6, 'short_description', $json);

    [$exit, $output] = seedCopyRun($this->file);

    expect($exit)->toBe(0)
        ->and(seedCopyRow($output, 6)[2])->toBe('fill')
        ->and(seedCopyRow($output, 6)[4])->toBe('written')
        ->and(seedCopyEn(6, 'short_description'))->toBe($entries['6']['short']);
})->with([
    'key missing' => '{"es":"Hola"}',
    'null' => '{"en":null}',
    'empty string' => '{"en":""}',
    'whitespace only' => '{"en":"   "}',
]);

// Criterion 20.
it('says unchanged for every card on a second run and writes no row', function () {
    seedCopyRun($this->file);
    $before = seedCopyUpdatedAt();
    $beforeRows = seedCopySnapshot();

    $this->travel(5)->minutes();
    [$exit, $output] = seedCopyRun($this->file);

    expect($exit)->toBe(0)
        ->and(seedCopyUpdatedAt())->toBe($before)
        ->and(seedCopySnapshot())->toBe($beforeRows);

    foreach (array_keys(CardSeeder::MAJOR_ARCANA) as $number) {
        expect(array_slice(seedCopyRow($output, $number), 0, 5))->toBe([(string) $number, CardSeeder::MAJOR_ARCANA[$number], 'same', 'same', 'unchanged']);
    }
});

// Criterion 21.
it('never writes a soft-deleted card', function () {
    Card::where('number', 9)->first()->delete();
    $trashed = (array) DB::table('cards')->where('number', 9)->first();
    $entries = seedCopyEntries();

    [$exit, $output] = seedCopyRun($this->file);

    expect($exit)->toBe(0)
        ->and((array) DB::table('cards')->where('number', 9)->first())->toBe($trashed)
        ->and(seedCopyRow($output, 9)[4])->toBe('missing card')
        ->and(seedCopyWrittenNumbers(array_diff_key($entries, ['9' => 1])))->toBe(seedCopyAllExcept([9]));
});

it('writes only the live card when a trashed one shares its number', function () {
    $trashedId = Card::where('number', 11)->first()->getKey();
    Card::where('number', 11)->first()->delete();
    $live = Card::factory()->create([
        'number' => 11,
        'name' => ['en' => 'Live eleven'],
        'short_description' => [],
        'long_description' => [],
    ]);
    $trashed = (array) DB::table('cards')->where('id', $trashedId)->first();
    $entries = seedCopyEntries();

    [$exit, $output] = seedCopyRun($this->file);

    expect($exit)->toBe(0)
        ->and(seedCopyRow($output, 11)[4])->toBe('written')
        ->and((array) DB::table('cards')->where('id', $trashedId)->first())->toBe($trashed)
        ->and(DB::table('cards')->where('id', $live->getKey())->value('deleted_at'))->toBeNull()
        ->and(seedCopyEn(11, 'short_description'))->toBe($entries['11']['short']);
});

// Criterion 22.
it('matches a renamed card by its number and keeps its new name', function () {
    DB::update('update cards set name = ?::jsonb where number = ?', ['{"en":"Renamed in the panel"}', 13]);
    $entries = seedCopyEntries();

    [$exit, $output] = seedCopyRun($this->file);

    expect($exit)->toBe(0)
        ->and(seedCopyRow($output, 13)[4])->toBe('written')
        ->and(seedCopyEn(13, 'short_description'))->toBe($entries['13']['short'])
        ->and(seedCopyEn(13, 'long_description'))->toBe($entries['13']['long'])
        ->and(json_decode(seedCopyRaw(13, 'name'), true))->toBe(['en' => 'Renamed in the panel']);
});

// Criterion 25.
it('says missing card for a number with no live card and still exits 0', function () {
    Card::where('number', 12)->first()->forceDelete();
    $entries = seedCopyEntries();

    [$exit, $output] = seedCopyRun($this->file);

    $row = seedCopyRow($output, 12);

    expect($exit)->toBe(0)
        ->and($row[4])->toBe('missing card')
        ->and($row[2])->toBe('')
        ->and($row[3])->toBe('')
        ->and(seedCopyWrittenNumbers(array_diff_key($entries, ['12' => 1])))->toBe(seedCopyAllExcept([12]));
});

it('says no entry for a number the file lacks, writes the other cards and exits non-zero', function () {
    $entries = seedCopyEntries();
    unset($entries['9']);
    seedCopyWriteFile($this->file, $entries);
    $before = [seedCopyRaw(9, 'short_description'), seedCopyRaw(9, 'long_description')];

    [$exit, $output] = seedCopyRun($this->file);

    $row = seedCopyRow($output, 9);

    expect($exit)->not->toBe(0)
        ->and($row[4])->toBe('no entry')
        ->and($row[2])->toBe('')
        ->and($row[3])->toBe('')
        ->and([seedCopyRaw(9, 'short_description'), seedCopyRaw(9, 'long_description')])->toBe($before)
        ->and(seedCopyWrittenNumbers($entries))->toBe(seedCopyAllExcept([9]));
});

it('warns once about keys that are not 0 to 21, ignores them, and does not fail', function () {
    $entries = seedCopyEntries();
    $withStrays = $entries + [
        '05' => ['short' => 'Stray short for zero five.', 'long' => 'Stray long for zero five.'],
        '22' => ['short' => 'Stray short for twenty-two.', 'long' => 'Stray long for twenty-two.'],
        'abc' => ['short' => 'Stray short for abc.', 'long' => 'Stray long for abc.'],
    ];
    seedCopyWriteFile($this->file, $withStrays);

    [$exit, $output] = seedCopyRun($this->file);

    $lines = array_values(array_filter(explode("\n", $output), fn ($l) => str_contains($l, 'ignored')));

    expect($exit)->toBe(0)
        ->and($lines)->toHaveCount(1)
        ->and($lines[0])->toContain('05')
        ->and($lines[0])->toContain('22')
        ->and($lines[0])->toContain('abc')
        // "05" is a stray, not card 5: card 5 gets the entry under "5".
        ->and(seedCopyEn(5, 'short_description'))->toBe($entries['5']['short'])
        ->and(seedCopyWrittenNumbers($entries))->toBe(seedCopyAllExcept([]))
        ->and(DB::table('cards')->count())->toBe(22);
});

it('prints no ignored warning when every key is 0 to 21', function () {
    [$exit, $output] = seedCopyRun($this->file);

    // The command must really have run before the absence of a warning means anything.
    expect($exit)->toBe(0)
        ->and($output)->toContain('Result');

    foreach (array_keys(CardSeeder::MAJOR_ARCANA) as $number) {
        expect(seedCopyRow($output, $number)[4])->toBe('written');
    }

    expect($output)->not->toContain('ignored');
});

// Criterion 26. Each case is refused before any card is read: one error that
// names the entry and the field, every row unchanged, exit non-zero.
// Proves the read counter is live, so the zero assertions below mean something.
it('reads the cards table when the file is valid, which proves the read counter works', function () {
    $reads = seedCopyCardReads();

    [$exit] = seedCopyRun($this->file);
    $count = $reads->count;

    expect($exit)->toBe(0)
        ->and($count)->toBeGreaterThan(0);
});

it('refuses a file it cannot read', function (Closure $path, string $wording) {
    $before = seedCopySnapshot();
    $reads = seedCopyCardReads();

    [$exit, $output] = seedCopyRun($path($this->file));
    $count = $reads->count;

    expect($exit)->not->toBe(0)
        ->and($output)->toContain($wording)
        ->and($output)->not->toContain('written')
        ->and($count)->toBe(0)
        ->and(seedCopySnapshot())->toBe($before);
})->with([
    'missing' => [fn (string $file) => $file.'.does-not-exist', 'Could not read the copy file'],
    // A directory opens as a path but cannot be read as a file, whoever runs the suite.
    'unreadable' => [fn (string $file) => dirname($file), 'Could not read the copy file'],
]);

// A regular file with no read permission. Root ignores file modes, so skip there.
it('refuses a regular file that has no read permission', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('Root can read a file with mode 000.');
    }

    $before = seedCopySnapshot();
    chmod($this->file, 0000);
    $reads = seedCopyCardReads();

    try {
        [$exit, $output] = seedCopyRun($this->file);
        $count = $reads->count;
    } finally {
        chmod($this->file, 0600);
    }

    expect($exit)->not->toBe(0)
        ->and($output)->toContain('Could not read the copy file')
        ->and($output)->not->toContain('written')
        ->and($count)->toBe(0)
        ->and(seedCopySnapshot())->toBe($before);
});

it('refuses a file that is not valid JSON', function (string $content) {
    file_put_contents($this->file, $content);
    $before = seedCopySnapshot();
    $reads = seedCopyCardReads();

    [$exit, $output] = seedCopyRun($this->file);
    $count = $reads->count;

    expect($exit)->not->toBe(0)
        ->and($output)->toContain('not valid JSON')
        ->and($count)->toBe(0)
        ->and(seedCopySnapshot())->toBe($before);
})->with([
    'plain text' => 'this is not json',
    'truncated' => '{"0": {"short": "a", "long": ',
    'empty file' => '',
]);

it('refuses a file whose top level is not a JSON object', function (string $content) {
    file_put_contents($this->file, $content);
    $before = seedCopySnapshot();
    $reads = seedCopyCardReads();

    [$exit, $output] = seedCopyRun($this->file);
    $count = $reads->count;

    expect($exit)->not->toBe(0)
        ->and($output)->toContain('must be a JSON object')
        ->and($count)->toBe(0)
        ->and(seedCopySnapshot())->toBe($before);
})->with([
    'a list' => '[{"short":"a","long":"b"}]',
    'a string' => '"text"',
    'a number' => '5',
    'null' => 'null',
]);

it('refuses a file with an entry that is not an object, naming the entry', function (mixed $value) {
    $entries = seedCopyEntries();
    $entries['3'] = $value;
    seedCopyWriteFile($this->file, $entries);
    $before = seedCopySnapshot();
    $reads = seedCopyCardReads();

    [$exit, $output] = seedCopyRun($this->file);
    $count = $reads->count;

    expect($exit)->not->toBe(0)
        ->and($output)->toContain('Entry "3"')
        ->and($output)->toContain('must be an object')
        ->and($count)->toBe(0)
        ->and(seedCopySnapshot())->toBe($before);
})->with([
    'a string' => ['just text'],
    'a number' => [7],
    'null' => [null],
    'a list' => [[['short' => 'a', 'long' => 'b']]],
]);

it('refuses a file with a short or long that is missing, not a string or blank, naming entry and field', function (string $field, mixed $value, bool $present) {
    $entries = seedCopyEntries();

    if ($present) {
        $entries['3'][$field] = $value;
    } else {
        unset($entries['3'][$field]);
    }

    seedCopyWriteFile($this->file, $entries);
    $before = seedCopySnapshot();
    $reads = seedCopyCardReads();

    [$exit, $output] = seedCopyRun($this->file);
    $count = $reads->count;

    $errors = array_values(array_filter(explode("\n", $output), fn ($l) => str_contains($l, 'Entry "3"')));

    expect($exit)->not->toBe(0)
        ->and($errors)->toHaveCount(1)
        ->and($errors[0])->toContain($field)
        ->and($errors[0])->toContain('non-empty string')
        ->and($count)->toBe(0)
        ->and(seedCopySnapshot())->toBe($before);
})->with([
    'short missing' => ['short', null, false],
    'short a number' => ['short', 5, true],
    'short null' => ['short', null, true],
    'short a list' => ['short', ['x'], true],
    'short empty' => ['short', '', true],
    'short blank' => ['short', "  \t ", true],
    'long missing' => ['long', null, false],
    'long a number' => ['long', 5, true],
    'long null' => ['long', null, true],
    'long a list' => ['long', ['x'], true],
    'long empty' => ['long', '', true],
    'long blank' => ['long', "  \n ", true],
]);

// The contract is exactly two strings: a third key is refused, not ignored.
it('refuses an entry with a field beyond short and long, naming the entry and the field', function () {
    $entries = seedCopyEntries();
    $entries['3']['notes'] = 'A third key the contract does not allow.';
    seedCopyWriteFile($this->file, $entries);
    $before = seedCopySnapshot();
    $reads = seedCopyCardReads();

    [$exit, $output] = seedCopyRun($this->file);
    $count = $reads->count;

    $errors = array_values(array_filter(explode("\n", $output), fn ($l) => str_contains($l, 'Entry "3"')));

    expect($exit)->not->toBe(0)
        ->and($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('notes')
        ->and($output)->not->toContain('written')
        ->and($count)->toBe(0)
        ->and(seedCopySnapshot())->toBe($before);
});

// Criterion 24 without --force. The other writer acts after the command has
// read the card and before it writes the row. Each case: `conflict`, the row as
// the other writer left it, the other cards written, exit non-zero.
it('reports a conflict and keeps what the other writer left', function (?string $preShort, Closure $change, Closure $assertRow) {
    if ($preShort !== null) {
        seedCopySetRaw(8, 'short_description', $preShort);
    }

    $entries = seedCopyEntries();
    seedCopyListenOnce(8, function (Card $card) use ($change) {
        $change($card->getKey());
    });

    [$exit, $output] = seedCopyRun($this->file);

    expect($exit)->not->toBe(0)
        ->and(seedCopyRow($output, 8)[4])->toBe('conflict');

    $assertRow();

    // Only card 8 lost; every other card was still written.
    expect(seedCopyWrittenNumbers(array_diff_key($entries, ['8' => 1])))->toBe(seedCopyAllExcept([8]))
        ->and(seedCopyRow($output, 7)[4])->toBe('written')
        ->and(seedCopyRow($output, 9)[4])->toBe('written');
})->with([
    'an English edit' => [
        null,
        fn (int $id) => DB::update('update cards set short_description = ?::jsonb where id = ?', ['{"en":"Edited meanwhile"}', $id]),
        function () {
            expect(json_decode(seedCopyRaw(8, 'short_description'), true))->toBe(['en' => 'Edited meanwhile'])
                ->and(json_decode(seedCopyRaw(8, 'long_description'), true))->toBe([]);
        },
    ],
    'a Spanish-only edit' => [
        null,
        fn (int $id) => DB::update('update cards set short_description = ?::jsonb where id = ?', ['{"es":"Editado mientras"}', $id]),
        function () {
            expect(json_decode(seedCopyRaw(8, 'short_description'), true))->toBe(['es' => 'Editado mientras'])
                ->and(json_decode(seedCopyRaw(8, 'long_description'), true))->toBe([]);
        },
    ],
    // Only long is to be written, yet a Spanish edit to short must still
    // conflict: both fields are always in the condition.
    'a Spanish edit to the field it was not going to write' => [
        '{"en":"Her short"}',
        fn (int $id) => DB::update('update cards set short_description = ?::jsonb where id = ?', ['{"en":"Her short","es":"Corto"}', $id]),
        function () {
            expect(json_decode(seedCopyRaw(8, 'short_description'), true))->toEqual(['en' => 'Her short', 'es' => 'Corto'])
                ->and(json_decode(seedCopyRaw(8, 'long_description'), true))->toBe([]);
        },
    ],
    'a soft delete' => [
        null,
        fn (int $id) => DB::table('cards')->where('id', $id)->update(['deleted_at' => now()]),
        function () {
            $row = DB::table('cards')->where('number', 8)->first();

            expect($row->deleted_at)->not->toBeNull()
                ->and(json_decode($row->short_description, true))->toBe([])
                ->and(json_decode($row->long_description, true))->toBe([]);
        },
    ],
]);

// The same conflict rule for long_description: the condition must compare it
// too, or an edit there would be overwritten silently.
it('reports a conflict when long_description was edited, and keeps what the other writer left', function (array $pre, Closure $change, array $expectedShort, array $expectedLong) {
    foreach ($pre as $field => $json) {
        seedCopySetRaw(8, $field, $json);
    }

    $entries = seedCopyEntries();
    seedCopyListenOnce(8, function (Card $card) use ($change) {
        $change($card->getKey());
    });

    [$exit, $output] = seedCopyRun($this->file);

    expect($exit)->not->toBe(0)
        ->and(seedCopyRow($output, 8)[4])->toBe('conflict')
        ->and(json_decode(seedCopyRaw(8, 'short_description'), true))->toEqual($expectedShort)
        ->and(json_decode(seedCopyRaw(8, 'long_description'), true))->toEqual($expectedLong)
        ->and(seedCopyWrittenNumbers(array_diff_key($entries, ['8' => 1])))->toBe(seedCopyAllExcept([8]))
        ->and(seedCopyRow($output, 7)[4])->toBe('written')
        ->and(seedCopyRow($output, 9)[4])->toBe('written');
})->with([
    'an English edit to long' => [
        [],
        fn (int $id) => DB::update('update cards set long_description = ?::jsonb where id = ?', ['{"en":"Edited long meanwhile"}', $id]),
        [],
        ['en' => 'Edited long meanwhile'],
    ],
    'a Spanish-only edit to long' => [
        [],
        fn (int $id) => DB::update('update cards set long_description = ?::jsonb where id = ?', ['{"es":"Largo editado"}', $id]),
        [],
        ['es' => 'Largo editado'],
    ],
    // Only short is to be written (long is kept), yet an edit to long must conflict.
    'a Spanish edit to long while only short is being filled' => [
        ['long_description' => '{"en":"Her long"}'],
        fn (int $id) => DB::update('update cards set long_description = ?::jsonb where id = ?', ['{"en":"Her long","es":"Largo"}', $id]),
        [],
        ['en' => 'Her long', 'es' => 'Largo'],
    ],
]);

/*
 * Step 11 (--force and the backup). Assumptions the implementer must match:
 *  - `cards:seed-copy --file=<path> --force`; the table is unchanged (no `Live?`)
 *  - a non-empty English field that differs from the file says `replace`
 *  - the backup is `seed-copy-backups/cards-copy-<YYYYmmdd-HHMMSS>.json` on the
 *    `local` disk, JSON as in the plan: taken_at (ISO 8601), environment,
 *    file (the --file path as given), cards: [{id, number, short_description,
 *    long_description}] for every card to be written, whole fields as read
 *  - the command prints the backup's absolute path (the disk's own path()) on
 *    one line, and a notice containing `wiped on deploy` and `copy`
 *  - it creates the file exclusively on the disk's real path (path() of the
 *    disk), then reads it back with `Storage::disk('local')->get($relative)`
 *  - a failed unverified file is removed; every backup failure exits non-zero
 *    before any row is written
 */

const SEED_COPY_BACKUP_DIR = 'seed-copy-backups';
const SEED_COPY_BACKUP_NAME = 'seed-copy-backups/cards-copy-20261030-120000.json';

/** @return array{0: int, 1: string} */
function seedCopyRunForce(string $file): array
{
    $exit = Artisan::call('cards:seed-copy', ['--file' => $file, '--force' => true]);

    return [$exit, Artisan::output()];
}

/** Freezes the clock at 12:00:00 on 30 Oct 2026 in the app's timezone, and isolates the local disk. */
function seedCopyFreezeAndFake(): void
{
    Carbon::setTestNow(Carbon::create(2026, 10, 30, 12, 0, 0, config('app.timezone')));
    Storage::fake('local');
}

function seedCopyBackupFiles(): array
{
    return Storage::disk('local')->allFiles();
}

/** @return array<int, array> the cards of the backup, keyed by number */
function seedCopyBackupCards(): array
{
    return collect(seedCopyBackupDecoded()['cards'])->keyBy('number')->all();
}

/** The decoded backup file. Asserts it exists and decodes to an array with cards, so a missing file fails as an assertion. */
function seedCopyBackupDecoded(): array
{
    expect(Storage::disk('local')->exists(SEED_COPY_BACKUP_NAME))->toBeTrue();
    $decoded = json_decode((string) Storage::disk('local')->get(SEED_COPY_BACKUP_NAME), true);
    expect($decoded)->toBeArray()
        ->and($decoded)->toHaveKey('cards')
        ->and($decoded['cards'])->toBeArray();

    return $decoded;
}

/** A proxy of the fake local disk that lets everything through unless a test adds expectations. */
function seedCopyDouble(): array
{
    $real = Storage::disk('local');
    $double = Mockery::mock($real);
    Storage::set('local', $double);

    return [$real, $double];
}

function seedCopyClearCopy(): void
{
    DB::update("update cards set short_description = '[]'::jsonb, long_description = '[]'::jsonb");
}

/** The backup decoded WITHOUT the assoc flag, so `{}` (stdClass) and `[]` (array) stay distinguishable. @return array<int, stdClass> keyed by number */
function seedCopyBackupObjects(): array
{
    expect(Storage::disk('local')->exists(SEED_COPY_BACKUP_NAME))->toBeTrue();
    $decoded = json_decode((string) Storage::disk('local')->get(SEED_COPY_BACKUP_NAME));
    expect($decoded)->toBeInstanceOf(stdClass::class)
        ->and($decoded->cards)->toBeArray();

    $byNumber = [];
    foreach ($decoded->cards as $card) {
        $byNumber[$card->number] = $card;
    }

    return $byNumber;
}

if (! class_exists('SeedCopyStreamWrapper', false)) {
    /**
     * An in-memory `seedcopy://` filesystem that behaves like a real one (mode
     * 'x' fails on an existing file, 'w' truncates it) and lets a test inject
     * two faults without touching production code:
     *  - a rival that creates the backup file at the instant of the first
     *    write-open, i.e. after every check the command made and before its create
     *  - a write budget: stream_write stores only that many bytes in total, then 0
     */
    class SeedCopyStreamWrapper
    {
        /** @var array<string, string> */
        public static array $files = [];

        /** @var array<string, bool> */
        public static array $dirs = [];

        public static ?string $rivalPath = null;

        public static string $rivalBytes = '';

        public static bool $rivalDone = false;

        public static ?int $writeBudget = null;

        public static int $writeOpens = 0;

        public static int $peakBytes = 0;

        public $context;

        private string $path = '';

        private int $position = 0;

        private bool $writable = false;

        public static function reset(): void
        {
            self::$files = [];
            self::$dirs = [];
            self::$rivalPath = null;
            self::$rivalBytes = '';
            self::$rivalDone = false;
            self::$writeBudget = null;
            self::$writeOpens = 0;
            self::$peakBytes = 0;
        }

        public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
        {
            $this->path = $path;
            $this->position = 0;
            $this->writable = strpbrk($mode, 'waxc+') !== false;

            if ($this->writable) {
                self::$writeOpens++;

                if (self::$rivalPath === $path && ! self::$rivalDone) {
                    self::$rivalDone = true;
                    self::$files[$path] = self::$rivalBytes;
                }
            }

            $exists = isset(self::$files[$path]);

            if (str_contains($mode, 'x') && $exists) {
                if ($options & STREAM_REPORT_ERRORS) {
                    trigger_error('File exists', E_USER_WARNING);
                }

                return false;
            }

            if (str_starts_with($mode, 'r') && ! $exists) {
                return false;
            }

            if (str_starts_with($mode, 'w') || str_starts_with($mode, 'x') || ! $exists) {
                self::$files[$path] = '';
            }

            return true;
        }

        public function stream_write(string $data): int
        {
            if (! $this->writable) {
                return 0;
            }

            $room = self::$writeBudget === null
                ? strlen($data)
                : max(0, self::$writeBudget - strlen(self::$files[$this->path]));
            $chunk = substr($data, 0, $room);
            self::$files[$this->path] .= $chunk;
            self::$peakBytes = max(self::$peakBytes, strlen(self::$files[$this->path]));

            return strlen($chunk);
        }

        public function stream_read(int $count): string|false
        {
            $chunk = substr(self::$files[$this->path] ?? '', $this->position, $count);
            $this->position += strlen($chunk);

            return $chunk;
        }

        public function stream_eof(): bool
        {
            return $this->position >= strlen(self::$files[$this->path] ?? '');
        }

        public function stream_flush(): bool
        {
            return true;
        }

        public function stream_close(): void {}

        public function stream_lock(int $operation): bool
        {
            return true;
        }

        public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
        {
            return false;
        }

        public function stream_stat(): array|false
        {
            return ['mode' => 0100644, 'size' => strlen(self::$files[$this->path] ?? '')];
        }

        public function url_stat(string $path, int $flags): array|false
        {
            $path = rtrim($path, '/');

            if (isset(self::$dirs[$path])) {
                return ['mode' => 040755, 'size' => 0];
            }

            if (isset(self::$files[$path])) {
                return ['mode' => 0100644, 'size' => strlen(self::$files[$path])];
            }

            return false;
        }

        public function unlink(string $path): bool
        {
            if (! isset(self::$files[$path])) {
                return false;
            }

            unset(self::$files[$path]);

            return true;
        }

        public function mkdir(string $path, int $mode, int $options): bool
        {
            self::$dirs[rtrim($path, '/')] = true;

            return true;
        }
    }
}

function seedCopyWrapperRemove(): void
{
    if (in_array('seedcopy', stream_get_wrappers(), true)) {
        stream_wrapper_unregister('seedcopy');
    }

    if (class_exists('SeedCopyStreamWrapper', false)) {
        SeedCopyStreamWrapper::reset();
    }
}

function seedCopyWrapperUrl(string $relative): string
{
    return 'seedcopy://disk/'.ltrim($relative, '/');
}

/**
 * Points the local disk at the in-memory wrapper. The command's path(), exists(),
 * put(), get() and delete() all land on it, and so does its own fopen/fwrite/unlink
 * of the path it was given, so any create strategy meets the same filesystem.
 */
function seedCopyWrapperInstall(): void
{
    seedCopyWrapperRemove();
    stream_wrapper_register('seedcopy', SeedCopyStreamWrapper::class);

    [, $double] = seedCopyDouble();
    $double->shouldReceive('path')->andReturnUsing(fn (string $relative = '') => seedCopyWrapperUrl($relative));
    $double->shouldReceive('exists')->andReturnUsing(fn (string $relative) => file_exists(seedCopyWrapperUrl($relative)));
    $double->shouldReceive('get')->andReturnUsing(
        fn (string $relative) => file_exists(seedCopyWrapperUrl($relative)) ? file_get_contents(seedCopyWrapperUrl($relative)) : null
    );
    $double->shouldReceive('put')->andReturnUsing(
        fn (string $relative, $contents) => file_put_contents(seedCopyWrapperUrl($relative), (string) $contents) !== false
    );
    $double->shouldReceive('delete')->andReturnUsing(fn ($relative) => @unlink(seedCopyWrapperUrl($relative)));
}

// Criterion 27: Jennifer's text in both fields is replaced, and the Jennifer trap on `[]` rows.
it('replaces her English text in both fields with --force, and fills the rest', function () {
    seedCopyFreezeAndFake();
    $entries = seedCopyEntries();
    seedCopySetRaw(2, 'short_description', '{"en":"Jennifer short"}');
    seedCopySetRaw(2, 'long_description', '{"en":"Jennifer long"}');

    [$exit, $output] = seedCopyRunForce($this->file);

    expect($exit)->toBe(0)
        ->and(array_slice(seedCopyRow($output, 2), 0, 5))->toBe(['2', CardSeeder::MAJOR_ARCANA[2], 'replace', 'replace', 'written'])
        ->and(array_slice(seedCopyRow($output, 1), 0, 5))->toBe(['1', CardSeeder::MAJOR_ARCANA[1], 'fill', 'fill', 'written'])
        ->and(seedCopyEn(2, 'short_description'))->toBe($entries['2']['short'])
        ->and(seedCopyEn(2, 'long_description'))->toBe($entries['2']['long'])
        ->and(seedCopyWrittenNumbers($entries))->toBe(array_keys(CardSeeder::MAJOR_ARCANA))
        ->and(seedCopyBackupFiles())->toBe([SEED_COPY_BACKUP_NAME]);
});

it('does not replace her text without --force, and writes no backup', function () {
    Storage::fake('local');
    seedCopySetRaw(2, 'short_description', '{"en":"Jennifer short"}');

    [$exit, $output] = seedCopyRun($this->file);

    expect($exit)->toBe(0)
        ->and(seedCopyRow($output, 2)[2])->toBe('keep') // Step 10 behaviour, passes on the stub too
        ->and(seedCopyRow($output, 1)[4])->toBe('written') // fill still happens, Step 10 too
        ->and(seedCopyEn(2, 'short_description'))->toBe('Jennifer short')
        ->and(seedCopyBackupFiles())->toBe([])
        ->and($output)->not->toContain('wiped on deploy')
        ->and($output)->not->toContain('seed-copy-backups');
});

it('says same and unchanged for a card already equal to the file under --force, and leaves the row alone', function () {
    seedCopyFreezeAndFake();
    $entries = seedCopyEntries();
    seedCopySetRaw(4, 'short_description', json_encode(['en' => $entries['4']['short'], 'es' => 'Hola']));
    seedCopySetRaw(4, 'long_description', json_encode(['en' => $entries['4']['long']]));
    seedCopySetRaw(5, 'short_description', json_encode(['en' => $entries['5']['short']]));
    seedCopySetRaw(5, 'long_description', '{"en":"Her long"}');
    $before4 = (array) DB::table('cards')->where('number', 4)->first();

    [$exit, $output] = seedCopyRunForce($this->file);

    expect($exit)->toBe(0)
        ->and(array_slice(seedCopyRow($output, 4), 0, 5))->toBe(['4', CardSeeder::MAJOR_ARCANA[4], 'same', 'same', 'unchanged'])
        ->and((array) DB::table('cards')->where('number', 4)->first())->toBe($before4)
        ->and(array_slice(seedCopyRow($output, 5), 0, 5))->toBe(['5', CardSeeder::MAJOR_ARCANA[5], 'same', 'replace', 'written'])
        ->and(seedCopyEn(5, 'short_description'))->toBe($entries['5']['short'])
        ->and(seedCopyEn(5, 'long_description'))->toBe($entries['5']['long']);

    // Card 4 is not in the backup: it was not written.
    expect(seedCopyBackupCards())->not->toHaveKey(4)
        ->and(seedCopyBackupCards())->toHaveKey(5);
});

it('leaves every row and writes no backup for a forced run whose cards are all same', function () {
    seedCopyFreezeAndFake();
    seedCopyRun($this->file);
    $snapshot = seedCopySnapshot();
    $this->travel(5)->minutes();
    Carbon::setTestNow(Carbon::create(2026, 10, 30, 12, 0, 0, config('app.timezone')));

    [$exit, $output] = seedCopyRunForce($this->file);

    expect($exit)->toBe(0)
        ->and(array_slice(seedCopyRow($output, 7), 0, 5))->toBe(['7', CardSeeder::MAJOR_ARCANA[7], 'same', 'same', 'unchanged'])
        ->and(array_slice(seedCopyRow($output, 0), 0, 5))->toBe(['0', CardSeeder::MAJOR_ARCANA[0], 'same', 'same', 'unchanged'])
        ->and(array_slice(seedCopyRow($output, 21), 0, 5))->toBe(['21', CardSeeder::MAJOR_ARCANA[21], 'same', 'same', 'unchanged'])
        ->and(seedCopySnapshot())->toBe($snapshot)
        ->and(seedCopyBackupFiles())->toBe([])
        ->and($output)->not->toContain('wiped on deploy');
});

it('writes no backup for a forced run where every card is failed', function () {
    seedCopyFreezeAndFake();
    DB::update("update cards set short_description = '\"x\"'::jsonb");
    $snapshot = seedCopySnapshot();

    [$exit, $output] = seedCopyRunForce($this->file);

    expect($exit)->not->toBe(0)
        ->and(seedCopyRow($output, 3)[4])->toBe('failed: unexpected value in short_description')
        ->and(seedCopyRow($output, 0)[4])->toBe('failed: unexpected value in short_description')
        ->and(seedCopyRow($output, 21)[4])->toBe('failed: unexpected value in short_description')
        ->and(seedCopySnapshot())->toBe($snapshot)
        ->and(seedCopyBackupFiles())->toBe([])
        ->and($output)->not->toContain('wiped on deploy');
});

it('leaves other languages equal as raw jsonb under --force, nested values included', function () {
    seedCopyFreezeAndFake();
    seedCopySetRaw(6, 'short_description', '{"en":"Old","es":{"a":["b",1,null]},"fr":"Salut"}');
    seedCopySetRaw(6, 'long_description', '{"es":"Largo","en":"Old long"}');

    seedCopyRunForce($this->file);

    expect(json_decode(seedCopyRaw(6, 'short_description'), true))->toEqual([
        'en' => seedCopyEntries()['6']['short'],
        'es' => ['a' => ['b', 1, null]],
        'fr' => 'Salut',
    ])->and(json_decode(seedCopyRaw(6, 'long_description'), true))->toEqual([
        'es' => 'Largo',
        'en' => seedCopyEntries()['6']['long'],
    ]);
});

// Criterion 28: contents, path, notice.
it('backs up the old values of every card it writes, and prints the path with the wiped-on-deploy notice', function () {
    seedCopyFreezeAndFake();
    seedCopySetRaw(3, 'short_description', '{"en":"Her short","es":"Hola"}');
    seedCopySetRaw(6, 'short_description', '{"en":"Old","es":{"a":["b",1,null]}}');
    seedCopySetRaw(6, 'long_description', '{"es":"Largo"}');
    // Card 4 is failed, so it must not be in the backup.
    seedCopySetRaw(4, 'short_description', '"oops"');
    $old = [];
    foreach (array_keys(CardSeeder::MAJOR_ARCANA) as $n) {
        $row = DB::table('cards')->where('number', $n)->first();
        $old[$n] = ['id' => $row->id, 'short' => json_decode($row->short_description, true), 'long' => json_decode($row->long_description, true)];
    }

    [$exit, $output] = seedCopyRunForce($this->file);

    $path = Storage::disk('local')->path(SEED_COPY_BACKUP_NAME);
    $decoded = seedCopyBackupDecoded();
    $cards = seedCopyBackupCards();

    expect($exit)->not->toBe(0) // card 4 failed
        ->and(seedCopyBackupFiles())->toBe([SEED_COPY_BACKUP_NAME])
        ->and($output)->toContain($path)
        ->and($output)->toContain('wiped on deploy')
        ->and(strtolower($output))->toContain('copy')
        ->and($decoded['taken_at'])->toBe(now()->toIso8601String())
        ->and($decoded['environment'])->toBe(app()->environment())
        ->and($decoded['file'])->toBe($this->file)
        ->and(collect(array_keys($cards))->sort()->values()->all())->toBe(seedCopyAllExcept([4]));

    foreach (seedCopyAllExcept([4]) as $n) {
        expect($cards[$n]['id'])->toBe($old[$n]['id'])
            ->and($cards[$n]['number'])->toBe($n)
            ->and($cards[$n]['short_description'])->toEqual($old[$n]['short'])
            ->and($cards[$n]['long_description'])->toEqual($old[$n]['long']);
    }

    // A card that held only fills (empty `[]` fields) is in it with `[]`, and a Spanish key survived.
    expect($cards[9]['short_description'])->toBe([])
        ->and($cards[3]['short_description'])->toEqual(['en' => 'Her short', 'es' => 'Hola'])
        ->and($cards[6]['long_description'])->toEqual(['es' => 'Largo']);
});

// The important one: the backup is written, and read back, before any row changes.
it('has created and read back the backup before the first row changes', function () {
    seedCopyFreezeAndFake();
    $before = seedCopySnapshot();
    [$real, $double] = seedCopyDouble();
    $seen = (object) ['reads' => 0, 'fileExisted' => null, 'rowsAtReadBack' => null];
    $double->shouldReceive('get')->andReturnUsing(function (...$args) use ($real, $seen) {
        $seen->reads++;
        $seen->fileExisted = file_exists($real->path(SEED_COPY_BACKUP_NAME));
        $seen->rowsAtReadBack = seedCopySnapshot();

        return $real->get(...$args);
    });

    [$exit] = seedCopyRunForce($this->file);

    expect($exit)->toBe(0)
        ->and($seen->reads)->toBeGreaterThan(0)
        ->and($seen->fileExisted)->toBeTrue()
        ->and($seen->rowsAtReadBack)->toBe($before)
        ->and(seedCopySnapshot())->not->toBe($before);
});

// Failure doubles: each ends with every row unchanged and a non-zero exit.
it('writes no row and exits non-zero when the backup directory cannot be created', function () {
    seedCopyFreezeAndFake();
    // A regular file where the directory should go.
    File::put(Storage::disk('local')->path(SEED_COPY_BACKUP_DIR), 'not a directory');
    $before = seedCopySnapshot();

    [$exit, $output] = seedCopyRunForce($this->file);

    expect($exit)->not->toBe(0)
        ->and(seedCopySnapshot())->toBe($before)
        ->and($output)->not->toContain('written');
});

it('writes no row and exits non-zero when the backup directory cannot be written', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root ignores directory permissions');
    }

    seedCopyFreezeAndFake();
    $dir = Storage::disk('local')->path(SEED_COPY_BACKUP_DIR);
    mkdir($dir, 0755, true);
    chmod($dir, 0555);
    $before = seedCopySnapshot();

    try {
        [$exit] = seedCopyRunForce($this->file);
    } finally {
        chmod($dir, 0755);
    }

    expect($exit)->not->toBe(0)
        ->and(seedCopySnapshot())->toBe($before)
        ->and(seedCopyBackupFiles())->toBe([]);
});

it('writes no row and exits non-zero when the disk throws while making the file', function () {
    seedCopyFreezeAndFake();
    $before = seedCopySnapshot();
    [, $double] = seedCopyDouble();
    $double->shouldReceive('path')->andThrow(new RuntimeException('disk gone'));
    $double->shouldReceive('put')->andThrow(new RuntimeException('disk gone'));

    [$exit] = seedCopyRunForce($this->file);

    expect($exit)->not->toBe(0)
        ->and(seedCopySnapshot())->toBe($before);
});

it('writes no row, exits non-zero and removes the file when the read-back is not what was written', function (Closure $readBack) {
    seedCopyFreezeAndFake();
    $before = seedCopySnapshot();
    [$real, $double] = seedCopyDouble();
    $double->shouldReceive('get')->andReturnUsing(fn (...$args) => $readBack($real->get(...$args)));

    [$exit, $output] = seedCopyRunForce($this->file);

    expect($exit)->not->toBe(0)
        ->and(seedCopySnapshot())->toBe($before)
        ->and(file_exists($real->path(SEED_COPY_BACKUP_NAME)))->toBeFalse()
        ->and($output)->not->toContain('written');
})->with([
    'nothing' => [fn ($content) => null],
    'an empty string' => [fn ($content) => ''],
    'invalid JSON' => [fn ($content) => '{"taken_at":'],
    'different content' => [function ($content) {
        $decoded = json_decode((string) $content, true) ?? ['cards' => [[]]];
        $decoded['cards'][0]['short_description'] = ['en' => 'tampered'];

        return json_encode($decoded);
    }],
    'no cards' => [fn ($content) => json_encode(['cards' => []])],
]);

it('refuses a backup name that already exists, leaves that file as it was, and writes no row', function () {
    seedCopyFreezeAndFake();
    Storage::disk('local')->put(SEED_COPY_BACKUP_NAME, 'someone else was here');
    $before = seedCopySnapshot();

    [$exit, $output] = seedCopyRunForce($this->file);

    expect($exit)->not->toBe(0)
        ->and(Storage::disk('local')->get(SEED_COPY_BACKUP_NAME))->toBe('someone else was here')
        ->and(seedCopySnapshot())->toBe($before)
        ->and($output)->not->toContain('written');
});

it('lets the first of two attempts in the same second win and the second write nothing', function () {
    seedCopyFreezeAndFake();

    [$firstExit] = seedCopyRunForce($this->file);
    $bytes = Storage::disk('local')->get(SEED_COPY_BACKUP_NAME);
    expect($firstExit)->toBe(0);

    // Put the copy back to empty so the second attempt has cards to write.
    seedCopyClearCopy();
    $before = seedCopySnapshot();

    [$secondExit, $output] = seedCopyRunForce($this->file);

    expect($secondExit)->not->toBe(0)
        ->and(seedCopySnapshot())->toBe($before)
        ->and(seedCopyWrittenNumbers(seedCopyEntries()))->toBe([])
        ->and(Storage::disk('local')->get(SEED_COPY_BACKUP_NAME))->toBe($bytes)
        ->and(seedCopyBackupFiles())->toBe([SEED_COPY_BACKUP_NAME])
        ->and($output)->not->toContain('written');
});

it('does not replace a backup that another run created after this one had decided', function () {
    seedCopyFreezeAndFake();
    $before = seedCopySnapshot();
    // After the last card has been read (so after every decision), the rival creates the file.
    seedCopyListenOnce(21, fn () => Storage::disk('local')->put(SEED_COPY_BACKUP_NAME, 'rival backup'));

    [$exit] = seedCopyRunForce($this->file);

    expect($exit)->not->toBe(0)
        ->and(Storage::disk('local')->get(SEED_COPY_BACKUP_NAME))->toBe('rival backup')
        ->and(seedCopySnapshot())->toBe($before);
});

// A true race: the rival creates the file at the instant of the command's own
// create call, after any exists() check it might have made. The earlier test
// above creates the rival first, which an exists-then-put implementation also
// survives. Only an exclusive create ('x') refuses here; a put or 'w' would
// truncate the rival's bytes.
it('does not truncate a rival backup created between the check and the create', function () {
    seedCopyFreezeAndFake();
    seedCopyWrapperInstall();
    $url = seedCopyWrapperUrl(SEED_COPY_BACKUP_NAME);
    SeedCopyStreamWrapper::$rivalPath = $url;
    SeedCopyStreamWrapper::$rivalBytes = 'rival backup';
    $before = seedCopySnapshot();

    [$exit, $output] = seedCopyRunForce($this->file);

    expect(SeedCopyStreamWrapper::$rivalDone)->toBeTrue('the command never tried to create the backup')
        ->and($exit)->not->toBe(0)
        ->and(SeedCopyStreamWrapper::$files[$url] ?? null)->toBe('rival backup')
        ->and(seedCopySnapshot())->toBe($before)
        ->and($output)->not->toContain('written');
});

// The command writes with fwrite in a loop. A write that stores some bytes and
// then stops (full disk) must fail the run and leave no half file behind.
it('writes no row, exits non-zero and removes the file when the write stops part way', function () {
    seedCopyFreezeAndFake();
    seedCopyWrapperInstall();
    $url = seedCopyWrapperUrl(SEED_COPY_BACKUP_NAME);
    SeedCopyStreamWrapper::$writeBudget = 40;
    $before = seedCopySnapshot();

    [$exit, $output] = seedCopyRunForce($this->file);

    expect(SeedCopyStreamWrapper::$peakBytes)->toBe(40, 'some bytes must have been stored before the write failed')
        ->and($exit)->not->toBe(0)
        ->and(seedCopySnapshot())->toBe($before)
        ->and(isset(SeedCopyStreamWrapper::$files[$url]))->toBeFalse()
        ->and($output)->not->toContain('written');
});

// The backup keeps the shape of what the card held: `{}` stays an object, `[]`
// an array, a list a list. Decoded without the assoc flag, so a mix-up shows.
it('keeps jsonb objects, empty arrays and lists as they were in the backup', function () {
    seedCopyFreezeAndFake();
    seedCopySetRaw(2, 'short_description', '{"es":["a","b"]}');
    seedCopySetRaw(2, 'long_description', '{"es":["c"],"fr":"Long"}');
    seedCopySetRaw(4, 'short_description', '{"es":{"a":{},"b":[]}}');
    seedCopySetRaw(4, 'long_description', '{"es":{"k":[1,{}],"m":{}}}');
    seedCopySetRaw(6, 'short_description', '{"en":"Old","fr":"Salut"}');

    [$exit] = seedCopyRunForce($this->file);

    $cards = seedCopyBackupObjects();

    expect($exit)->toBe(0)
        // CardSeeder rows held `[]`: it is still an empty array, not `{}`.
        ->and($cards[9]->short_description)->toBe([])
        ->and($cards[9]->long_description)->toBe([])
        // A list stays a list.
        ->and($cards[2]->short_description)->toBeInstanceOf(stdClass::class)
        ->and($cards[2]->short_description->es)->toBe(['a', 'b'])
        ->and($cards[2]->long_description->es)->toBe(['c'])
        ->and($cards[2]->long_description->fr)->toBe('Long')
        // Nested empty objects stay objects and empty arrays stay arrays.
        ->and($cards[4]->short_description->es->a)->toBeInstanceOf(stdClass::class)
        ->and($cards[4]->short_description->es->b)->toBe([])
        ->and($cards[4]->long_description->es->k[0])->toBe(1)
        ->and($cards[4]->long_description->es->k[1])->toBeInstanceOf(stdClass::class)
        ->and($cards[4]->long_description->es->m)->toBeInstanceOf(stdClass::class)
        // An object with an English key stays an object.
        ->and($cards[6]->short_description)->toBeInstanceOf(stdClass::class)
        ->and($cards[6]->short_description->en)->toBe('Old')
        ->and($cards[6]->short_description->fr)->toBe('Salut');
});

// Criterion 24 with --force: the same conflict rule, and the conflicting card stays in the backup.
it('reports a conflict under --force and keeps what the other writer left', function (Closure $change, Closure $assertRow) {
    seedCopyFreezeAndFake();
    // Her text is in card 8, so --force would replace it: the conflict must still win.
    seedCopySetRaw(8, 'short_description', '{"en":"Her short"}');
    seedCopySetRaw(8, 'long_description', '{"en":"Her long"}');
    $entries = seedCopyEntries();
    seedCopyListenOnce(8, fn (Card $card) => $change($card->getKey()));

    [$exit, $output] = seedCopyRunForce($this->file);

    $assertRow();
    expect($exit)->not->toBe(0)
        ->and(seedCopyRow($output, 8)[4])->toBe('conflict')
        ->and(seedCopyRow($output, 7)[4])->toBe('written')
        ->and(seedCopyRow($output, 9)[4])->toBe('written')
        ->and(seedCopyWrittenNumbers(array_diff_key($entries, ['8' => 1])))->toBe(seedCopyAllExcept([8]))
        ->and(seedCopyBackupCards())->toHaveKey(8)
        ->and(seedCopyBackupCards()[8]['short_description'])->toEqual(['en' => 'Her short']);
})->with([
    'an English edit' => [
        fn (int $id) => DB::update('update cards set short_description = ?::jsonb where id = ?', ['{"en":"Edited meanwhile"}', $id]),
        function () {
            expect(json_decode(seedCopyRaw(8, 'short_description'), true))->toBe(['en' => 'Edited meanwhile'])
                ->and(json_decode(seedCopyRaw(8, 'long_description'), true))->toBe(['en' => 'Her long']);
        },
    ],
    'a Spanish-only edit' => [
        fn (int $id) => DB::update('update cards set short_description = ?::jsonb where id = ?', ['{"en":"Her short","es":"Editado"}', $id]),
        function () {
            expect(json_decode(seedCopyRaw(8, 'short_description'), true))->toEqual(['en' => 'Her short', 'es' => 'Editado'])
                ->and(json_decode(seedCopyRaw(8, 'long_description'), true))->toBe(['en' => 'Her long']);
        },
    ],
    'an edit to long' => [
        fn (int $id) => DB::update('update cards set long_description = ?::jsonb where id = ?', ['{"en":"Her long","es":"Largo"}', $id]),
        function () {
            expect(json_decode(seedCopyRaw(8, 'short_description'), true))->toBe(['en' => 'Her short'])
                ->and(json_decode(seedCopyRaw(8, 'long_description'), true))->toEqual(['en' => 'Her long', 'es' => 'Largo']);
        },
    ],
    'a soft delete' => [
        fn (int $id) => DB::table('cards')->where('id', $id)->update(['deleted_at' => now()]),
        function () {
            $row = DB::table('cards')->where('number', 8)->first();

            expect($row->deleted_at)->not->toBeNull()
                ->and(json_decode($row->short_description, true))->toBe(['en' => 'Her short'])
                ->and(json_decode($row->long_description, true))->toBe(['en' => 'Her long']);
        },
    ],
]);

/*
 * Step 12 of the card pictures plan (card copy amendment): `--dry-run`, the
 * `Live?` column, and the deploy leaving copy alone. Assumptions the
 * implementer must match:
 *  - `--dry-run` combines with `--file` and `--force`
 *  - the table gains a sixth column, `Live?`, holding `yes`, or `no: ` followed
 *    by what Card::availability()->unmetBy($card, Locales::default()) names,
 *    joined by `, `, and empty for `missing card`
 *  - a dry run says `would write` where a real run says `written`, and prints
 *    no `Backup:` line
 */

function seedCopyRunDry(string $file, bool $force = false): array
{
    $options = ['--file' => $file, '--dry-run' => true];

    if ($force) {
        $options['--force'] = true;
    }

    $exit = Artisan::call('cards:seed-copy', $options);

    return [$exit, Artisan::output()];
}

function seedCopyGivePicture(int $number): void
{
    DB::update('update cards set image_path = ? where number = ? and deleted_at is null', ['cards/'.$number.'.webp', $number]);
}

/** What the gate names for a card holding both English fields and no picture. */
function seedCopyNoPicture(): string
{
    return 'no: a picture';
}

// Criterion 29: a dry run writes nothing and reads as a real run would.
it('writes no row and no backup on a dry run, and says would write where a real run writes', function (bool $force) {
    seedCopyFreezeAndFake();
    $before = seedCopySnapshot();
    $updatedAt = seedCopyUpdatedAt();

    [$exit, $output] = seedCopyRunDry($this->file, $force);

    expect($exit)->toBe(0)
        ->and(seedCopySnapshot())->toEqual($before)
        ->and(seedCopyUpdatedAt())->toEqual($updatedAt)
        ->and(seedCopyBackupFiles())->toBe([])
        ->and($output)->not->toContain('Backup:')
        ->and($output)->not->toContain('written');

    foreach (array_keys(CardSeeder::MAJOR_ARCANA) as $number) {
        expect(array_slice(seedCopyRow($output, $number), 2, 3))->toBe(['fill', 'fill', 'would write']);
    }
})->with(['without --force' => false, 'with --force' => true]);

it('says replace and would write for her text on a forced dry run, and keeps it and writes no backup', function () {
    seedCopyFreezeAndFake();
    seedCopySetRaw(4, 'short_description', '{"en":"Her short"}');
    seedCopySetRaw(4, 'long_description', '{"en":"Her long"}');
    $before = seedCopySnapshot();

    [$exit, $output] = seedCopyRunDry($this->file, force: true);

    expect($exit)->toBe(0)
        ->and(array_slice(seedCopyRow($output, 4), 2, 3))->toBe(['replace', 'replace', 'would write'])
        ->and(seedCopySnapshot())->toEqual($before)
        ->and(seedCopyBackupFiles())->toBe([])
        ->and($output)->not->toContain('Backup:');
});

it('reads kept, unchanged, missing card, no entry and failed on a dry run as a real run does', function () {
    seedCopyFreezeAndFake();
    $entries = seedCopyEntries();
    // 1: kept (her short, the file's long already there).
    seedCopySetRaw(1, 'short_description', '{"en":"Her short"}');
    seedCopySetRaw(1, 'long_description', json_encode(['en' => $entries['1']['long']]));
    // 2: unchanged.
    seedCopySetRaw(2, 'short_description', json_encode(['en' => $entries['2']['short']]));
    seedCopySetRaw(2, 'long_description', json_encode(['en' => $entries['2']['long']]));
    // 3: failed, a scalar where an object belongs.
    seedCopySetRaw(3, 'short_description', '"plain"');
    // 5: missing card.
    Card::where('number', 5)->first()->delete();
    // 6: no entry.
    unset($entries['6']);
    seedCopyWriteFile($this->file, $entries);
    $before = seedCopySnapshot();

    [$exit, $output] = seedCopyRunDry($this->file);

    expect($exit)->not->toBe(0)
        ->and(seedCopySnapshot())->toEqual($before)
        ->and(array_slice(seedCopyRow($output, 1), 2, 3))->toBe(['keep', 'same', 'kept'])
        ->and(array_slice(seedCopyRow($output, 2), 2, 3))->toBe(['same', 'same', 'unchanged'])
        ->and(seedCopyRow($output, 3)[4])->toBe('failed: unexpected value in short_description')
        ->and(seedCopyRow($output, 5)[4])->toBe('missing card')
        ->and(seedCopyRow($output, 6)[4])->toBe('no entry')
        ->and(seedCopyRow($output, 7)[4])->toBe('would write');
});

it('exits non-zero on a dry run with only a failed card, and with only a missing entry', function (Closure $break) {
    $break($this->file);

    [$exit] = seedCopyRunDry($this->file);

    expect($exit)->not->toBe(0);
})->with([
    'a failed card' => [fn () => seedCopySetRaw(3, 'short_description', '"plain"')],
    'a missing entry' => [function (string $file) {
        $entries = seedCopyEntries();
        unset($entries['6']);
        seedCopyWriteFile($file, $entries);
    }],
]);

it('refuses an invalid file on a dry run, before reading any card', function () {
    file_put_contents($this->file, '{not json');
    $reads = seedCopyCardReads();

    [$exit, $output] = seedCopyRunDry($this->file);

    expect($exit)->not->toBe(0)
        ->and($reads->count)->toBe(0)
        ->and($output)->not->toContain('would write');
});

it('never says conflict on a dry run, even when the card changes after it was read', function () {
    seedCopyListenOnce(8, fn (Card $card) => DB::update('update cards set short_description = ?::jsonb where id = ?', ['{"en":"Edited meanwhile"}', $card->getKey()]));

    [$exit, $output] = seedCopyRunDry($this->file);

    expect($exit)->toBe(0)
        ->and($output)->not->toContain('conflict')
        ->and(seedCopyRow($output, 8)[4])->toBe('would write')
        ->and(seedCopyEn(8, 'short_description'))->toBe('Edited meanwhile');
});

// Criterion 30: the Live? column.
it('says yes for a published card with a picture, on the dry run that would fill it and on the real run', function () {
    seedCopyGivePicture(0);

    [, $dry] = seedCopyRunDry($this->file);
    [$exit, $real] = seedCopyRun($this->file);

    expect(seedCopyRow($dry, 0)[5])->toBe('yes')
        ->and($exit)->toBe(0)
        ->and(seedCopyRow($real, 0)[5])->toBe('yes');
});

it('names the picture for a card without one, and the planned copy is not named on a dry run', function () {
    [, $dry] = seedCopyRunDry($this->file);
    [, $real] = seedCopyRun($this->file);

    expect(seedCopyRow($dry, 1)[5])->toBe(seedCopyNoPicture())
        ->and(seedCopyRow($real, 1)[5])->toBe(seedCopyNoPicture());
});

it('never saves the in-memory copy a dry run computes Live? from', function () {
    seedCopyGivePicture(0);
    $updatedAt = seedCopyUpdatedAt();

    [, $dry] = seedCopyRunDry($this->file);

    expect(seedCopyRow($dry, 0)[5])->toBe('yes')
        ->and(seedCopyUpdatedAt())->toEqual($updatedAt)
        ->and(seedCopyEn(0, 'short_description'))->toBeNull()
        ->and(Card::where('number', 0)->first()->getTranslation('short_description', 'en', false))->toBe('');
});

it('names publishing for an unpublished card', function () {
    seedCopyGivePicture(2);
    DB::table('cards')->where('number', 2)->update(['is_published' => false]);

    [, $dry] = seedCopyRunDry($this->file);
    [, $real] = seedCopyRun($this->file);

    expect(seedCopyRow($dry, 2)[5])->toBe('no: to be published')
        ->and(seedCopyRow($real, 2)[5])->toBe('no: to be published');
});

it('joins several missing things with a comma, in the order the gate names them', function () {
    DB::table('cards')->where('number', 2)->update(['is_published' => false]);

    [, $real] = seedCopyRun($this->file);

    expect(seedCopyRow($real, 2)[5])->toBe('no: a picture, to be published');
});

it('names the missing copy of a card with no entry, from the row as it stands', function () {
    seedCopyGivePicture(1);
    seedCopySetRaw(1, 'short_description', '{"en":"Her short"}');
    $entries = seedCopyEntries();
    unset($entries['1']);
    seedCopyWriteFile($this->file, $entries);

    [, $real] = seedCopyRun($this->file);

    expect(seedCopyRow($real, 1)[4])->toBe('no entry')
        ->and(seedCopyRow($real, 1)[5])->toBe('no: a full description');
});

it('names the missing copy of a failed card, from the row as it stands', function () {
    seedCopyGivePicture(3);
    seedCopySetRaw(3, 'short_description', '"plain"');

    [, $real] = seedCopyRun($this->file);

    expect(seedCopyRow($real, 3)[4])->toBe('failed: unexpected value in short_description')
        // Nothing is written for a failed card, so both fields are still empty.
        ->and(seedCopyRow($real, 3)[5])->toBe('no: a short description, a full description');
});

it('leaves Live? empty for a card removed for good while the command ran', function () {
    seedCopyListenOnce(8, fn (Card $card) => DB::table('cards')->where('id', $card->getKey())->delete());

    [$exit, $real] = seedCopyRun($this->file);

    expect($exit)->not->toBe(0)
        ->and(seedCopyRow($real, 8)[4])->toBe('conflict')
        ->and(seedCopyRow($real, 8)[5])->toBe('');
});

it('leaves Live? empty for a missing card', function () {
    Card::where('number', 5)->first()->delete();

    [, $real] = seedCopyRun($this->file);

    expect(seedCopyRow($real, 5)[4])->toBe('missing card')
        ->and(seedCopyRow($real, 5)[5])->toBe('');
});

it('shows the state the other writer left for a conflict card', function (Closure $change, string $live) {
    seedCopyGivePicture(8);
    seedCopyListenOnce(8, fn (Card $card) => $change($card->getKey()));

    [$exit, $real] = seedCopyRun($this->file);

    expect($exit)->not->toBe(0)
        ->and(seedCopyRow($real, 8)[4])->toBe('conflict')
        ->and(seedCopyRow($real, 8)[5])->toBe($live);
})->with([
    'her English short' => [
        fn (int $id) => DB::update('update cards set short_description = ?::jsonb where id = ?', ['{"en":"Edited meanwhile"}', $id]),
        'no: a full description',
    ],
    'a soft delete' => [
        fn (int $id) => DB::update('update cards set deleted_at = now(), short_description = ?::jsonb where id = ?', ['{"es":"x"}', $id]),
        'no: a short description, a full description, a developer to put it back, because it was removed',
    ],
]);

it('asks the default language the gate asks, not the en key by assumption', function () {
    // Spanish is the default here and card 0 is finished in it, with no English
    // name. Asking `en` would say `no: a name`; the gate's language says yes.
    seedCopyGivePicture(0);
    seedCopySetRaw(0, 'name', '{"es":"El Loco"}');
    seedCopySetRaw(0, 'short_description', '{"es":"Un comienzo."}');
    seedCopySetRaw(0, 'long_description', '{"es":"El significado largo."}');
    config()->set('app.fallback_locale', 'es');

    [, $dry] = seedCopyRunDry($this->file);

    expect(seedCopyRow($dry, 0)[4])->toBe('would write')
        ->and(seedCopyRow($dry, 0)[5])->toBe('yes');
});

it('marks yes exactly the cards GET /api/v1/en/cards lists after the real run', function () {
    foreach ([0, 3, 9, 14, 21] as $number) {
        seedCopyGivePicture($number);
    }
    DB::table('cards')->where('number', 9)->update(['is_published' => false]);

    [$exit, $real] = seedCopyRun($this->file);

    $marked = array_values(array_filter(
        array_keys(CardSeeder::MAJOR_ARCANA),
        fn (int $number): bool => seedCopyRow($real, $number)[5] === 'yes',
    ));
    $listed = collect($this->getJson('/api/v1/en/cards')->assertOk()->json())->pluck('number')->sort()->values()->all();

    expect($exit)->toBe(0)
        ->and($marked)->toBe([0, 3, 14, 21])
        ->and($listed)->toBe($marked);
});

// Criterion 23, the test half: the deploy seeder leaves copy alone.
it('leaves every English description empty when DatabaseSeeder runs on a fresh deck', function () {
    // beforeEach placed the deck; remove it so the seeder creates every card.
    DB::table('cards')->delete();

    $this->seed(DatabaseSeeder::class);

    foreach (array_keys(CardSeeder::MAJOR_ARCANA) as $number) {
        $card = Card::where('number', $number)->first();

        expect($card->getTranslation('short_description', 'en', false))->toBe('')
            ->and($card->getTranslation('long_description', 'en', false))->toBe('');
    }
});

it('leaves seeded copy and her later edit byte for byte, and re-creates a removed card empty', function () {
    [$exit] = seedCopyRun($this->file);
    expect($exit)->toBe(0)
        ->and(seedCopyWrittenNumbers(seedCopyEntries()))->toBe(array_keys(CardSeeder::MAJOR_ARCANA));
    DB::update('update cards set short_description = ?::jsonb where number = 4', ['{"en":"Her edit","es":["a","b"]}']);
    Card::where('number', 11)->first()->forceDelete();
    $raw = fn (): array => DB::table('cards')->where('number', '!=', 11)->orderBy('number')
        ->get(['number', DB::raw('short_description::text as s'), DB::raw('long_description::text as l')])
        ->map(fn ($row) => (array) $row)->all();
    $before = $raw();

    $this->seed(DatabaseSeeder::class);

    $recreated = Card::where('number', 11)->first();

    expect($raw())->toBe($before)
        ->and($recreated)->not->toBeNull()
        ->and($recreated->getTranslation('short_description', 'en', false))->toBe('')
        ->and($recreated->getTranslation('long_description', 'en', false))->toBe('');
});
