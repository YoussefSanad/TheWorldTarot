<?php

use App\Models\Card;
use Database\Seeders\CardSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
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
    $this->disk = Storage::fake(config('filesystems.media'), ['visibility' => 'private']);
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
