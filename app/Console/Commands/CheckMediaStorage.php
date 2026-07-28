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
    /**
     * What a variable carrying a bucket name might be called.
     *
     * Public because the test has to create their absence deliberately: this
     * reads the real environment, and a CI runner has variables of its own that
     * match.
     */
    public const CANDIDATE_PATTERN = '/BUCKET|STORAGE|S3|R2|MEDIA|CLOUD/i';

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

        // Checked first, because it makes every other line here a lie. Laravel
        // does not read the .env file at all when the configuration is cached,
        // so env() returns null for everything and a perfectly well configured
        // environment reports as empty.
        if (app()->configurationIsCached()) {
            $this->components->error(
                'The configuration is cached, so nothing below can be trusted: the .env file is not read at all '
                .'in this state, and it was cached before the bucket existed. Run config:clear, then try again.'
            );

            return;
        }

        $this->components->info('What this environment was given:');

        foreach (['AWS_BUCKET', 'AWS_URL', 'AWS_ENDPOINT'] as $variable) {
            $this->components->twoColumnDetail(
                $variable,
                filled(env($variable)) ? '<fg=green>set</>' : '<fg=red>missing</>',
            );
        }

        // Reported apart from the rest, because absent is the normal state on a
        // platform that hands out temporary credentials, and reading them as a
        // fault sends people looking for keys that are not supposed to exist.
        $this->components->twoColumnDetail(
            'Credentials',
            filled(env('AWS_ACCESS_KEY_ID'))
                ? 'static keys'
                : (filled(env('AWS_CONTAINER_CREDENTIALS_FULL_URI'))
                    ? '<fg=green>provided by the platform</>'
                    : '<fg=red>none of any kind</>'),
        );

        $this->components->twoColumnDetail(
            'Region',
            (string) (config('filesystems.disks.s3.region') ?: '<fg=red>missing</>'),
        );

        $this->newLine();
        $this->reportCandidates();
    }

    /**
     * Anything in the environment that looks like it might name a bucket.
     *
     * Names only, never values. The platform provides credentials and a region
     * without a bucket name, which means either it publishes that name under a
     * variable of its own choosing, or the bucket belongs to another
     * environment. This tells the two apart without a second trip to the shell.
     */
    /**
     * Which disks this environment actually has, and which of them have a
     * bucket.
     *
     * Laravel Cloud ships disk configuration as a blob rather than as AWS_
     * variables, the same way it ships queue configuration, so the bucket can
     * be sitting in a perfectly good disk under a name nobody guessed. Reading
     * the list beats guessing at variable names.
     */
    private function reportDisks(): void
    {
        $this->components->info('Disks this environment defines:');

        foreach (array_keys(config('filesystems.disks', [])) as $disk) {
            $bucket = config("filesystems.disks.{$disk}.bucket");

            $this->components->twoColumnDetail(
                $disk,
                filled($bucket) ? '<fg=green>has a bucket</>' : (string) config("filesystems.disks.{$disk}.driver"),
            );
        }

        $this->newLine();
    }

    private function reportCandidates(): void
    {
        $known = ['AWS_BUCKET', 'AWS_URL', 'AWS_ENDPOINT', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'AWS_REGION'];

        $candidates = collect(array_keys(array_merge($_ENV, $_SERVER)))
            ->filter(fn (string $name): bool => (bool) preg_match(self::CANDIDATE_PATTERN, $name))
            ->reject(fn (string $name): bool => in_array($name, $known, true))
            ->unique()
            ->sort()
            ->values();

        $this->reportDisks();

        if ($candidates->isEmpty()) {
            $this->components->warn(
                'Nothing in this environment names a bucket under any name. The bucket is attached to a '
                .'different environment than this one.'
            );

            return;
        }

        $this->components->info('These variables might carry the bucket name under another name:');

        $candidates->each(fn (string $name) => $this->components->twoColumnDetail($name, 'present'));

        $this->newLine();
        $this->components->warn('Tell me which of those is the bucket and the disk will read it directly.');
    }
}
