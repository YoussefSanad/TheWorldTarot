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
     * Where the ticks live. Namespaced under the environment so a staging
     * measurement and a production one can never be read as one series if the
     * two ever share a cache store.
     */
    private function key(): string
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

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function ticks(): array
    {
        $ticks = Cache::get($this->key(), []);

        return is_array($ticks) ? array_values($ticks) : [];
    }

    private function report(): int
    {
        $ticks = $this->ticks();

        $this->components->twoColumnDetail('Environment', app()->environment());
        $this->components->twoColumnDetail('Ticks recorded', (string) count($ticks));

        if ($ticks === []) {
            $this->newLine();
            $this->components->error('Nothing has been recorded.');
            $this->line('  Either the heartbeat is not deployed, or this platform is not running');
            $this->line('  `schedule:run` at all. Those are different problems and the second one is');
            $this->line('  much worse: it would mean `orders:reconcile` has never executed here.');

            return self::FAILURE;
        }

        $times = array_map(fn (string $at) => CarbonImmutable::parse($at), $ticks);
        $first = $times[0];
        $last = $times[count($times) - 1];

        $this->components->twoColumnDetail('First tick', $first->toDateTimeString().' UTC');
        $this->components->twoColumnDetail('Last tick', $last->toDateTimeString().' UTC');
        $this->components->twoColumnDetail('Watched for', $this->humanise($first->diffInSeconds($last)));

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
