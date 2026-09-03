<?php

use App\Enums\ProductKey;
use App\Models\Gift;
use App\Models\Reading;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\Support\TruncatesWhatAGiftTouches;

uses(DatabaseTruncation::class, TruncatesWhatAGiftTouches::class);

/*
| **Cleared afterwards as well as before.** `DatabaseTruncation` empties the
| tables on the way in and not on the way out, which is correct for a suite made
| entirely of it and wrong for one file inside a suite of `RefreshDatabase`:
| every row this file commits would otherwise still be there for the rest of the
| run, and a test counting products or orders would find somebody else's.
|
| Found by running it, on 3 September 2026. Eleven tests in four unrelated files
| went red counting one thing too many, and none of them named this file.
*/
afterEach(function () {
    $this->truncateTablesForAllConnections();
});

/*
| Two requests racing one code, and exactly one of them wins.
|
| **The gate on B7, issue #73, and the only thing in this repository that proves
| the row lock exists.** Every other case in `RedeemAGiftTest` passes just as
| happily against an implementation with no lock at all: a second redemption
| refused *afterwards* is refused by the stamp, not by the lock, and the stamp
| is only reached one request at a time.
|
| **This is the endpoint where a mistake means free readings.** There are no
| refunds and no expiry, so a gift spent twice is a reading written and given
| away, and the only record that it happened would be two rows nobody can
| explain.
|
| ## Why this file commits, and why it forks
|
| `RefreshDatabase` wraps a test in a transaction that never commits, so nothing
| it writes is visible to any other connection and there is nothing for a second
| process to race for. `DatabaseTruncation` commits instead, and
| `Tests\Support\TruncatesWhatAGiftTouches` says which tables it may then empty.
|
| **A scripted interleaving on two connections was tried first and cannot
| answer this.** Holding the row on a second connection and watching the
| redemption time out proves the same thing whether the lock is on the read or
| only on the write, because Postgres blocks the `update` either way. The one
| arrangement that tells those apart is two processes genuinely overlapping,
| which is what the ticket asked for and why it says this is the only proof.
|
| **A race is a probability, so one round is not a proof.** A single round that
| happened not to overlap would be green about nothing, which is exactly the
| shape of failure `CLAUDE.md` section 0.5 warns about. Several rounds, each
| against a fresh code, is what makes a missing lock something this notices
| rather than something it might.
*/

/**
 * Two real processes, both submitting the same code at the same instant.
 *
 * The endpoint rather than the action, because a request is what races in the
 * world: two tabs, or a submit and the impatient second submit behind it.
 *
 * @return array<int, string> The status each racer got back
 */
function racingForOneCode(string $code, int $racers = 2): array
{
    $answers = sys_get_temp_dir().'/gift-race-'.bin2hex(random_bytes(8));

    mkdir($answers);

    // A wall clock instant both racers wait for, so the two requests overlap
    // rather than running one after the other. Forking costs microseconds, so
    // a quarter of a second is far more than the start needs.
    $startAt = microtime(true) + 0.25;

    $children = [];

    foreach (range(1, $racers) as $racer) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('The race never started: this process could not fork.');
        }

        if ($pid !== 0) {
            $children[] = $pid;

            continue;
        }

        // **A connection of its own, on a name the parent has never opened.** A
        // fork duplicates the parent's socket to Postgres, and two processes
        // writing down one socket corrupt the protocol between them.
        // Reconnecting the default connection is not the fix, because
        // disconnecting it here tells the server to end the backend the parent
        // is still using. So the child never touches it: it opens a second one
        // and leaves the inherited handle alone.
        config(['database.connections.racer' => config('database.connections.'.config('database.default'))]);
        DB::setDefaultConnection('racer');

        usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

        try {
            $answer = (string) test()->postJson('/api/v1/en/gifts/redeem', [
                'code' => $code,
                'question' => "Racer {$racer}, what should I focus on this month?",
                'querent_email' => "racer{$racer}@example.com",
            ])->status();
        } catch (Throwable $e) {
            $answer = 'threw '.$e::class;
        }

        file_put_contents($answers.'/'.$racer, $answer);

        // **Killed rather than returned from or exited.** This process is
        // inside PHPUnit, and any ordinary end to it runs the shutdown
        // handlers, which report a second set of results for a run nobody
        // started. A signal leaves the inherited database handle unfinished
        // too, which is what keeps the parent's connection alive.
        posix_kill(posix_getpid(), SIGKILL);
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $ignored);
    }

    return array_map(
        fn (int $racer): string => is_file($answers.'/'.$racer)
            ? (string) file_get_contents($answers.'/'.$racer)
            : 'no answer',
        range(1, $racers),
    );
}

it('lets exactly one of two requests racing one code win', function () {
    // Named rather than skipped. A skipped test reads as a passing one in the
    // summary line, and this is the case the unit exists to prove.
    expect(extension_loaded('pcntl'))->toBeTrue()
        ->and(function_exists('posix_kill'))->toBeTrue();

    // Three rounds, and a different giftable reading each time because
    // `products.key` is unique and there is one row per product for the life of
    // the site.
    $rounds = [
        'K7M49PQR2XYZ' => ProductKey::ThreeCard,
        'A1B2C3D4E5F6' => ProductKey::MonthAhead,
        'ZZZZ11112222' => ProductKey::InDepth,
    ];

    foreach ($rounds as $code => $product) {
        aGiftOf(['code' => $code], ['key' => $product]);

        $answers = racingForOneCode($code);
        sort($answers);

        // One served and one refused, in whichever order they arrived. Never
        // two 200s, which is the free reading, and never two 409s, which is a
        // present nobody can open.
        expect($answers)->toBe(['200', '409']);

        $gift = Gift::where('code', $code)->sole();

        expect($gift->isRedeemed())->toBeTrue()
            ->and(Reading::where('gift_id', $gift->id)->count())->toBe(1);
    }
});

it('gives the reading to whichever racer won, whole', function () {
    aGiftOf(['code' => 'K7M49PQR2XYZ']);

    racingForOneCode('K7M49PQR2XYZ');

    $reading = Reading::sole();

    // **Not a mixture of the two.** The loser is refused before anything of
    // theirs is written, so the question and the address on the reading belong
    // to one person and the winner is the person who gets the answer.
    expect($reading->querent_email)->toBe(
        str_contains((string) $reading->question, 'Racer 1') ? 'racer1@example.com' : 'racer2@example.com',
    );
});
