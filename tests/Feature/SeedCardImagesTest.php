<?php

use App\Models\Card;
use Database\Seeders\CardSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * Assumptions the implementer must match (step 2 of the card pictures plan):
 *  - the command is `cards:seed-images --source=<dir>`
 *  - it prints a table with the columns `#`, `Card`, `File`, `Result`, one row
 *    per card number, the number in the first cell and the result last
 *  - results used here: `uploaded`, `skipped`, `conflict`, and a result that
 *    starts `failed` for a rejected or thrown write
 *  - any failed or conflicting card makes the exit code non-zero
 *  - this file reads CardSeeder::MAJOR_ARCANA, which the implementer makes
 *    ~~public in this step. It is private today, so every test here errors until
 *    then.~~
 *    27 Oct 2026: the constant is public as of step 2 and the command exists.
 */

beforeEach(function () {
    Queue::fake();

    // The fake disk defaults to private writes, so a file only reads as public
    // when the command passes the explicit 'public' visibility itself. Without
    // this, the fake's own default made dropping that option invisible.
    // Step 5: the url makes Storage::url() absolute, which Http can request.
    // Without it the fake returns a relative /storage/... path.
    $this->disk = Storage::fake(config('filesystems.media'), ['visibility' => 'private', 'url' => 'https://media.test']);
    $this->source = sys_get_temp_dir().'/seed-images-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->source);
});

afterEach(function () {
    File::deleteDirectory($this->source);
});

/**
 * One fake file per number, each with bytes of its own so every stored name
 * differs. Nothing here is a real picture: the command moves bytes and never
 * decodes them.
 */
function seedImagesWriteFiles(string $source, ?array $numbers = null): void
{
    foreach ($numbers ?? array_keys(CardSeeder::MAJOR_ARCANA) as $number) {
        file_put_contents(seedImagesFile($source, $number), 'fake webp bytes for card '.$number);
    }
}

function seedImagesName(int $number): string
{
    return sprintf('%02d-card-%02d.webp', $number, $number);
}

function seedImagesFile(string $source, int $number): string
{
    return $source.'/'.seedImagesName($number);
}

/** The path the plan promises: number, slug, first 8 hex of the sha1 of the bytes. */
function seedImagesExpectedPath(string $source, int $number): string
{
    return 'cards/'.sprintf('%02d', $number).'-card-'.sprintf('%02d', $number).'-'
        .substr(sha1_file(seedImagesFile($source, $number)), 0, 8).'.webp';
}

/** All 22 live cards, none with a picture, keyed by number. */
function seedImagesMakeCards(): array
{
    $cards = [];

    foreach (CardSeeder::MAJOR_ARCANA as $number => $name) {
        $cards[$number] = Card::factory()->withoutImage()->create([
            'number' => $number,
            'name' => ['en' => $name],
        ]);
    }

    return $cards;
}

/**
 * Wraps the fake disk so a test can act between the command reading a card and
 * writing its row. Everything else, and the real write itself, goes to the
 * fake, so "the object is on the disk" afterwards means something.
 *
 * @param  Closure(string $name): void  $beforeWrite
 */
function seedImagesDiskDouble(FilesystemAdapter $fake, Closure $beforeWrite): FilesystemAdapter
{
    $double = Mockery::mock($fake);
    $double->shouldReceive('putFileAs')->andReturnUsing(
        function (...$arguments) use ($fake, $beforeWrite) {
            $beforeWrite((string) $arguments[2]);

            return $fake->putFileAs(...$arguments);
        },
    );

    return $double;
}

// An empty string when the row is missing, so a command that prints nothing
// fails the assertion on the row rather than throwing on a null.
function seedImagesRowFor(string $output, int $number): string
{
    preg_match('/^\|\s*'.$number.'\s*\|.*$/m', $output, $match);

    return $match[0] ?? '';
}

it('uploads every picture under cards/, public, and points each card at it', function () {
    $cards = seedImagesMakeCards();
    seedImagesWriteFiles($this->source);

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Result');

    foreach (CardSeeder::MAJOR_ARCANA as $number => $name) {
        $expected = seedImagesExpectedPath($this->source, $number);

        // Content hash in the name: a new picture must get a new URL.
        $this->disk->assertExists($expected);
        expect($this->disk->getVisibility($expected))->toBe('public')
            ->and($cards[$number]->fresh()->image_path)->toBe($expected)
            ->and(seedImagesRowFor($output, $number))->toMatch('/uploaded\s*\|\s*$/');
    }

    expect($this->disk->allFiles())->toHaveCount(22);

    // The Card saving hook reacts to video_master_path only. A picture write
    // must dispatch nothing.
    Queue::assertNothingPushed();
});

it('puts the hash of the bytes in the stored name', function () {
    seedImagesMakeCards();
    seedImagesWriteFiles($this->source, [0, 1]);

    Artisan::call('cards:seed-images', ['--source' => $this->source]);

    $path = Card::where('number', 0)->first()->image_path;

    expect($path)->toBe('cards/00-card-00-'.substr(sha1('fake webp bytes for card 0'), 0, 8).'.webp')
        ->and(Card::where('number', 1)->first()->image_path)->not->toBe($path);
});

it('leaves a card that already has a picture alone', function () {
    $cards = seedImagesMakeCards();
    $cards[3]->forceFill(['image_path' => 'cards/her-own.webp'])->saveQuietly();
    $before = $cards[3]->fresh();
    seedImagesWriteFiles($this->source);

    $this->travel(5)->minutes();
    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source]);
    $output = Artisan::output();

    // Her picture is hers. The seed fills gaps and nothing else.
    $this->disk->assertMissing(seedImagesExpectedPath($this->source, 3));
    expect($exit)->toBe(0)
        ->and(seedImagesRowFor($output, 3))->toMatch('/skipped\s*\|\s*$/')
        ->and($cards[3]->fresh()->image_path)->toBe('cards/her-own.webp')
        ->and($cards[3]->fresh()->updated_at->equalTo($before->updated_at))->toBeTrue()
        ->and($this->disk->allFiles())->toHaveCount(21);
});

it('never touches a soft-deleted card', function () {
    $cards = seedImagesMakeCards();
    $cards[7]->delete();
    seedImagesWriteFiles($this->source);

    Artisan::call('cards:seed-images', ['--source' => $this->source]);

    // Real behaviour first, or a command that does nothing would pass: every
    // other card got its picture.
    foreach (CardSeeder::MAJOR_ARCANA as $number => $name) {
        if ($number === 7) {
            continue;
        }

        $expected = seedImagesExpectedPath($this->source, $number);
        $this->disk->assertExists($expected);
        expect($cards[$number]->fresh()->image_path)->toBe($expected);
    }

    // And the trashed one got nothing: no row change, no object. Looking it up
    // with the trash included would make the command write it.
    $trashed = Card::withTrashed()->find($cards[7]->getKey());

    expect($trashed->trashed())->toBeTrue()
        ->and($trashed->image_path)->toBeNull()
        ->and($this->disk->allFiles())->toHaveCount(21);
    $this->disk->assertMissing(seedImagesExpectedPath($this->source, 7));
});

it('writes only the live card when a trashed one shares its number', function () {
    $cards = seedImagesMakeCards();
    $cards[7]->delete();
    // Numbers are unique only among live cards, so a replacement is possible.
    $live = Card::factory()->withoutImage()->create(['number' => 7]);
    seedImagesWriteFiles($this->source);

    Artisan::call('cards:seed-images', ['--source' => $this->source]);

    expect(Card::withTrashed()->find($cards[7]->getKey())->image_path)->toBeNull()
        ->and($live->fresh()->image_path)->toBe(seedImagesExpectedPath($this->source, 7));
});

it('leaves the card without a picture when the disk reports the write failed', function () {
    $cards = seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    $double = Mockery::mock($this->disk);
    $double->shouldReceive('putFileAs')->andReturn(false);
    Storage::set(config('filesystems.media'), $double);

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source]);
    $output = Artisan::output();

    // Writing the row first would publish a picture that is not there.
    expect($exit)->not->toBe(0)
        ->and(seedImagesRowFor($output, 0))->toContain('failed');

    foreach ($cards as $card) {
        expect($card->fresh()->image_path)->toBeNull();
    }
});

it('leaves the card without a picture when the disk throws', function () {
    $cards = seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    $double = Mockery::mock($this->disk);
    $double->shouldReceive('putFileAs')->andThrow(new RuntimeException('the bucket said no'));
    Storage::set(config('filesystems.media'), $double);

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source]);
    $output = Artisan::output();

    expect($exit)->not->toBe(0)
        ->and(seedImagesRowFor($output, 0))->toContain('failed');

    foreach ($cards as $card) {
        expect($card->fresh()->image_path)->toBeNull();
    }
});

it('refuses a local disk outside local and testing before reading or writing anything', function () {
    $cards = seedImagesMakeCards();
    seedImagesWriteFiles($this->source);

    // Storage::fake swaps the disk but leaves the configured driver as it was,
    // so this still looks like a local disk to the guard. That is the only
    // reason the refusal can be tested at all.
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('cards:seed-images', ['--source' => $this->source])
        ->expectsOutputToContain('wiped on every deploy')
        ->assertFailed();

    expect($this->disk->allFiles())->toBeEmpty();

    foreach ($cards as $card) {
        expect($card->fresh()->image_path)->toBeNull();
    }
});

it('refuses an s3 disk with no bucket before reading or writing anything', function () {
    $cards = seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    $s3 = Storage::fake('s3');
    config()->set('filesystems.media', 's3');
    config()->set('filesystems.disks.s3.driver', 's3');
    config()->set('filesystems.disks.s3.bucket', null);

    $this->artisan('cards:seed-images', ['--source' => $this->source])
        ->expectsOutputToContain('no bucket configured')
        ->assertFailed();

    expect($s3->allFiles())->toBeEmpty()
        ->and($this->disk->allFiles())->toBeEmpty();

    foreach ($cards as $card) {
        expect($card->fresh()->image_path)->toBeNull();
    }
});

it('reports a conflict and keeps her picture when the panel replaced it mid-run', function () {
    $cards = seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    $id = $cards[5]->getKey();

    $double = seedImagesDiskDouble($this->disk, function (string $name) use ($id) {
        if (str_starts_with($name, '05-')) {
            Card::query()->whereKey($id)->update(['image_path' => 'cards/panel-upload.webp']);
        }
    });
    Storage::set(config('filesystems.media'), $double);

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source]);
    $output = Artisan::output();

    // A save() here would write the seed over her upload. Only the
    // conditional update notices.
    expect($exit)->not->toBe(0)
        ->and(seedImagesRowFor($output, 5))->toMatch('/conflict\s*\|\s*$/')
        ->and($cards[5]->fresh()->image_path)->toBe('cards/panel-upload.webp');

    // The upload stays: deleting it would race the same way the row did.
    $this->disk->assertExists(seedImagesExpectedPath($this->source, 5));

    // The others were not in anyone's way.
    expect($cards[6]->fresh()->image_path)->toBe(seedImagesExpectedPath($this->source, 6));
});

it('reports a conflict and does not revive a card deleted mid-run', function () {
    $cards = seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    $id = $cards[5]->getKey();

    $double = seedImagesDiskDouble($this->disk, function (string $name) use ($id) {
        if (str_starts_with($name, '05-')) {
            Card::query()->whereKey($id)->update(['deleted_at' => now()]);
        }
    });
    Storage::set(config('filesystems.media'), $double);

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source]);
    $output = Artisan::output();

    $row = Card::withTrashed()->find($id);

    expect($exit)->not->toBe(0)
        ->and(seedImagesRowFor($output, 5))->toMatch('/conflict\s*\|\s*$/')
        ->and($row->trashed())->toBeTrue()
        ->and($row->image_path)->toBeNull();

    $this->disk->assertExists(seedImagesExpectedPath($this->source, 5));
});

// Step 2a: a set the command cannot fully apply. Each case below starts from a
// full set of 22 and breaks exactly one thing, so the exit code and the
// "everything else was uploaded" assertions mean something.

it('reports a file with no card as missing card, uploads nothing for it, and exits 0', function () {
    $cards = seedImagesMakeCards();
    $cards[9]->forceDelete();
    seedImagesWriteFiles($this->source);

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and(seedImagesRowFor($output, 9))->toContain('missing card')
        ->and(seedImagesRowFor($output, 9))->toContain(seedImagesName(9))
        ->and(Card::withTrashed()->where('number', 9)->exists())->toBeFalse();

    $this->disk->assertMissing(seedImagesExpectedPath($this->source, 9));

    // The rest of the set went through: 21 uploads, not a silent no-op.
    expect($this->disk->allFiles())->toHaveCount(21)
        ->and(seedImagesRowFor($output, 10))->toMatch('/uploaded\s*\|\s*$/');
});

it('reports a file whose only card is in the trash as missing card and exits 0', function () {
    $cards = seedImagesMakeCards();
    $cards[7]->delete();
    seedImagesWriteFiles($this->source);

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and(seedImagesRowFor($output, 7))->toContain('missing card')
        ->and(Card::withTrashed()->find($cards[7]->getKey())->image_path)->toBeNull()
        ->and($this->disk->allFiles())->toHaveCount(21)
        ->and(seedImagesRowFor($output, 8))->toMatch('/uploaded\s*\|\s*$/');
});

it('reports a major arcana number with no file as missing file and exits non-zero', function () {
    $cards = seedImagesMakeCards();
    $numbers = array_values(array_diff(array_keys(CardSeeder::MAJOR_ARCANA), [4]));
    seedImagesWriteFiles($this->source, $numbers);

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source]);
    $output = Artisan::output();

    expect($exit)->not->toBe(0)
        ->and(seedImagesRowFor($output, 4))->toContain('missing file')
        ->and($cards[4]->fresh()->image_path)->toBeNull()
        // The card with the gap does not stop the other 21.
        ->and($cards[5]->fresh()->image_path)->toBe(seedImagesExpectedPath($this->source, 5))
        ->and($this->disk->allFiles())->toHaveCount(21);
});

it('reports two files for one number, uploads neither, leaves the row alone and exits non-zero', function () {
    $cards = seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    file_put_contents($this->source.'/12-card-12-alt.webp', 'a second picture for card 12');

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source]);
    $output = Artisan::output();

    expect($exit)->not->toBe(0)
        ->and(seedImagesRowFor($output, 12))->toContain('more than one file')
        ->and($cards[12]->fresh()->image_path)->toBeNull()
        ->and($cards[11]->fresh()->image_path)->toBe(seedImagesExpectedPath($this->source, 11))
        ->and($this->disk->allFiles())->toHaveCount(21);

    $this->disk->assertMissing(seedImagesExpectedPath($this->source, 12));
});

it('warns once about stray files, ignores them, and still exits 0', function () {
    seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    file_put_contents($this->source.'/README.md', 'naming rule');
    file_put_contents($this->source.'/notes.txt', 'scratch');
    // Matches the pattern but is no major arcana number: a stray, not a missing card.
    file_put_contents($this->source.'/22-extra.webp', 'not a card');
    file_put_contents($this->source.'/.gitkeep', '');

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('ignored')
        ->and(substr_count($output, 'README.md'))->toBe(1)
        ->and(substr_count($output, 'notes.txt'))->toBe(1)
        ->and(substr_count($output, '22-extra.webp'))->toBe(1)
        // .gitkeep lives there on purpose and must not read as a problem.
        ->and($output)->not->toContain('.gitkeep')
        ->and($output)->not->toContain('missing')
        ->and(seedImagesRowFor($output, 22))->toBe('')
        ->and($this->disk->allFiles())->toHaveCount(22);
});

// Step 3: --force replaces a picture, and leaves identical bytes alone. Each
// case seeds once for real, then changes exactly one thing before the rerun.

it('with --force replaces a picture whose bytes changed and keeps the old object', function () {
    $cards = seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    Artisan::call('cards:seed-images', ['--source' => $this->source]);

    $oldPath = $cards[3]->fresh()->image_path;
    $oldUpdatedAt = $cards[3]->fresh()->updated_at;
    file_put_contents(seedImagesFile($this->source, 3), 'a repainted card 3');
    $newPath = seedImagesExpectedPath($this->source, 3);

    $this->travel(5)->minutes();
    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source, '--force' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($newPath)->not->toBe($oldPath)
        ->and(seedImagesRowFor($output, 3))->toMatch('/replaced\s*\|\s*$/')
        ->and($cards[3]->fresh()->image_path)->toBe($newPath)
        ->and($cards[3]->fresh()->updated_at->greaterThan($oldUpdatedAt))->toBeTrue();

    // The old URL keeps answering until she decides otherwise.
    $this->disk->assertExists($oldPath);
    $this->disk->assertExists($newPath);
    expect($this->disk->getVisibility($newPath))->toBe('public');
});

it('with --force leaves a card whose bytes are identical unchanged and writes no row', function () {
    $cards = seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    Artisan::call('cards:seed-images', ['--source' => $this->source]);

    $before = $cards[4]->fresh();
    $expected = seedImagesExpectedPath($this->source, 4);

    // Time moves, so a row write would show in updated_at.
    $this->travel(5)->minutes();
    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source, '--force' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($before->image_path)->toBe($expected)
        ->and(seedImagesRowFor($output, 4))->toMatch('/unchanged\s*\|\s*$/')
        ->and($cards[4]->fresh()->image_path)->toBe($expected)
        ->and($cards[4]->fresh()->updated_at->equalTo($before->updated_at))->toBeTrue()
        ->and(now()->greaterThan($before->updated_at))->toBeTrue();

    $this->disk->assertExists($expected);
    expect($this->disk->allFiles())->toHaveCount(22);
});

it('with --force restores an object that went missing and still says unchanged', function () {
    $cards = seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    Artisan::call('cards:seed-images', ['--source' => $this->source]);

    $expected = seedImagesExpectedPath($this->source, 4);
    $before = $cards[4]->fresh();
    $this->disk->delete($expected);
    $this->disk->assertMissing($expected);

    $this->travel(5)->minutes();
    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source, '--force' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and(seedImagesRowFor($output, 4))->toMatch('/unchanged\s*\|\s*$/')
        ->and($cards[4]->fresh()->image_path)->toBe($expected)
        ->and($cards[4]->fresh()->updated_at->equalTo($before->updated_at))->toBeTrue();

    $this->disk->assertExists($expected);
    expect($this->disk->getVisibility($expected))->toBe('public');
});

it('skips her panel picture without --force and replaces it, keeping her object, with --force', function () {
    $cards = seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    Artisan::call('cards:seed-images', ['--source' => $this->source]);

    // Jennifer replaces card 6's picture in the panel.
    $this->disk->put('cards/panel-upload.webp', 'her upload');
    $cards[6]->forceFill(['image_path' => 'cards/panel-upload.webp'])->saveQuietly();
    $expected = seedImagesExpectedPath($this->source, 6);

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source]);

    expect($exit)->toBe(0)
        ->and(seedImagesRowFor(Artisan::output(), 6))->toMatch('/skipped\s*\|\s*$/')
        ->and($cards[6]->fresh()->image_path)->toBe('cards/panel-upload.webp');

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source, '--force' => true]);

    expect($exit)->toBe(0)
        ->and(seedImagesRowFor(Artisan::output(), 6))->toMatch('/replaced\s*\|\s*$/')
        ->and($cards[6]->fresh()->image_path)->toBe($expected);

    $this->disk->assertExists($expected);
    $this->disk->assertExists('cards/panel-upload.webp');
});

it('with --force reports a conflict and keeps her picture when the panel replaced it mid-run', function () {
    $cards = seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    Artisan::call('cards:seed-images', ['--source' => $this->source]);
    $id = $cards[5]->getKey();

    // New bytes, so the stored name differs from the observed path and the
    // command takes the conditional update with a filled observed value.
    file_put_contents(seedImagesFile($this->source, 5), 'a repainted card 5');
    $double = seedImagesDiskDouble($this->disk, function (string $name) use ($id) {
        if (str_starts_with($name, '05-')) {
            Card::query()->whereKey($id)->update(['image_path' => 'cards/panel-upload.webp']);
        }
    });
    Storage::set(config('filesystems.media'), $double);

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source, '--force' => true]);
    $output = Artisan::output();

    expect($exit)->not->toBe(0)
        ->and(seedImagesRowFor($output, 5))->toMatch('/conflict\s*\|\s*$/')
        ->and($cards[5]->fresh()->image_path)->toBe('cards/panel-upload.webp');

    $this->disk->assertExists(seedImagesExpectedPath($this->source, 5));
    // The others were not in anyone's way.
    expect(seedImagesRowFor($output, 6))->toMatch('/unchanged\s*\|\s*$/');
});

it('with --force reports a conflict and does not revive a card deleted mid-run', function () {
    $cards = seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    Artisan::call('cards:seed-images', ['--source' => $this->source]);
    $id = $cards[5]->getKey();
    $oldPath = $cards[5]->fresh()->image_path;

    file_put_contents(seedImagesFile($this->source, 5), 'a repainted card 5');
    $double = seedImagesDiskDouble($this->disk, function (string $name) use ($id) {
        if (str_starts_with($name, '05-')) {
            Card::query()->whereKey($id)->update(['deleted_at' => now()]);
        }
    });
    Storage::set(config('filesystems.media'), $double);

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source, '--force' => true]);
    $output = Artisan::output();

    $row = Card::withTrashed()->find($id);

    expect($exit)->not->toBe(0)
        ->and(seedImagesRowFor($output, 5))->toMatch('/conflict\s*\|\s*$/')
        ->and($row->trashed())->toBeTrue()
        ->and($row->image_path)->toBe($oldPath);

    $this->disk->assertExists(seedImagesExpectedPath($this->source, 5));
});

it('with --force never touches a soft-deleted card, even one that has a picture', function () {
    $cards = seedImagesMakeCards();
    $cards[7]->forceFill(['image_path' => 'cards/old-seven.webp'])->saveQuietly();
    $cards[7]->delete();
    seedImagesWriteFiles($this->source);

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source, '--force' => true]);

    // Real behaviour first: every live card got its picture.
    expect($exit)->toBe(0)
        ->and($cards[8]->fresh()->image_path)->toBe(seedImagesExpectedPath($this->source, 8))
        ->and(Card::withTrashed()->find($cards[7]->getKey())->image_path)->toBe('cards/old-seven.webp')
        ->and($this->disk->allFiles())->toHaveCount(21);

    $this->disk->assertMissing(seedImagesExpectedPath($this->source, 7));
});

it('with --force changes only the live card when a trashed one shares its number', function () {
    $cards = seedImagesMakeCards();
    $cards[7]->forceFill(['image_path' => 'cards/old-seven.webp'])->saveQuietly();
    $cards[7]->delete();
    $live = Card::factory()->withoutImage()->create(['number' => 7]);
    seedImagesWriteFiles($this->source);

    Artisan::call('cards:seed-images', ['--source' => $this->source, '--force' => true]);

    expect(Card::withTrashed()->find($cards[7]->getKey())->image_path)->toBe('cards/old-seven.webp')
        ->and($live->fresh()->image_path)->toBe(seedImagesExpectedPath($this->source, 7));
});

// Step 3a: --dry-run computes every row and writes nothing. It is still a real
// check: the guard and the set checks run exactly as in a real run.

it('with --dry-run says would upload for every card and writes nothing', function () {
    $cards = seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    $before = Card::query()->pluck('updated_at', 'number')->all();

    $this->travel(5)->minutes();
    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source, '--dry-run' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->not->toContain('uploaded');

    foreach (CardSeeder::MAJOR_ARCANA as $number => $name) {
        expect(seedImagesRowFor($output, $number))->toMatch('/would upload\s*\|\s*$/')
            ->and($cards[$number]->fresh()->image_path)->toBeNull()
            ->and($cards[$number]->fresh()->updated_at->equalTo($before[$number]))->toBeTrue();
    }

    $this->disk->assertDirectoryEmpty('cards');
    expect($this->disk->allFiles())->toBeEmpty();
    Queue::assertNothingPushed();

    // Control: the same set, run for real, does upload all 22. Without this a
    // command that never finds a file would also leave the disk empty.
    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source]);

    expect($exit)->toBe(0)
        ->and(seedImagesRowFor(Artisan::output(), 0))->toMatch('/uploaded\s*\|\s*$/')
        ->and($this->disk->allFiles())->toHaveCount(22)
        ->and($cards[0]->fresh()->image_path)->toBe(seedImagesExpectedPath($this->source, 0));
});

it('with --dry-run says would skip for a card that has a picture and touches nothing', function () {
    $cards = seedImagesMakeCards();
    $cards[3]->forceFill(['image_path' => 'cards/her-own.webp'])->saveQuietly();
    $before = $cards[3]->fresh();
    seedImagesWriteFiles($this->source);

    $this->travel(5)->minutes();
    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source, '--dry-run' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and(seedImagesRowFor($output, 3))->toMatch('/would skip\s*\|\s*$/')
        // The neighbours have no picture, so they are the real "would upload".
        ->and(seedImagesRowFor($output, 4))->toMatch('/would upload\s*\|\s*$/')
        ->and($cards[3]->fresh()->image_path)->toBe('cards/her-own.webp')
        ->and($cards[3]->fresh()->updated_at->equalTo($before->updated_at))->toBeTrue()
        ->and($this->disk->allFiles())->toBeEmpty();
});

it('with --dry-run --force says would replace or unchanged and writes nothing', function () {
    $cards = seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    Artisan::call('cards:seed-images', ['--source' => $this->source]);

    $oldPath = $cards[3]->fresh()->image_path;
    $unchangedPath = $cards[4]->fresh()->image_path;
    file_put_contents(seedImagesFile($this->source, 3), 'a repainted card 3');
    $newPath = seedImagesExpectedPath($this->source, 3);

    // An object that went missing: a real --force would restore it, a dry run must not.
    $this->disk->delete($unchangedPath);
    $files = $this->disk->allFiles();
    sort($files);
    $updatedAt = Card::query()->pluck('updated_at', 'number')->all();

    $this->travel(5)->minutes();
    $exit = Artisan::call('cards:seed-images', [
        '--source' => $this->source,
        '--dry-run' => true,
        '--force' => true,
    ]);
    $output = Artisan::output();

    $after = $this->disk->allFiles();
    sort($after);

    expect($exit)->toBe(0)
        ->and(seedImagesRowFor($output, 3))->toMatch('/would replace\s*\|\s*$/')
        ->and(seedImagesRowFor($output, 4))->toMatch('/unchanged\s*\|\s*$/')
        ->and(seedImagesRowFor($output, 5))->toMatch('/unchanged\s*\|\s*$/')
        ->and($cards[3]->fresh()->image_path)->toBe($oldPath)
        ->and($cards[4]->fresh()->image_path)->toBe($unchangedPath)
        ->and($after)->toBe($files)
        ->and($files)->toHaveCount(21);

    foreach (Card::query()->get() as $card) {
        expect($card->updated_at->equalTo($updatedAt[$card->number]))->toBeTrue();
    }

    $this->disk->assertMissing($newPath);
    // unchanged must not rewrite the object in a dry run.
    $this->disk->assertMissing($unchangedPath);
});

it('with --dry-run still reports a missing file and exits non-zero', function () {
    $cards = seedImagesMakeCards();
    $numbers = array_values(array_diff(array_keys(CardSeeder::MAJOR_ARCANA), [4]));
    seedImagesWriteFiles($this->source, $numbers);

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source, '--dry-run' => true]);
    $output = Artisan::output();

    expect($exit)->not->toBe(0)
        ->and(seedImagesRowFor($output, 4))->toContain('missing file')
        ->and(seedImagesRowFor($output, 5))->toMatch('/would upload\s*\|\s*$/')
        ->and($cards[5]->fresh()->image_path)->toBeNull()
        ->and($this->disk->allFiles())->toBeEmpty();
});

it('with --dry-run still reports two files for one number and exits non-zero', function () {
    seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    file_put_contents($this->source.'/12-card-12-alt.webp', 'a second picture for card 12');

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source, '--dry-run' => true]);
    $output = Artisan::output();

    expect($exit)->not->toBe(0)
        ->and(seedImagesRowFor($output, 12))->toContain('more than one file')
        ->and(seedImagesRowFor($output, 11))->toMatch('/would upload\s*\|\s*$/')
        ->and($this->disk->allFiles())->toBeEmpty();
});

it('with --dry-run reports a file with no card as missing card and exits 0', function () {
    $cards = seedImagesMakeCards();
    $cards[9]->forceDelete();
    seedImagesWriteFiles($this->source);

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source, '--dry-run' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and(seedImagesRowFor($output, 9))->toContain('missing card')
        ->and(seedImagesRowFor($output, 10))->toMatch('/would upload\s*\|\s*$/')
        ->and($this->disk->allFiles())->toBeEmpty();
});

it('refuses a dry run on a local disk outside local and testing before reading anything', function () {
    $cards = seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    app()->detectEnvironment(fn (): string => 'production');

    // The source does not exist: a command that read it before the guard would
    // fail with another message, and one that skipped the guard would print rows.
    $this->artisan('cards:seed-images', [
        '--source' => $this->source.'/does-not-exist',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('wiped on every deploy')
        ->doesntExpectOutputToContain('would upload')
        ->assertFailed();

    // And with a real source, the same refusal and no table.
    $this->artisan('cards:seed-images', ['--source' => $this->source, '--dry-run' => true])
        ->expectsOutputToContain('wiped on every deploy')
        ->doesntExpectOutputToContain('would upload')
        ->assertFailed();

    expect($this->disk->allFiles())->toBeEmpty();

    foreach ($cards as $card) {
        expect($card->fresh()->image_path)->toBeNull();
    }
});

it('with --dry-run never reaches the write, so it never reports a conflict', function () {
    $cards = seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    $id = $cards[5]->getKey();

    // A double that would change the row on write. A dry run must never call it.
    $calls = 0;
    $double = seedImagesDiskDouble($this->disk, function (string $name) use ($id, &$calls) {
        $calls++;
        Card::query()->whereKey($id)->update(['image_path' => 'cards/panel-upload.webp']);
    });
    Storage::set(config('filesystems.media'), $double);

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source, '--dry-run' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($calls)->toBe(0)
        ->and($output)->not->toContain('conflict')
        ->and(seedImagesRowFor($output, 5))->toMatch('/would upload\s*\|\s*$/')
        ->and($cards[5]->fresh()->image_path)->toBeNull()
        ->and($this->disk->allFiles())->toBeEmpty();

    $calls = 0;
    $exit = Artisan::call('cards:seed-images', [
        '--source' => $this->source,
        '--dry-run' => true,
        '--force' => true,
    ]);

    expect($exit)->toBe(0)
        ->and($calls)->toBe(0)
        ->and(Artisan::output())->not->toContain('conflict');
});

// Step 5: --verify sends one HEAD per matched live card that has a picture
// after the run, to the disk's public URL. Http is always faked here, and
// preventStrayRequests makes any request the fake does not cover throw, so the
// suite can never reach a real network.

function seedImagesUrl(string $path): string
{
    return 'https://media.test/'.$path;
}

function seedImagesAssertHead(string $path): void
{
    Http::assertSent(fn (Request $request) => $request->method() === 'HEAD' && $request->url() === seedImagesUrl($path));
}

function seedImagesAssertNoRequestFor(string $path): void
{
    Http::assertNotSent(fn (Request $request) => $request->url() === seedImagesUrl($path));
}

it('with --verify sends one HEAD to the exact public URL of every uploaded picture and exits 0 on 2xx', function () {
    seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    Http::preventStrayRequests();
    Http::fake(['https://media.test/*' => Http::response('', 200)]);

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source, '--verify' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Verify')
        ->and(seedImagesRowFor($output, 5))->toContain('200');

    foreach (CardSeeder::MAJOR_ARCANA as $number => $name) {
        seedImagesAssertHead(seedImagesExpectedPath($this->source, $number));
    }

    Http::assertSentCount(22);
});

it('with --verify reports a 403 in the table, names the bucket as the likely cause and exits non-zero', function () {
    seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    $blocked = seedImagesExpectedPath($this->source, 5);
    Http::preventStrayRequests();
    Http::fake([
        seedImagesUrl($blocked) => Http::response('', 403),
        'https://media.test/*' => Http::response('', 200),
    ]);

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source, '--verify' => true]);
    $output = Artisan::output();

    expect($exit)->not->toBe(0)
        ->and(seedImagesRowFor($output, 5))->toContain('403')
        ->and(seedImagesRowFor($output, 4))->toContain('200')
        ->and(seedImagesRowFor($output, 4))->not->toContain('403')
        // media:check's wording: "A 403 means the bucket is not publicly readable".
        ->and($output)->toContain('not publicly readable');
});

it('with --verify fails on a connection error and still checks the other cards', function () {
    seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    $unreachable = seedImagesUrl(seedImagesExpectedPath($this->source, 5));
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($unreachable) {
        if ($request->url() === $unreachable) {
            throw new ConnectionException('could not resolve host');
        }

        return Http::response('', 200);
    });

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source, '--verify' => true]);
    $output = Artisan::output();

    // A thrown exception that escaped would also be non-zero, so the next
    // card's row proves the command caught it and carried on.
    expect($exit)->not->toBe(0)
        ->and(seedImagesRowFor($output, 5))->not->toContain('200')
        ->and(seedImagesRowFor($output, 6))->toContain('200');
});

it('with --verify does not request a card with no picture', function () {
    $cards = seedImagesMakeCards();
    $cards[9]->forceDelete();
    $cards[7]->delete();
    $numbers = array_values(array_diff(array_keys(CardSeeder::MAJOR_ARCANA), [4]));
    seedImagesWriteFiles($this->source, $numbers);
    // Two files for number 12: neither is uploaded.
    file_put_contents($this->source.'/12-card-12-alt.webp', 'a second picture for card 12');
    Http::preventStrayRequests();
    Http::fake(['https://media.test/*' => Http::response('', 200)]);

    Artisan::call('cards:seed-images', ['--source' => $this->source, '--verify' => true]);

    // Real requests first, or a command that verifies nothing would pass.
    // 22 cards less missing file (4), missing card (9), trashed (7), duplicate (12).
    Http::assertSentCount(18);
    seedImagesAssertHead(seedImagesExpectedPath($this->source, 5));

    // Card 4 has no file, so there are no bytes to hash into an expected path:
    // assert on its path prefix instead.
    foreach ([7, 9, 12] as $number) {
        seedImagesAssertNoRequestFor(seedImagesExpectedPath($this->source, $number));
    }

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '04-card-04'));

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '12-card-12'));
});

it('with --verify checks the picture a skipped card already has, not the seed file', function () {
    $cards = seedImagesMakeCards();
    $cards[3]->forceFill(['image_path' => 'cards/her-own.webp'])->saveQuietly();
    seedImagesWriteFiles($this->source);
    Http::preventStrayRequests();
    Http::fake(['https://media.test/*' => Http::response('', 200)]);

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source, '--verify' => true]);

    expect($exit)->toBe(0);
    seedImagesAssertHead('cards/her-own.webp');
    seedImagesAssertNoRequestFor(seedImagesExpectedPath($this->source, 3));
    Http::assertSentCount(22);
});

it('with --verify on a dry run checks what is already there and writes nothing', function () {
    $cards = seedImagesMakeCards();
    $cards[3]->forceFill(['image_path' => 'cards/her-own.webp'])->saveQuietly();
    seedImagesWriteFiles($this->source);
    Http::preventStrayRequests();
    Http::fake(['https://media.test/*' => Http::response('', 200)]);

    $exit = Artisan::call('cards:seed-images', [
        '--source' => $this->source,
        '--dry-run' => true,
        '--verify' => true,
    ]);
    $output = Artisan::output();

    // Only card 3 has a picture. The 21 "would upload" cards have nothing to ask for.
    expect($exit)->toBe(0)
        ->and(seedImagesRowFor($output, 3))->toContain('200')
        ->and(seedImagesRowFor($output, 4))->toContain('would upload')
        ->and($this->disk->allFiles())->toBeEmpty()
        ->and($cards[4]->fresh()->image_path)->toBeNull();

    Http::assertSentCount(1);
    seedImagesAssertHead('cards/her-own.webp');
});

it('with --verify on a dry run still fails on a 403', function () {
    $cards = seedImagesMakeCards();
    $cards[3]->forceFill(['image_path' => 'cards/her-own.webp'])->saveQuietly();
    seedImagesWriteFiles($this->source);
    Http::preventStrayRequests();
    Http::fake(['https://media.test/*' => Http::response('', 403)]);

    $exit = Artisan::call('cards:seed-images', [
        '--source' => $this->source,
        '--dry-run' => true,
        '--verify' => true,
    ]);

    expect($exit)->not->toBe(0)
        ->and(seedImagesRowFor(Artisan::output(), 3))->toContain('403');
});

it('with --verify checks whatever picture the other writer left on a conflict row', function () {
    $cards = seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    $id = $cards[5]->getKey();
    $double = seedImagesDiskDouble($this->disk, function (string $name) use ($id) {
        if (str_starts_with($name, '05-')) {
            Card::query()->whereKey($id)->update(['image_path' => 'cards/panel-upload.webp']);
        }
    });
    Storage::set(config('filesystems.media'), $double);
    Http::preventStrayRequests();
    Http::fake(['https://media.test/*' => Http::response('', 200)]);

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source, '--verify' => true]);
    $output = Artisan::output();

    // Non-zero because of the conflict, not the verify.
    expect($exit)->not->toBe(0)
        ->and(seedImagesRowFor($output, 5))->toContain('conflict');

    seedImagesAssertHead('cards/panel-upload.webp');
    // The seed object exists on the disk but no row points at it.
    seedImagesAssertNoRequestFor(seedImagesExpectedPath($this->source, 5));
});

it('with --verify and --force checks the current path on a replaced row and on an unchanged row', function () {
    $cards = seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    Artisan::call('cards:seed-images', ['--source' => $this->source]);
    $oldPath = $cards[3]->fresh()->image_path;
    $unchangedPath = $cards[4]->fresh()->image_path;
    file_put_contents(seedImagesFile($this->source, 3), 'a repainted card 3');
    $newPath = seedImagesExpectedPath($this->source, 3);
    Http::preventStrayRequests();
    Http::fake(['https://media.test/*' => Http::response('', 200)]);

    $exit = Artisan::call('cards:seed-images', [
        '--source' => $this->source,
        '--force' => true,
        '--verify' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and(seedImagesRowFor($output, 3))->toContain('replaced')
        ->and(seedImagesRowFor($output, 4))->toContain('unchanged');

    seedImagesAssertHead($newPath);
    seedImagesAssertHead($unchangedPath);
    // The old object stays on the disk but is no longer the card's picture.
    seedImagesAssertNoRequestFor($oldPath);
    Http::assertSentCount(22);
});

it('without --verify sends no request on a real run, a forced run or a dry run', function () {
    seedImagesMakeCards();
    seedImagesWriteFiles($this->source);
    Http::preventStrayRequests();
    Http::fake();

    $exit = Artisan::call('cards:seed-images', ['--source' => $this->source]);
    expect($exit)->toBe(0)
        ->and(Artisan::output())->not->toContain('Verify');

    Artisan::call('cards:seed-images', ['--source' => $this->source, '--force' => true]);
    Artisan::call('cards:seed-images', ['--source' => $this->source, '--dry-run' => true]);

    // Control: the runs did their work, so "nothing sent" is not an idle command.
    expect($this->disk->allFiles())->toHaveCount(22);
    Http::assertNothingSent();
});
