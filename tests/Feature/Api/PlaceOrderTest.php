<?php

use App\Actions\PlaceOrder;
use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\Payments\ManualSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost');
    Mail::fake();
});

function orderPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Jennifer Eisenberger',
        'email' => 'jennifer@example.com',
        'currency' => 'USD',
        'lines' => [['product' => 'one-card']],
    ], $overrides);
}

function sellable(array $attributes = []): Product
{
    return Product::factory()->create(array_merge([
        'key' => 'one-card',
        'is_published' => true,
        'price_usd' => 2500,
        'price_eur' => 2300,
        'price_gbp' => 1900,
    ], $attributes));
}

it('places an order at the price the product is today', function () {
    sellable();

    $response = $this->postJson('/api/v1/orders', orderPayload())
        ->assertCreated()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('currency', 'USD')
        ->assertJsonPath('total_amount', 2500)
        ->assertJsonPath('lines.0.product', 'one-card')
        ->assertJsonPath('lines.0.unit_amount', 2500);

    $order = Order::findOrFail($response->json('id'));

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->total_amount)->toBe(2500)
        ->and($order->lines)->toHaveCount(1);
});

it('charges in the currency asked for, and never re-detects it', function () {
    sellable();

    // A customer who chose GBP gets GBP, whatever their address suggests.
    $this->withHeader('CF-IPCountry', 'US')
        ->postJson('/api/v1/orders', orderPayload(['currency' => 'GBP']))
        ->assertCreated()
        ->assertJsonPath('currency', 'GBP')
        ->assertJsonPath('total_amount', 1900);
});

it('refuses a currency this system does not sell in', function () {
    sellable();

    // Rather than falling back, which would charge somebody a number from the
    // wrong currency.
    $this->postJson('/api/v1/orders', orderPayload(['currency' => 'JPY']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('currency');
});

it('adds a basket up across several lines', function () {
    sellable();
    Product::factory()->create(['key' => 'three-card', 'is_published' => true, 'price_usd' => 4000]);

    $this->postJson('/api/v1/orders', orderPayload([
        'lines' => [
            ['product' => 'one-card', 'quantity' => 2],
            ['product' => 'three-card'],
        ],
    ]))
        ->assertCreated()
        ->assertJsonPath('total_amount', 2500 * 2 + 4000);
});

it('refuses the whole order when one line is wrong', function () {
    sellable();
    Product::factory()->create(['key' => 'three-card', 'is_published' => false]);

    // Dropping the bad line silently would charge somebody for a basket they
    // never agreed to.
    $this->postJson('/api/v1/orders', orderPayload([
        'lines' => [
            ['product' => 'one-card'],
            ['product' => 'three-card'],
        ],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines.1.product');

    expect(Order::count())->toBe(0);
});

it('cannot buy something unpublished, unpriced, or retired', function (callable $make) {
    $make();

    $this->postJson('/api/v1/orders', orderPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.product');

    expect(Order::count())->toBe(0);
})->with([
    'unpublished' => fn () => fn () => sellable(['is_published' => false]),
    'priced at nothing' => fn () => fn () => sellable(['price_usd' => 0]),
    'retired' => fn () => fn () => sellable()->delete(),
    'never existed' => fn () => fn () => null,
]);

it('takes a question on a reading and refuses one on the pass', function () {
    sellable();
    Product::factory()->pass()->create(['key' => 'viewing-room-pass', 'price_usd' => 9900]);

    $this->postJson('/api/v1/orders', orderPayload([
        'lines' => [['product' => 'one-card', 'question' => 'Will the move be right for me?']],
    ]))->assertCreated()->assertJsonPath('lines.0.question', 'Will the move be right for me?');

    // A pass has nothing to ask about, and allows_question says so.
    $this->postJson('/api/v1/orders', orderPayload([
        'lines' => [['product' => 'viewing-room-pass', 'question' => 'Why?']],
    ]))->assertStatus(422)->assertJsonValidationErrors('lines.0.question');
});

/*
| Who the order belongs to.
*/

it('makes an unclaimed account for a buyer who has none', function () {
    sellable();

    $this->postJson('/api/v1/orders', orderPayload())->assertCreated();

    $customer = Customer::firstWhere('email', 'jennifer@example.com');

    expect($customer)->not->toBeNull()
        ->and($customer->name)->toBe('Jennifer Eisenberger')
        // No password until they claim it, and nothing can sign in to it.
        ->and($customer->password)->toBeNull()
        ->and($customer->orders)->toHaveCount(1);
});

it('attaches to the account an email already has, making no second row', function () {
    sellable();
    $existing = Customer::factory()->create(['email' => 'jennifer@example.com']);

    $this->postJson('/api/v1/orders', orderPayload())->assertCreated();
    $this->postJson('/api/v1/orders', orderPayload())->assertCreated();

    expect(Customer::where('email', 'jennifer@example.com')->count())->toBe(1)
        ->and(Order::where('customer_id', $existing->id)->count())->toBe(2)
        // Their password is untouched. Ordering must never disturb an account.
        ->and($existing->fresh()->password)->toBe($existing->password);
});

it('treats an address as the same account whatever case it arrives in', function () {
    sellable();
    Customer::factory()->create(['email' => 'jennifer@example.com']);

    $this->postJson('/api/v1/orders', orderPayload(['email' => 'JENNIFER@Example.com']))
        ->assertCreated();

    expect(Customer::count())->toBe(1);
});

it('uses the signed in customer and ignores whatever email was typed', function () {
    sellable();
    $signedIn = Customer::factory()->create(['email' => 'real@example.com']);

    $this->actingAs($signedIn, 'web')
        ->postJson('/api/v1/orders', orderPayload(['email' => 'someone-else@example.com']))
        ->assertCreated();

    // Nobody attaches an order to another person while authenticated as
    // themselves.
    expect(Order::first()->customer_id)->toBe($signedIn->id)
        ->and(Customer::where('email', 'someone-else@example.com')->exists())->toBeFalse();
});

it('needs a name and an email from somebody who is not signed in', function () {
    sellable();

    $this->postJson('/api/v1/orders', ['currency' => 'USD', 'lines' => [['product' => 'one-card']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'email']);
});

it('needs neither from somebody who is', function () {
    sellable();

    $this->actingAs(Customer::factory()->create(), 'web')
        ->postJson('/api/v1/orders', ['currency' => 'USD', 'lines' => [['product' => 'one-card']]])
        ->assertCreated();
});

/*
| The same two rules again, for somebody carrying a bearer token.
|
| **Not a duplicate of the two above, and the difference is the whole point.**
| Those sign in through the `web` guard, which is what a cookie uses. This route
| carries no auth middleware, so nothing resolves the caller before the request
| is validated, and a bare `user()` asks the default guard alone. A native app
| holding a perfectly good token was therefore a guest here.
|
| A real token is minted and sent as a header rather than using
| `Sanctum::actingAs`, because that helper swaps the guard for a fake one and
| would have gone on passing through the whole bug. The proof has to travel the
| channel a real caller travels, which is the standing lesson of this project.
*/

function tokenFor(Customer $customer): string
{
    return $customer->createToken('a native app')->plainTextToken;
}

it('uses the customer behind a bearer token and ignores whatever email was typed', function () {
    sellable();
    $signedIn = Customer::factory()->create(['email' => 'real@example.com']);

    $this->withToken(tokenFor($signedIn))
        ->postJson('/api/v1/orders', orderPayload(['email' => 'someone-else@example.com']))
        ->assertCreated();

    // The protection the docblock promises, proved on the path that did not
    // have it: an order could be attached to any address the caller typed,
    // while they were signed in as themselves.
    expect(Order::first()->customer_id)->toBe($signedIn->id)
        ->and(Customer::where('email', 'someone-else@example.com')->exists())->toBeFalse();
});

it('needs no name or email from somebody carrying a token', function () {
    sellable();

    $this->withToken(tokenFor(Customer::factory()->create()))
        ->postJson('/api/v1/orders', ['currency' => 'USD', 'lines' => [['product' => 'one-card']]])
        ->assertCreated();
});

/*
| Placing an order is silent, and the gateway is swappable.
*/

it('sends nothing at all when an order is placed', function () {
    sellable();

    $this->postJson('/api/v1/orders', orderPayload())->assertCreated();

    // Anybody can type any address into a checkout. If placing an order sent
    // mail, this endpoint is a free email bomber aimed at whoever the sender
    // names. The invitation to set a password goes out when it is paid.
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

it('leaves the customer nowhere to go, because nothing is collected yet', function () {
    sellable();

    $this->postJson('/api/v1/orders', orderPayload())
        ->assertCreated()
        ->assertJsonPath('payment_url', null);
});

it('resolves the gateway from config, so naming another one changes what happens', function () {
    expect(app(PaymentGateway::class))->toBeInstanceOf(ManualSettlement::class);

    config()->set('payments.gateway', new class implements PaymentGateway
    {
        public function begin(Order $order): ?string
        {
            return 'https://pay.example.test/'.$order->id;
        }
    }::class);

    sellable();

    // A fake gateway proves this caller and proves nothing about any real one,
    // per .claude/external-services.md.
    $this->postJson('/api/v1/orders', orderPayload())
        ->assertCreated()
        ->assertJsonPath('payment_url', fn (?string $url): bool => str_starts_with((string) $url, 'https://pay.example.test/'));
});

it('refuses to be cached', function () {
    sellable();

    $this->postJson('/api/v1/orders', orderPayload())
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('refuses an empty basket', function () {
    sellable();

    $this->postJson('/api/v1/orders', orderPayload(['lines' => []]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines');
});

it('refuses to price a line whose product does not exist', function () {
    // **The form request stops this reaching here, and that stays true.** The
    // guard exists because PlaceOrder is the shared seam: phase B adds a
    // payment path that does not route through StoreOrderRequest, and the
    // failure without it was a TypeError on null in the one action that turns
    // money into a record.
    expect(fn () => app(PlaceOrder::class)(
        Customer::factory()->create(),
        'USD',
        [['product' => 'a-product-that-was-never-seeded', 'quantity' => 1]],
    ))->toThrow(InvalidArgumentException::class, 'a-product-that-was-never-seeded');

    expect(Order::count())->toBe(0);
});

it('refuses to price a line in a currency the product has no price in', function () {
    $product = Product::factory()->create();

    // Prices are three NOT NULL columns, so this cannot come from the
    // catalogue. It comes from a caller naming a currency we do not sell in,
    // where priceIn returns null and the old code wrote it into an unsigned
    // integer column.
    expect(fn () => app(PlaceOrder::class)(
        Customer::factory()->create(),
        'JPY',
        [['product' => $product->key->value, 'quantity' => 1]],
    ))->toThrow(InvalidArgumentException::class, 'JPY');

    expect(Order::count())->toBe(0);
});
