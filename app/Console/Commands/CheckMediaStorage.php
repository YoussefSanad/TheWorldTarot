<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Proves where uploads actually go.
 *
 * This exists because the failure it catches is silent. A deployed environment
 * left on a local disk accepts every upload, shows every picture, and loses the
 * lot on the next deploy, which nobody notices until the Library is full of
 * broken images.
 */
class CheckMediaStorage extends Command
{
    protected $signature = 'media:check';

    protected $description = 'Write, read back and delete a file on the media disk';

    public function handle(): int
    {
        $name = config('filesystems.media');
        $disk = Storage::disk($name);
        $driver = config("filesystems.disks.{$name}.driver");

        $this->components->twoColumnDetail('Disk', $name);
        $this->components->twoColumnDetail('Driver', (string) $driver);
        $this->components->twoColumnDetail('Bucket', (string) (config("filesystems.disks.{$name}.bucket") ?: 'not applicable'));

        // The whole point of the command. A local driver anywhere but a
        // developer's machine means uploads are being written to a filesystem
        // that does not survive a deploy.
        if ($driver === 'local' && ! app()->environment('local', 'testing')) {
            $this->components->error(
                'Uploads are going to this machine\'s own filesystem, which is wiped on every deploy. '
                .'Set MEDIA_DISK=s3.'
            );

            return self::FAILURE;
        }

        $path = 'health/media-check-'.now()->timestamp.'.txt';
        $written = 'written at '.now()->toIso8601String();

        try {
            $disk->put($path, $written);

            if ($disk->get($path) !== $written) {
                $this->components->error('The file came back different from what was written.');

                return self::FAILURE;
            }

            $this->components->twoColumnDetail('Public URL', $disk->url($path));
            $this->components->info('Wrote and read back a file successfully.');

            // Says what the command cannot check for itself. Reachability is a
            // bucket setting, not a credentials problem, and it fails in a way
            // that looks identical from in here.
            $this->components->warn('Open that URL in a browser. A 403 means the bucket is not publicly readable, and every card picture will be a broken image.');
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $disk->delete($path);
        }

        return self::SUCCESS;
    }
}
