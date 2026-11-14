<?php

use App\Models\Card;
use Database\Seeders\CardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * Step 7 of the card pictures plan: asset validation ONLY.
 *
 * This file checks that the committed files in database/seed-images/cards/ are
 * a complete, well-formed set (count, names, format, size, shape, weight). It
 * does NOT stand for the plaque legibility check, which the user did by eye.
 * A picture can pass every assertion here and still crop or hide its plaque.
 *
 * Cards 00 and 01 were placeholders until 14 November 2026 (#127), when the
 * client's final art replaced them under the same names. They were always held
 * to the same rules, so nothing here names them apart.
 *
 * "Under 300KB" is read as 300 * 1000 bytes (decimal), not 300 * 1024.
 */

const SEED_ASSET_PATTERN = '/^(\d{2})-[a-z0-9]+(?:-[a-z0-9]+)*\.webp$/';

/** Paths of every file in the real folder that matches NN-slug.webp, sorted. */
function seedAssetFiles(): array
{
    $dir = database_path('seed-images/cards');
    $files = [];

    foreach (scandir($dir) ?: [] as $name) {
        if (preg_match(SEED_ASSET_PATTERN, $name) === 1) {
            $files[] = $dir.'/'.$name;
        }
    }

    return $files;
}

it('holds exactly 22 files named NN-slug.webp', function () {
    $names = array_map('basename', seedAssetFiles());

    expect($names)->toHaveCount(22, 'found '.count($names).': '.implode(', ', $names));
});

it('has each number from 0 to 21 exactly once', function () {
    $seen = [];

    foreach (seedAssetFiles() as $path) {
        preg_match(SEED_ASSET_PATTERN, basename($path), $m);
        $seen[(int) $m[1]][] = basename($path);
    }

    foreach (range(0, 21) as $number) {
        expect($seen[$number] ?? [])->toHaveCount(1, sprintf('number %02d appears in: %s', $number, implode(', ', $seen[$number] ?? ['no file'])));
    }

    expect(array_keys($seen))->each->toBeLessThanOrEqual(21);
});

it('has real webp files of the right size and shape, each under 300KB', function () {
    $files = seedAssetFiles();

    expect($files)->not->toBeEmpty('no matching files in database/seed-images/cards');

    foreach ($files as $path) {
        $name = basename($path);
        $info = @getimagesize($path);

        expect($info)->not->toBeFalse("{$name} is not a readable image");
        expect($info['mime'])->toBe('image/webp', "{$name} is not webp");
        expect($info[0])->toBeGreaterThanOrEqual(640, "{$name} is {$info[0]} wide, needs at least 640");
        expect($info[1])->toBeGreaterThanOrEqual(1060, "{$name} is {$info[1]} high, needs at least 1060");

        $drift = abs(($info[0] / $info[1]) / (640 / 1060) - 1);
        expect($drift)->toBeLessThanOrEqual(0.02, "{$name} is {$info[0]}x{$info[1]}, ratio is off 640:1060 by ".round($drift * 100, 1).'%');

        $bytes = filesize($path);
        expect($bytes)->toBeLessThan(300 * 1000, "{$name} is {$bytes} bytes, limit is 300000");
    }
});

it('seeds all 22 cards from the real folder onto a faked media disk', function () {
    Queue::fake();
    $disk = Storage::fake(config('filesystems.media'), ['visibility' => 'private', 'url' => 'https://media.test']);

    foreach (CardSeeder::MAJOR_ARCANA as $number => $name) {
        Card::factory()->withoutImage()->create([
            'number' => $number,
            'name' => ['en' => $name],
        ]);
    }

    $exit = Artisan::call('cards:seed-images');
    $output = Artisan::output();

    expect($exit)->toBe(0, $output);
    expect(substr_count($output, 'uploaded'))->toBe(22, $output);

    foreach (Card::query()->get() as $card) {
        expect($card->image_path)->not->toBeEmpty(sprintf('card %02d has no image_path', $card->number));
        $disk->assertExists($card->image_path);
    }
});
