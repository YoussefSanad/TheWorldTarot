<?php

namespace App\Console\Commands;

use App\Models\Card;
use App\Support\Locales;
use Database\Seeders\CardSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SeedCardCopy extends Command
{
    protected $signature = 'cards:seed-copy {--file= : Path to the copy file} {--force : Replace existing English copy} {--dry-run : Say what would change and write nothing}';

    protected $description = 'Seed card copy from a file';

    public function handle(): int
    {
        $file = $this->option('file') ?: database_path('seed-copy/cards.en.json');
        $entries = $this->readCopyFile($file);

        if ($entries === null) {
            return self::FAILURE;
        }

        $strays = [];

        foreach (array_keys(get_object_vars($entries)) as $key) {
            if (! array_key_exists($key, CardSeeder::MAJOR_ARCANA)) {
                $strays[] = $key;
            }
        }

        if ($strays !== []) {
            $this->warn('Stray keys ignored: '.implode(', ', $strays));
        }

        $rows = [];
        $pending = [];
        $failed = false;
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');
        $cards = [];
        $fields = ['short_description' => 'short', 'long_description' => 'long'];

        foreach (array_keys(CardSeeder::MAJOR_ARCANA) as $number) {
            $card = Card::where('number', $number)->first();
            $label = $card?->translationLabel() ?? '';

            if (! property_exists($entries, (string) $number)) {
                if ($card !== null) {
                    $cards[count($rows)] = $card;
                }
                $rows[] = [$number, $label, '', '', 'no entry'];
                $failed = true;

                continue;
            }

            if ($card === null) {
                $rows[] = [$number, '', '', '', 'missing card'];

                continue;
            }

            $cards[count($rows)] = $card;
            $entry = $entries->{(string) $number};
            $observed = [];
            $decisions = [];
            $next = [];
            $result = null;

            foreach ($fields as $field => $key) {
                $observed[$field] = $card->getRawOriginal($field);
                $value = json_decode($observed[$field]);

                if (json_last_error() !== JSON_ERROR_NONE || (! is_object($value) && $value !== null && $value !== [])) {
                    $result = 'failed: unexpected value in '.$field;
                    break;
                }

                if ($value === null || $value === []) {
                    $value = (object) [];
                }

                $english = $value->en ?? null;

                if ($english !== null && ! is_string($english)) {
                    $result = 'failed: unexpected value in '.$field;
                    break;
                }

                if ($english === $entry->{$key}) {
                    $decisions[$field] = 'same';
                } elseif ($english === null || trim($english) === '') {
                    $decisions[$field] = 'fill';
                    $value->en = $entry->{$key};
                    $next[$field] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } elseif ($force) {
                    $decisions[$field] = 'replace';
                    $value->en = $entry->{$key};
                    $next[$field] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } else {
                    $decisions[$field] = 'keep';
                }
            }

            if ($result === null && $next === []) {
                $result = in_array('keep', $decisions, true) ? 'kept' : 'unchanged';
            } elseif ($result === null) {
                $pending[count($rows)] = [
                    'id' => $card->getKey(),
                    'number' => $number,
                    'observed' => $observed,
                    'next' => $next,
                ];
            }

            $failed = $failed || ($result !== null && str_starts_with($result, 'failed:'));
            $rows[] = [$number, $label, $decisions['short_description'] ?? '', $decisions['long_description'] ?? '', $result];
        }

        // Decide every card first: the backup must cover all planned writes and
        // must be verified before even the first row can change.
        if ($force && ! $dryRun && $pending !== [] && ! $this->backupCopy($file, $pending)) {
            return self::FAILURE;
        }

        foreach ($pending as $index => $write) {
            if ($dryRun) {
                // Every decision above was made as a real run makes it; only
                // the write is skipped, so a dry run can never say conflict.
                $rows[$index][4] = 'would write';

                continue;
            }

            try {
                // Compare both whole columns so an edit in any language wins,
                // even in the field not being written. Never use ->en: jsonb_set
                // rejects CardSeeder's top-level [] and would miss other locales.
                // Builder updates skip model events safely because both hooks in
                // Card::booted() act only on video_master_path.
                $written = Card::query()
                    ->whereKey($write['id'])
                    ->whereNull('deleted_at')
                    ->whereRaw('short_description = ?::jsonb', [$write['observed']['short_description']])
                    ->whereRaw('long_description = ?::jsonb', [$write['observed']['long_description']])
                    ->update($write['next']);

                $result = $written === 1 ? 'written' : 'conflict';
            } catch (Throwable $e) {
                $result = 'failed: '.$e->getMessage();
            }

            $failed = $failed || $result === 'conflict' || str_starts_with($result, 'failed:');
            $rows[$index][4] = $result;
        }

        foreach ($rows as $index => $row) {
            $rows[$index][5] = isset($cards[$index])
                ? $this->live($cards[$index], $dryRun ? ($pending[$index]['next'] ?? []) : null)
                : '';
        }

        $this->table(['#', 'Card', 'Short', 'Long', 'Result', 'Live?'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Whether the website shows this card now, in the words the gate uses.
     *
     * After a real run the row is read again, so a conflict shows what the
     * other writer left. A dry run asks a copy of the card holding the planned
     * values, which is never saved.
     *
     * @param  array<string, string>|null  $planned  raw jsonb per field on a dry run, null after a real run
     */
    private function live(Card $card, ?array $planned): string
    {
        if ($planned === null) {
            $subject = Card::withTrashed()->find($card->getKey());

            // Removed for good mid-run: no card left to ask, as for `missing card`.
            if ($subject === null) {
                return '';
            }
        } else {
            $subject = clone $card;
            $subject->setRawAttributes(array_merge($card->getAttributes(), $planned));
        }

        // The gate's own language, not the file's `en` by assumption.
        $missing = Card::availability()->unmetBy($subject, Locales::default());

        return $missing === [] ? 'yes' : 'no: '.implode(', ', $missing);
    }

    private function backupCopy(string $file, array $pending): bool
    {
        $created = false;
        $handle = null;
        $path = null;

        try {
            $disk = Storage::disk('local');
            $takenAt = now();
            $relative = 'seed-copy-backups/cards-copy-'.$takenAt->format('Ymd-His').'.json';
            $directory = $disk->path('seed-copy-backups');
            $path = $disk->path($relative);

            if (! is_dir($directory) && ! File::makeDirectory($directory, 0755, true, true) && ! is_dir($directory)) {
                throw new RuntimeException('Could not create the backup directory');
            }

            $cards = [];
            foreach ($pending as $write) {
                // Embed the database's valid JSON directly: decoding a large
                // number into a PHP float could lose copy in the backup.
                $cards[] = '{"id":'.json_encode($write['id'], JSON_THROW_ON_ERROR)
                    .',"number":'.json_encode($write['number'], JSON_THROW_ON_ERROR)
                    .',"short_description":'.$write['observed']['short_description']
                    .',"long_description":'.$write['observed']['long_description'].'}';
            }

            $metadata = json_encode([
                'taken_at' => $takenAt->toIso8601String(),
                'environment' => app()->environment(),
                'file' => $file,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $json = substr($metadata, 0, -1).',"cards":['.implode(',', $cards).']}';

            // Exclusive creation, not exists followed by put, prevents a second
            // run in the same second from replacing the first run's backup.
            $handle = @fopen($path, 'x');
            if ($handle === false) {
                throw new RuntimeException('Could not create the backup file exclusively');
            }
            $created = true;

            $length = strlen($json);
            for ($offset = 0; $offset < $length; $offset += $written) {
                $written = fwrite($handle, substr($json, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Could not write the backup file');
                }
            }

            if (! fflush($handle)) {
                throw new RuntimeException('Could not flush the backup file');
            }
            $closed = fclose($handle);
            $handle = null;
            if (! $closed) {
                throw new RuntimeException('Could not close the backup file');
            }

            // Exact bytes plus valid JSON verify nested types and numeric values
            // without a lossy decode-and-reencode comparison.
            $readBack = $disk->get($relative);
            if (! is_string($readBack) || $readBack !== $json) {
                throw new RuntimeException('Backup read-back did not match');
            }
            json_decode($readBack, flags: JSON_THROW_ON_ERROR);

            $this->line('Backup: '.$path);
            $this->warn('The local disk is wiped on deploy. Copy the backup off the server before trusting this run.');

            return true;
        } catch (Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($created) {
                @unlink($path);
            }

            $this->components->error('Could not verify the copy backup: '.$e->getMessage());

            return false;
        }
    }

    private function readCopyFile(string $path): ?object
    {
        try {
            if (! File::isFile($path) || ! File::isReadable($path)) {
                throw new RuntimeException('Not a readable file');
            }

            $content = File::get($path);
        } catch (Throwable $e) {
            $this->components->error('Could not read the copy file: '.$e->getMessage());

            return null;
        }

        $entries = json_decode($content);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->components->error('The copy file is not valid JSON.');

            return null;
        }

        if (! is_object($entries)) {
            $this->components->error('The copy file must be a JSON object.');

            return null;
        }

        foreach ($entries as $number => $entry) {
            if (! is_object($entry)) {
                $this->components->error('Entry "'.$number.'" must be an object.');

                return null;
            }

            foreach (['short', 'long'] as $field) {
                if (! isset($entry->{$field}) || ! is_string($entry->{$field}) || trim($entry->{$field}) === '') {
                    $this->components->error('Entry "'.$number.'" field '.$field.' must be a non-empty string.');

                    return null;
                }
            }

            foreach (array_keys(get_object_vars($entry)) as $field) {
                if (! in_array($field, ['short', 'long'], true)) {
                    $this->components->error('Entry "'.$number.'" has an unexpected field: '.$field.'.');

                    return null;
                }
            }
        }

        return $entries;
    }
}
