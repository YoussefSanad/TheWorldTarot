<?php

namespace App\Console\Commands;

use App\Models\Card;
use App\Support\MediaDiskGuard;
use Database\Seeders\CardSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SeedCardImages extends Command
{
    protected $signature = 'cards:seed-images {--source= : Directory to read from, default database_path(\'seed-images/cards\')}';

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
            $files = File::files($this->option('source') ?: database_path('seed-images/cards'));
        } catch (Throwable $e) {
            $this->components->error('Could not read the source directory: '.$e->getMessage());

            return self::FAILURE;
        }

        $byNumber = [];

        foreach ($files as $file) {
            if (preg_match('/^(\d{2})-[a-z0-9-]+\.webp$/', $file->getFilename(), $match)) {
                $byNumber[(int) $match[1]][] = $file;
            }
        }

        $rows = [];
        $failed = false;

        foreach (array_keys(CardSeeder::MAJOR_ARCANA) as $number) {
            if (count($byNumber[$number] ?? []) !== 1) {
                continue;
            }

            $card = Card::where('number', $number)->first();

            if ($card === null) {
                continue;
            }

            $file = $byNumber[$number][0];
            $result = 'skipped';

            if (! $card->hasImage()) {
                $observed = $card->image_path;

                try {
                    $hash = sha1_file($file->getPathname());

                    if ($hash === false) {
                        throw new RuntimeException('Could not hash the file');
                    }

                    $storedName = substr($file->getFilename(), 0, -5).'-'.substr($hash, 0, 8).'.webp';
                    $stored = Storage::disk(config('filesystems.media'))->putFileAs(
                        'cards', $file, $storedName, ['visibility' => 'public'],
                    );

                    if (! is_string($stored) || $stored === '') {
                        $result = 'failed: disk rejected the write';
                    } else {
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
                        $result = $written === 1 ? 'uploaded' : 'conflict';
                    }
                } catch (Throwable $e) {
                    $result = 'failed: '.$e->getMessage();
                }
            }

            $failed = $failed || $result === 'conflict' || str_starts_with($result, 'failed:');
            $rows[] = [$number, $card->translationLabel(), $file->getFilename(), $result];
        }

        $this->table(['#', 'Card', 'File', 'Result'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
