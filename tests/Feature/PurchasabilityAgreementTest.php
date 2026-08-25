<?php

use App\Actions\PlaceOrder;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Purchasability\Basket;
use App\Purchasability\Purchasability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost');
    Mail::fake();
});

/**
 * "Can this basket be bought" is one rule with two renderings: refusals a
 * customer reads, and a predicate the seam guards on. Before the declaration
 * they were two implementations, one in a form request and one in an action,
 * kept in agreement by hand.
 *
 * Different callers ask different renderings, so a disagreement between them
 * does not show up as a failure anywhere. It shows up as a customer being
 * charged for something the rules were supposed to refuse, or as a 500 where a
 * 422 was owed.
 *
 * **What is actually being pinned, said plainly.** Inside the module the two
 * renderings cannot come apart: `permits` is `refusals` being empty, so
 * asserting those two agree proves only that they are one list. The gap that
 * can still open is the one that was there before, between the two call sites:
 * a rule added to the validator without going through the declaration, or a
 * guard added to the action, and either of those fails this file. So the
 * agreement asserted here is between the till and the seam on real rows, with
 * the declaration's own predicate asked beside them as the third answer.
 *
 * The wording is not asserted. What a customer reads is pinned in
 * PlaceOrderTest, and a test that asserted both would fail twice for one
 * reason.
 */

/**
 * Everything the states below name, in one catalogue.
 *
 * Every product exists in every test, so a state is the basket rather than what
 * happens to be seeded, and `rush-24h` is deliberately absent because one state
 * is about a key nothing was seeded for.
 */
function aCatalogue(): void
{
    Product::factory()->create(['key' => 'one-card', 'is_published' => true]);
    Product::factory()->create(['key' => 'three-card', 'is_published' => false]);
    Product::factory()->create(['key' => 'month-ahead', 'is_published' => true, 'price_usd' => 0]);
    Product::factory()->pass()->create(['is_published' => true]);

    Product::factory()->create(['key' => 'in-depth', 'is_published' => true])->delete();
}

/**
 * One basket in a named state, so a failure says which basket broke rather than
 * which line.
 *
 * Named for what it is rather than for the rule it trips, which is what lets a
 * rule be renamed or split without renaming a state.
 *
 * @return array{0: string, 1: array<int, array<string, mixed>>}
 */
function aBasketThatIs(string $state): array
{
    return match ($state) {
        'a basket anybody can buy' => ['USD', [['product' => 'one-card']]],

        // Three baskets that are fine, and are here because a matrix of
        // nothing but broken states cannot notice a rule that refuses too
        // much. A rule added to one rendering and forgotten in the other only
        // shows up on a basket the other one lets through.
        'buying several of something you can buy several of' => ['USD', [
            ['product' => 'one-card', 'quantity' => 3],
        ]],
        'asking a question of something that takes one' => ['USD', [
            ['product' => 'one-card', 'question' => 'Will the move be right for me?'],
        ]],
        'buying a pass and a reading together' => ['USD', [
            ['product' => 'one-card'],
            ['product' => 'viewing-room-pass'],
        ]],
        'naming a product nobody seeded' => ['USD', [['product' => 'rush-24h']]],
        'naming something unpublished' => ['USD', [['product' => 'three-card']]],
        'naming something priced at nothing' => ['USD', [['product' => 'month-ahead']]],
        'naming something retired' => ['USD', [['product' => 'in-depth']]],

        // A currency the products table cannot price at all, which is what
        // "unpriced in the currency being charged" means in a system whose
        // prices are three NOT NULL columns.
        //
        // **The two sides agree here for different reasons, and that is worth
        // saying.** At the till the currency rule refuses first and `after()`
        // returns early, so `pricedInTheCurrencyCharged` is never reached; at
        // the seam there is no currency rule and that rule is what refuses.
        // This state pins that they agree, not that one rule did it. They
        // cannot be made to agree by one rule either, because a supported
        // currency always has a price, so this is the only shape the state has.
        'charged in a currency nothing is priced in' => ['JPY', [['product' => 'one-card']]],

        'asking a question of something that takes none' => ['USD', [
            ['product' => 'viewing-room-pass', 'question' => 'Why?'],
        ]],
        'holding a pass the buyer already has' => ['USD', [['product' => 'viewing-room-pass']]],
        'holding two passes on one line' => ['USD', [['product' => 'viewing-room-pass', 'quantity' => 2]]],
        'holding two passes across the basket' => ['USD', [
            ['product' => 'viewing-room-pass'],
            ['product' => 'viewing-room-pass'],
        ]],
    };
}

dataset('basket states', [
    'a basket anybody can buy',
    'buying several of something you can buy several of',
    'asking a question of something that takes one',
    'buying a pass and a reading together',
    'naming a product nobody seeded',
    'naming something unpublished',
    'naming something priced at nothing',
    'naming something retired',
    'charged in a currency nothing is priced in',
    'asking a question of something that takes none',
    'holding a pass the buyer already has',
    'holding two passes on one line',
    'holding two passes across the basket',
]);

/**
 * The three ways the pass rules resolve who is buying.
 *
 * **Not one case three times.** A signed in customer comes off the session, a
 * guest is matched on the address they typed, and a token holder comes off
 * neither: nothing has resolved the caller by the time a request is validated,
 * so a rule asking the default guard sees a token holder as a guest. That was a
 * real defect on this route on 13 August 2026, and a real token is minted here
 * rather than `Sanctum::actingAs` for the reason PlaceOrderTest gives, that the
 * helper swaps the guard for a fake one and would go on passing through it.
 *
 * **A guest here always has an account already**, because the row is what the
 * seam has to be handed and the pass states need one that holds a pass. So the
 * guest whose address the customers table has never seen is not in this matrix.
 * It is covered directly in PlaceOrderTest, by the test counting one lookup for
 * an address with no account, and it is named here so nobody reads three buyers
 * as four.
 *
 * @return array{0: Customer, 1: Closure(string, array<int, array<string, mixed>>): TestResponse}
 */
function aBuyerWhoIs(string $who, bool $holdsThePass): array
{
    $customer = $holdsThePass
        ? Customer::factory()->withViewingRoomAccess()->create()
        : Customer::factory()->create();

    $ask = match ($who) {
        'a guest' => fn (string $currency, array $lines) => test()->postJson('/api/v1/orders', [
            'name' => $customer->name,
            'email' => $customer->email,
            'currency' => $currency,
            'lines' => $lines,
        ]),
        'a signed in customer' => fn (string $currency, array $lines) => test()
            ->actingAs($customer, 'web')
            ->postJson('/api/v1/orders', ['currency' => $currency, 'lines' => $lines]),
        'somebody carrying a token' => fn (string $currency, array $lines) => test()
            ->withToken($customer->createToken('a native app')->plainTextToken)
            ->postJson('/api/v1/orders', ['currency' => $currency, 'lines' => $lines]),
    };

    return [$customer, $ask];
}

dataset('buyers', [
    'a guest',
    'a signed in customer',
    'somebody carrying a token',
]);

it('refuses at the till exactly what it refuses at the seam', function (string $state, string $who) {
    aCatalogue();

    [$customer, $ask] = aBuyerWhoIs($who, $state === 'holding a pass the buyer already has');
    [$currency, $lines] = aBasketThatIs($state);

    // What a customer meets. Anything other than a 422 means the order was
    // taken, whatever the rules were supposed to say about it.
    $refusedAtTheTill = $ask($currency, $lines)->getStatusCode() === 422;

    // What phase B's payment path will meet, since it does not route through
    // the form request. A basket that gets past this writes an order for money.
    try {
        app(PlaceOrder::class)($customer, $currency, $lines);
        $refusedAtTheSeam = false;
    } catch (InvalidArgumentException) {
        $refusedAtTheSeam = true;
    }

    expect($refusedAtTheSeam)->toBe(
        $refusedAtTheTill,
        "the till and the seam disagreed about a basket {$state}, for {$who}",
    );
})->with('basket states')->with('buyers');

/**
 * **This one is close to a tautology and is kept knowingly.** `permits` is
 * `refusals` being empty, so what it pins is that it stays that way: the day
 * somebody gives the predicate an implementation of its own, this is what
 * refuses it. The criterion about a rule reaching one rendering and not the
 * other is carried by the two tests either side of this, not by this one.
 */
it('says the same thing in the predicate as in the refusals', function (string $state, string $who) {
    aCatalogue();

    [$customer] = aBuyerWhoIs($who, $state === 'holding a pass the buyer already has');
    [$currency, $lines] = aBasketThatIs($state);

    $declaration = Purchasability::declared();
    $basket = Basket::forCustomer($customer, $currency, $lines);

    expect($declaration->refusals($basket) === [])->toBe(
        $declaration->permits($basket),
        "the declaration could not explain itself about a basket {$state}, for {$who}",
    );
})->with('basket states')->with('buyers');

it('resolves a guest through the address they typed, the way the till does', function (string $state) {
    aCatalogue();

    [$customer] = aBuyerWhoIs('a guest', $state === 'holding a pass the buyer already has');
    [$currency, $lines] = aBasketThatIs($state);

    // The two ways a basket knows who is buying, asked about one basket. The
    // till builds a guest's basket from an address and the seam builds one from
    // the account that address resolves to, so a difference between them is a
    // customer refused at one and charged at the other.
    expect(Purchasability::declared()->permits(Basket::forGuest($customer->email, $currency, $lines)))
        ->toBe(
            Purchasability::declared()->permits(Basket::forCustomer($customer, $currency, $lines)),
            "the basket disagreed with itself about who was buying, for a basket {$state}",
        );
})->with('basket states');

it('writes an order for exactly the baskets the declaration permits', function (string $state, string $who) {
    aCatalogue();

    [$customer, $ask] = aBuyerWhoIs($who, $state === 'holding a pass the buyer already has');
    [$currency, $lines] = aBasketThatIs($state);

    $permitted = Purchasability::declared()->permits(Basket::forCustomer($customer, $currency, $lines));

    $ask($currency, $lines);

    // The consequence the whole declaration exists for. A refusal that does not
    // reach the till is somebody charged for something the rules refused.
    expect(Order::count() > 0)->toBe(
        $permitted,
        "an order was written for a basket the declaration refuses, {$state}, for {$who}",
    );
})->with('basket states')->with('buyers');
