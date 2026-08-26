<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Measures how often this platform actually invokes `schedule:run`.
 *
 * **Temporary. Remove it, and its line in `routes/console.php`, once the
 * measurement is written down.** It exists to answer one question that has been
 * carried as unknown since M4 and is not a permanent part of the system.
 *
 * The question is not academic. Task frequency lives in `routes/console.php`,
 * so `orders:reconcile` running `everyFiveMinutes()` is a ceiling and not a
 * floor: if the platform ticks hourly then a lost Stripe notification costs a
 * customer an hour rather than twenty minutes, whatever that line says, and
 * nothing anywhere would say so. Scheduled gift delivery is also waiting on
 * this answer, per `docs/plans/gifting-plan.md`.
 *
 * Recording and reporting are one command on purpose, matching
 * `availability:check --explain`. The recording is the check.
 *
 * Cache rather than a table, because a migration for a measurement that gets
 * deleted next week is the larger change. The store is `database` by default
 * here, so this survives a deploy and a restart, which a measurement spanning
 * an hour needs.
 *
 * On Laravel Cloud, run the report from the environment's command runner:
 *
 *     php artisan scheduler:check --report
 *
 * Read `docs/deploy/measuring-the-scheduler.md` before believing a number.
 */
class CheckSchedulerTick extends Command
{
    protected $signature = 'scheduler:check
        {--report : Print what has been recorded so far and the gaps between ticks, writing nothing}
        {--forget : Throw away every recorded tick and start the measurement again}';

    protected $description = 'Record a scheduler tick, or report the measured interval between them';

    /**
     * Where the ticks live, namespaced by host so a staging measurement and a
     * production one can never be read as one series.
     *
     * **The host and not `app()->environment()`**, corrected 26 August 2026.
     * Laravel Cloud sets `APP_ENV=production` on every environment it hosts, so
     * staging and production both answered `production` and the key separated
     * nothing. The two happened not to collide because they hold separate
     * databases, which is luck rather than design, and a comment claiming the
     * key did the work was the trap this project keeps meeting: a label that
     * looks right and is about the wrong thing.
     */
    private function key(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return 'scheduler-ticks:'.(is_string($host) && $host !== '' ? $host : 'unknown-host');
    }

    /**
     * The key the previous version of this command wrote to.
     *
     * It exists only so that renaming the key cannot fabricate the worst
     * verdict this report has. An environment that had been ticking happily for
     * an hour would have answered "nothing has been recorded" the moment the
     * rename deployed, and that sentence means "this platform runs no scheduler
     * at all", which is a conclusion worth an afternoon in the dashboard. A
     * measurement that reports the effect of its own deploy is measuring
     * itself.
     *
     * Read before the host key and adopted by the next tick, then dropped.
     * Delete it with the command.
     */
    private function legacyKey(): string
    {
        return 'scheduler-ticks:'.app()->environment();
    }

    /**
     * Four hours at one a minute. Enough to tell a five minute tick from an
     * hourly one several times over, and bounded so a forgotten heartbeat
     * cannot grow without limit.
     */
    private const KEEP = 240;

    public function handle(): int
    {
        if ($this->option('forget')) {
            Cache::forget($this->key());
            $this->components->info('Recorded ticks thrown away. The measurement starts from the next one.');

            return self::SUCCESS;
        }

        return $this->option('report')
            ? $this->report()
            : $this->record();
    }

    /**
     * The scheduled half. Appends the moment it ran and says nothing, because
     * it runs unattended and its output goes nowhere.
     */
    private function record(): int
    {
        $ticks = $this->ticks();
        $ticks[] = CarbonImmutable::now()->toIso8601String();

        // Never expires. A tick recorded an hour ago is the entire point, and a
        // TTL shorter than the interval being measured would quietly delete the
        // evidence and report a healthy tick over whatever survived.
        Cache::forever($this->key(), array_slice($ticks, -self::KEEP));

        // Whatever was under the old key has now been written under the new
        // one, so drop it rather than leave a second copy that a later reader
        // could add to this one.
        if ($this->legacyKey() !== $this->key()) {
            Cache::forget($this->legacyKey());
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function ticks(): array
    {
        $ticks = $this->series($this->key());

        return $ticks === [] ? $this->series($this->legacyKey()) : $ticks;
    }

    /**
     * @return array<int, string>
     */
    private function series(string $key): array
    {
        $ticks = Cache::get($key, []);

        return is_array($ticks) ? array_values($ticks) : [];
    }

    private function report(): int
    {
        $ticks = $this->series($this->key());
        $adopted = $ticks === [] && $this->series($this->legacyKey()) !== [];

        if ($adopted) {
            $ticks = $this->series($this->legacyKey());
        }

        // The URL as well as APP_ENV, because Laravel Cloud sets APP_ENV to
        // production on every environment it hosts unless somebody overrides
        // it. A report about money that says "production" while describing
        // staging is the kind of label this project has been caught by before.
        $this->components->twoColumnDetail('Environment', app()->environment());
        $this->components->twoColumnDetail('Target', (string) config('app.url'));
        $this->components->twoColumnDetail('Ticks recorded', (string) count($ticks));

        if ($adopted) {
            $this->components->twoColumnDetail('Read from', $this->legacyKey().', written before the key became the host');
        }

        if ($ticks === []) {
            $this->newLine();
            $this->components->error('Nothing has been recorded, under either key.');
            $this->line('  Either the heartbeat is not deployed, or this platform is not running');
            $this->line('  `schedule:run` at all. Those are different problems and the second one is');
            $this->line('  much worse: it would mean `orders:reconcile` has never executed here.');
            $this->line('  Both `'.$this->key().'` and `'.$this->legacyKey().'` were empty, so');
            $this->line('  this is not the key rename of 26 August hiding an older series.');

            return self::FAILURE;
        }

        $times = array_map(fn (string $at) => CarbonImmutable::parse($at), $ticks);
        $first = $times[0];
        $last = $times[count($times) - 1];

        $this->components->twoColumnDetail('First tick', $first->toDateTimeString().' UTC');
        $this->components->twoColumnDetail('Last tick', $last->toDateTimeString().' UTC');
        $this->components->twoColumnDetail('Watched for', $this->humanise($first->diffInSeconds($last)));

        $sinceLast = $last->diffInSeconds(CarbonImmutable::now());

        $this->components->twoColumnDetail('Last tick was', $this->humanise($sinceLast).' ago');

        // A tick that has gone quiet is the loudest thing this command can
        // find, and it is not the same as never having started. If the
        // scheduler were running at any sane rate there would be another one by
        // now, so silence for this long means it has stopped or was never
        // configured, and every scheduled task in routes/console.php is
        // therefore not running at all.
        if ($sinceLast > 900) {
            $this->newLine();
            $this->components->error('The heartbeat has gone quiet for '.$this->humanise($sinceLast).'.');
            $this->line('  Nothing is asking this environment to tick. Check that a scheduler is');
            $this->line('  configured for it at all: Laravel Cloud does not run one by default, and');
            $this->line('  without it `orders:reconcile` and `cards:reconcile-videos` have never run');
            $this->line('  here even once. A single old tick usually means somebody invoked this');
            $this->line('  command by hand, which writes one exactly like a real tick does.');

            return self::FAILURE;
        }

        if (count($times) < 2) {
            $this->newLine();
            $this->components->warn('One tick is not an interval. Wait and ask again.');

            return self::FAILURE;
        }

        $gaps = [];

        for ($i = 1; $i < count($times); $i++) {
            $gaps[] = $times[$i - 1]->diffInSeconds($times[$i]);
        }

        sort($gaps);

        $shortest = $gaps[0];
        $longest = $gaps[count($gaps) - 1];
        $typical = $gaps[intdiv(count($gaps), 2)];

        $this->newLine();
        $this->components->twoColumnDetail('Shortest gap', $this->humanise($shortest));
        $this->components->twoColumnDetail('Typical gap', $this->humanise($typical));
        $this->components->twoColumnDetail('Longest gap', $this->humanise($longest));

        $this->newLine();

        // The typical gap rather than the shortest. A single short gap is two
        // invocations landing in one minute, which says nothing about the rate,
        // and the longest is whatever deploy or cold start happened to be in
        // the window. The middle is the one that describes the platform.
        $this->components->info('The scheduler ticks about every '.$this->humanise($typical).'.');

        $watched = $first->diffInSeconds($last);

        if ($watched < 3600) {
            $this->components->warn(
                'Watched for less than an hour, so a five minute tick and an hourly one are '
                .'not yet told apart. Leave it running and ask again.'
            );

            return self::FAILURE;
        }

        if ($typical > 90) {
            $this->components->warn(
                'This is slower than a minute, so every frequency in routes/console.php is a '
                .'ceiling rather than a floor. `orders:reconcile` is really running every '
                .$this->humanise(max($typical, 300)).', not every five minutes.'
            );
        }

        return self::SUCCESS;
    }

    private function humanise(int $seconds): string
    {
        if ($seconds < 120) {
            return $seconds.' second'.($seconds === 1 ? '' : 's');
        }

        if ($seconds < 7200) {
            return round($seconds / 60).' minutes';
        }

        return round($seconds / 3600, 1).' hours';
    }
}
