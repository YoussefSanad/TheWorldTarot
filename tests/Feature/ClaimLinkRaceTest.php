<?php

use App\Actions\MarkOrderPaid;
use App\Mail\OrderPaid;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\Support\TruncatesWhatAGiftTouches;

uses(DatabaseTruncation::class, TruncatesWhatAGiftTouches::class);

/*
| Two settlements racing for one unclaimed customer, and one live claim link.
|
| **Issue #110.** `MarkOrderPaid::tell()` checks `holdsALiveClaimLink` and then
| mints with the broker's `createToken`, which deletes the row for the email and
| inserts a new one. `password_reset_tokens.email` is the primary key, so two
| settlements that both pass the check either delete each other's token (the
| link already in an inbox stops working) or the second insert throws a unique
| violation before that order's receipt is sent.
|
| ## Why this file commits, and why it forks
|
| Same reasons as `RedeemAGiftRaceTest`, which is the authority: a
| `RefreshDatabase` transaction never commits, so a second process would see
| nothing, and a scripted interleaving cannot tell a lock on the check from a
| lock on the write. Two real processes overlapping can. Several rounds, each on
| fresh data, because one round that did not overlap would be green about
| nothing.
|
| ## How receipts are observed
|
| A child cannot share `Mail::fake` with the parent. Each child fakes mail for
| itself, settles its order, and writes down what its own fake saw: how many
| `OrderPaid` receipts it queued and whether each carried a set-password link.
| The parent reads those notes.
|
| **Cleared afterwards as well as before**, for the reason given in
| `RedeemAGiftRaceTest`. The broker table is not on the shared allowlist and
| holds no foreign key, so it is emptied by hand here.
*/
beforeEach(function () {
    config()->set('frontend.url', 'https://theworldtarot.com');

    DB::table(config('auth.passwords.customers.table'))->delete();
});

afterEach(function () {
    DB::table(config('auth.passwords.customers.table'))->delete();

    $this->truncateTablesForAllConnections();
});

/**
 * The set-password token a receipt carries, or null when it carries none.
 *
 * The same reading as `claimTokenIn` in `MarkOrderPaidTest`, under another name
 * because Pest loads every test file's functions into one namespace.
 */
function raceClaimTokenIn(OrderPaid $mail): ?string
{
    $content = $mail->content();
    $text = view($content->text, $content->with)->render();

    if (preg_match('#https://\S+/set-password/\?\S+#', $text, $found) !== 1) {
        return null;
    }

    parse_str((string) parse_url($found[0], PHP_URL_QUERY), $query);

    return $query['token'] ?? null;
}

/**
 * Two real processes, each settling one of two pass orders at the same instant.
 *
 * @param  array<int, int>  $orderIds  One order id per racer
 * @return array<int, array{error: ?string, receipts: int, links: int, tokens: list<string>}>
 */
function settlingTogether(array $orderIds): array
{
    $answers = sys_get_temp_dir().'/claim-race-'.bin2hex(random_bytes(8));

    mkdir($answers);

    // Forking costs microseconds, so a third of a second is far more than the
    // start needs, and it leaves room for each child to warm up before it.
    $startAt = microtime(true) + 0.35;

    $children = [];

    foreach ($orderIds as $racer => $orderId) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('The race never started: this process could not fork.');
        }

        if ($pid !== 0) {
            $children[] = $pid;

            continue;
        }

        // A connection of its own, as in `RedeemAGiftRaceTest`: never touch the
        // socket the parent is still using.
        config(['database.connections.racer' => config('database.connections.'.config('database.default'))]);
        DB::setDefaultConnection('racer');

        $answer = ['error' => null, 'receipts' => 0, 'links' => 0, 'tokens' => []];

        try {
            Mail::fake();

            // Everything slow happens before the start line: the connection,
            // the order, its customer and its lines. Only the settlement is
            // left to overlap.
            DB::connection('racer')->getPdo();
            $order = Order::query()->with('customer', 'lines')->findOrFail($orderId);
            $action = app(MarkOrderPaid::class);

            // A busy wait rather than a sleep, so the two release within
            // microseconds of each other.
            while (microtime(true) < $startAt) {
                // spin
            }

            $action($order);

            foreach (Mail::queued(OrderPaid::class) as $mail) {
                $answer['receipts']++;

                $token = raceClaimTokenIn($mail);

                if ($token !== null) {
                    $answer['links']++;
                    $answer['tokens'][] = $token;
                }
            }
        } catch (Throwable $e) {
            $answer['error'] = $e::class;
        }

        file_put_contents($answers.'/'.$racer, json_encode($answer));

        // Killed rather than returned from, as in `RedeemAGiftRaceTest`: any
        // ordinary exit runs PHPUnit's shutdown handlers a second time.
        posix_kill(posix_getpid(), SIGKILL);
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $ignored);
    }

    $read = array_map(
        fn (int $racer): array => is_file($answers.'/'.$racer)
            ? json_decode((string) file_get_contents($answers.'/'.$racer), true)
            : ['error' => 'no answer', 'receipts' => 0, 'links' => 0, 'tokens' => []],
        array_keys($orderIds),
    );

    // The notes may hold a live token, so they do not outlive the round.
    array_map('unlink', glob($answers.'/*') ?: []);
    rmdir($answers);

    return $read;
}

it('leaves exactly one live claim link and both receipts sent when two pass orders settle at once', function () {
    // Named rather than skipped: a skip reads as a pass.
    expect(extension_loaded('pcntl'))->toBeTrue()
        ->and(function_exists('posix_kill'))->toBeTrue();

    // `products.key` is unique and there is one pass, so the product is shared
    // by every round and the customer is what is fresh each time.
    $pass = Product::factory()->pass()->create();

    foreach (range(1, 12) as $round) {
        $customer = Customer::factory()->create([
            'email' => "buyer{$round}@example.com",
            'password' => null,
        ]);

        $orders = [
            1 => Order::factory()->for($customer)->forProduct($pass)->create(),
            2 => Order::factory()->for($customer)->forProduct($pass)->create(),
        ];

        $answers = settlingTogether(array_map(fn (Order $order): int => $order->id, $orders));

        // (b) Neither racer died. Without a lock the second insert can throw a
        // unique violation before its receipt is sent.
        expect(array_column($answers, 'error'))->toBe([null, null], "round {$round}");

        // (a) One row in the broker table for this address, never none and
        // never two.
        expect(DB::table(config('auth.passwords.customers.table'))
            ->where('email', $customer->email)
            ->count())->toBe(1, "round {$round}");

        // (c) Both receipts went out, and exactly one of them carries the
        // link. The other is the one `holdsALiveClaimLink` turned away.
        expect(array_column($answers, 'receipts'))->toBe([1, 1], "round {$round}")
            ->and(array_sum(array_column($answers, 'links')))->toBe(1, "round {$round}");

        // (d) The link that was emailed is the live one. One row in the table
        // is not enough: a racer could have mailed a token the other racer's
        // mint then deleted.
        $tokens = array_merge(...array_column($answers, 'tokens'));

        expect($tokens)->toHaveCount(1, "round {$round}")
            ->and(Password::broker('customers')->tokenExists($customer->fresh(), $tokens[0]))
            ->toBeTrue("round {$round}");
    }
});
