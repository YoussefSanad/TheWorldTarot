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
        $driver = config("filesystems.disks.{$name}.driver");
        $bucket = config("filesystems.disks.{$name}.bucket");

        $this->components->twoColumnDetail('Disk', $name);
        $this->components->twoColumnDetail('Driver', (string) $driver);
        $this->components->twoColumnDetail('Bucket', (string) ($bucket ?: 'not applicable'));

        // Before touching the disk, because building an s3 disk with no bucket
        // throws a type error from inside Flysystem, and a stack trace is a
        // poor way to be told an environment variable is missing.
        if ($driver === 's3' && blank($bucket)) {
            $this->components->error('This environment has no bucket configured, so uploads cannot work at all.');
            $this->reportEnvironment();

            return self::FAILURE;
        }

        $disk = Storage::disk($name);

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

    /**
     * Which of the platform's variables actually reached this environment.
     *
     * Names and whether they are set, never values. The usual answer is that
     * the bucket is attached to a different environment than the one this ran
     * in, or that the configuration was cached before the platform injected
     * anything.
     */
    private function reportEnvironment(): void
    {
        $this->newLine();
        $this->components->info('What this environment was given:');

        foreach (['AWS_BUCKET', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'AWS_ENDPOINT', 'AWS_URL'] as $variable) {
            $this->components->twoColumnDetail(
                $variable,
                filled(env($variable)) ? '<fg=green>set</>' : '<fg=red>missing</>',
            );
        }

        $this->newLine();
        $this->components->warn(
            'If these are all missing, the bucket is attached to another environment, or the configuration '
            .'was cached before it was attached. Run config:clear and try again. '
            .'If the platform names them differently, tell me the names and the disk will read those instead.'
        );
    }
}
