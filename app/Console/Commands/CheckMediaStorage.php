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

    protected $description = 'Write, read back and delete a file on each storage disk';

    public function handle(): int
    {
        $media = config('filesystems.media');
        $uploads = config('filesystems.uploads');

        // Two disks that want opposite things: pictures are public and want
        // caching, video masters are the asset the paywall exists to protect.
        // One disk serving both means the videos are in a public bucket.
        if ($media === $uploads) {
            $this->components->error(
                "The pictures and the video masters are both on the \"{$media}\" disk. "
                .'Whatever is true of one is true of the other, and video masters must never be public.'
            );

            return self::FAILURE;
        }

        $this->components->info('Pictures, publicly readable:');
        $pictures = $this->checkDisk($media, public: true);

        $this->newLine();
        $this->components->info('Video masters, never public:');
        $masters = $this->checkDisk($uploads, public: false);

        return $pictures && $masters ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Write, read back and delete on one disk, and say what it is.
     */
    private function checkDisk(string $name, bool $public): bool
    {
        $driver = config("filesystems.disks.{$name}.driver");
        $bucket = config("filesystems.disks.{$name}.bucket");

        $this->components->twoColumnDetail('Disk', $name);
        $this->components->twoColumnDetail('Driver', (string) $driver);
        $this->components->twoColumnDetail('Bucket', (string) ($bucket ?: 'not applicable'));

        // The one thing a private disk must never be: the disk Laravel serves
        // over the web from public/storage. It would put every video master a
        // URL away, which is the exact finding this project was hired to fix.
        if (! $public && $name === 'public') {
            $this->components->error('The video masters are on the disk the web server serves. Anyone could fetch them.');

            return false;
        }

        // Before touching the disk, because building an s3 disk with no bucket
        // throws a type error from inside Flysystem, and a stack trace is a
        // poor way to be told an environment variable is missing.
        if ($driver === 's3' && blank($bucket)) {
            $this->components->error('This environment has no bucket configured, so uploads cannot work at all.');
            $this->reportEnvironment();

            return false;
        }

        $disk = Storage::disk($name);

        // The whole point of the command. A local driver anywhere but a
        // developer's machine means uploads are being written to a filesystem
        // that does not survive a deploy.
        if ($driver === 'local' && ! app()->environment('local', 'testing')) {
            $this->components->error(
                'These files are going to this machine\'s own filesystem, which is wiped on every deploy. '
                .'This environment needs a bucket attached for them.'
            );

            return false;
        }

        $path = 'health/media-check-'.now()->timestamp.'.txt';
        $written = 'written at '.now()->toIso8601String();

        try {
            $disk->put($path, $written);

            if ($disk->get($path) !== $written) {
                $this->components->error('The file came back different from what was written.');

                return false;
            }

            $this->components->info('Wrote and read back a file successfully.');

            // Says what the command cannot check for itself. Whether a bucket
            // answers the public is a dashboard setting, and from in here a
            // private bucket and a public one behave identically.
            if ($public) {
                $this->components->twoColumnDetail('Public URL', $disk->url($path));
                $this->components->warn('Open that URL. A 403 means the bucket is not publicly readable, and every card picture is a broken image.');
            } else {
                $this->components->warn('Confirm in the dashboard that this bucket is private. Nothing here can tell the difference, and everything the paywall protects lives on it.');
            }
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return false;
        } finally {
            $disk->delete($path);
        }

        return true;
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
