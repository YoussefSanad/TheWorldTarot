<?php

namespace App\Console\Commands;

use App\Models\Card;
use App\Support\MediaDiskGuard;
use Database\Seeders\CardSeeder;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SeedCardImages extends Command
{
    protected $signature = 'cards:seed-images {--source= : Directory to read from, default database_path(\'seed-images/cards\')} {--force : Replace a picture a card already has} {--dry-run : Say what would happen and write nothing} {--verify : HEAD every card picture\'s public URL afterwards}';

    protected $description = 'Seed card pictures from a directory';

    public function handle(): int
    {
        // Refuse before reading files or cards, and before building the disk.
        // The guard checks the driver, not the name: Cloud can replace the
        // disk named public with a bucket.
        $refusal = app(MediaDiskGuard::class)->refusalFor(config('filesystems.media'));

        if ($refusal !== null) {
            $this->components->error($refusal);

            return self::FAILURE;
        }

        try {
            $files = File::files($this->option('source') ?: database_path('seed-images/cards'), true);
        } catch (Throwable $e) {
            $this->components->error('Could not read the source directory: '.$e->getMessage());

            return self::FAILURE;
        }

        $byNumber = [];
        $strays = [];

        foreach ($files as $file) {
            $name = $file->getFilename();

            if ($name === '.gitkeep') {
                continue;
            }

            if (preg_match('/^(\d{2})-[a-z0-9-]+\.webp$/', $name, $match)
                && array_key_exists((int) $match[1], CardSeeder::MAJOR_ARCANA)) {
                $byNumber[(int) $match[1]][] = $file;
            } else {
                $strays[] = $name;
            }
        }

        if ($strays !== []) {
            $this->warn('Stray files ignored: '.implode(', ', $strays));
        }

        $rows = [];
        $matchedRows = [];
        $failed = false;
        $dryRun = $this->option('dry-run');

        foreach (array_keys(CardSeeder::MAJOR_ARCANA) as $number) {
            $card = Card::where('number', $number)->first();
            $cardFiles = $byNumber[$number] ?? [];

            if (count($cardFiles) === 0) {
                $rows[] = [$number, $card?->translationLabel() ?? '', '', 'missing file'];
                $failed = true;

                continue;
            }

            if (count($cardFiles) > 1) {
                $names = array_map(fn ($file) => $file->getFilename(), $cardFiles);
                $rows[] = [$number, $card?->translationLabel() ?? '', implode(', ', $names), 'more than one file'];
                $failed = true;

                continue;
            }

            $file = $cardFiles[0];

            if ($card === null) {
                $rows[] = [$number, '', $file->getFilename(), 'missing card'];

                continue;
            }

            $result = $dryRun ? 'would skip' : 'skipped';

            if ($this->option('force') || ! $card->hasImage()) {
                $observed = $card->image_path;

                try {
                    $hash = sha1_file($file->getPathname());

                    if ($hash === false) {
                        throw new RuntimeException('Could not hash the file');
                    }

                    $storedName = substr($file->getFilename(), 0, -5).'-'.substr($hash, 0, 8).'.webp';
                    $storedPath = 'cards/'.$storedName;
                    // Comparing paths is enough only because the name carries the content hash.
                    $unchanged = $storedPath === $observed;
                    $action = $observed === null ? 'upload' : 'replace';
                    $result = $unchanged ? 'unchanged' : ($dryRun ? 'would '.$action : ($observed === null ? 'uploaded' : 'replaced'));

                    // Decide both runs the same way, branching only at the write so they cannot drift.
                    if (! $dryRun) {
                        $stored = Storage::disk(config('filesystems.media'))->putFileAs(
                            'cards', $file, $storedName, ['visibility' => 'public'],
                        );

                        if (! is_string($stored) || $stored === '') {
                            $result = 'failed: disk rejected the write';
                        } elseif (! $unchanged) {
                            // A panel edit or deletion during the upload must win.
                            // The builder skips model events, which is safe here:
                            // Card's hooks act on video_master_path, not pictures.
                            $written = Card::query()
                                ->whereKey($card->getKey())
                                ->whereNull('deleted_at')
                                ->when(
                                    $observed === null,
                                    fn ($q) => $q->whereNull('image_path'),
                                    fn ($q) => $q->where('image_path', $observed),
                                )
                                ->update(['image_path' => $stored]);

                            // Keep the object on conflict: deleting it could race
                            // another writer just as the row update could.
                            if ($written !== 1) {
                                $result = 'conflict';
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $result = 'failed: '.$e->getMessage();
                }
            }

            $failed = $failed || $result === 'conflict' || str_starts_with($result, 'failed:');
            $matchedRows[count($rows)] = $card->getKey();
            $rows[] = [$number, $card->translationLabel(), $file->getFilename(), $result];
        }

        $headers = ['#', 'Card', 'File', 'Result'];

        if ($this->option('verify')) {
            $headers[] = 'Verify';
            $cards = Card::query()->whereKey(array_values($matchedRows))->get()->keyBy('id');

            foreach ($rows as $index => &$row) {
                $row[] = '';
                $card = $cards->get($matchedRows[$index] ?? null);

                if ($card === null || ! $card->hasImage()) {
                    continue;
                }

                try {
                    $response = Http::timeout(5)->withoutRedirecting()->head(
                        Storage::disk(config('filesystems.media'))->url($card->image_path),
                    );
                    $row[4] = (string) $response->status();

                    if ($response->status() === 403) {
                        $row[4] .= ': the bucket is not publicly readable';
                    }

                    if (! $response->successful()) {
                        $failed = true;
                    }
                } catch (ConnectionException $e) {
                    $row[4] = 'connection error: '.$e->getMessage();
                    $failed = true;
                }
            }
            unset($row);
        }

        $this->table($headers, $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
