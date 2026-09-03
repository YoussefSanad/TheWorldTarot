<?php

use App\Enums\ProductKey;
use App\Models\Gift;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost');
    Mail::fake();
});

/**
 * Buying a line for somebody else.
 *
 * **This unit takes the gift and refuses it in the wrong place. It does not
 * store one.** B5 lands the `gifts` table, the catalogue fact and the refusal;
 * the row is written and the code minted at settlement, which is B6's. So a
 * gift accepted here leaves no gift row behind yet, and the test below says so
 * rather than leaving it to be discovered.
 *
 * `docs/adr/0004-a-reading-is-a-row-of-its-own.md` for why a gift is a line
 * bought for somebody else rather than a second order, and `API_CONTRACT.md`
 * section 2 for the object's four fields.
 */
function aGift(array $overrides = []): array
{
    return array_merge([
        'recipient_email' => 'sam@example.com',
        'signature' => 'Mum',
        'message' => 'Thought of you.',
    ], $overrides);
}

function aGiftableCatalogue(): void
{
    Product::factory()->create(['key' => ProductKey::OneCard, 'is_published' => true]);
    Product::factory()->create(['key' => ProductKey::MonthAhead, 'is_published' => true]);
    Product::factory()->pass()->create(['is_published' => true]);
}

function aBasketHolding(array $lines): array
{
    return ['name' => 'Jennifer Eisenberger', 'email' => 'jennifer@example.com', 'currency' => 'USD', 'lines' => $lines];
}

/*
| What the catalogue says may be given away.
*/

it('names three readings that may be gifted, and three things that may not', function () {
    // **The list, not a derivation of it.** Written out here from
    // `docs/CHECKPOINT.md` and `API_CONTRACT.md:114` rather than computed the
    // way the enum computes it, so this disagrees with the code the day
    // somebody changes one and not the other.
    expect(ProductKey::ThreeCard->isGiftable())->toBeTrue()
        ->and(ProductKey::MonthAhead->isGiftable())->toBeTrue()
        ->and(ProductKey::InDepth->isGiftable())->toBeTrue();

    // One card is the product that proves the two fields are independent: it
    // takes a question and cannot be gifted. A pass and an add-on are not
    // readings, and there is nothing to give.
    expect(ProductKey::OneCard->isGiftable())->toBeFalse()
        ->and(ProductKey::ViewingRoomPass->isGiftable())->toBeFalse()
        ->and(ProductKey::Rush24h->isGiftable())->toBeFalse();
});

it('is a different question from whether a question may be asked', function () {
    // Both fields exist because neither answers the other, and `one-card` is
    // where they part company. A test that only ever saw them agree would not
    // notice one being implemented in terms of the other.
    expect(ProductKey::OneCard->allowsQuestion())->toBeTrue()
        ->and(ProductKey::OneCard->isGiftable())->toBeFalse();
});

/*
| The refusal, which is the gate on this unit.
*/

it('refuses a gift on a reading that cannot be given away', function () {
    aGiftableCatalogue();

    $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'one-card', 'gift' => aGift()],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.0.gift' => 'This one cannot be given as a gift.']);

    // **Refused rather than quietly ignored.** Taking the order and dropping
    // the gift charges somebody for a present that was never sent.
    expect(Order::count())->toBe(0);
});

it('refuses a gift on the viewing room pass', function () {
    aGiftableCatalogue();

    $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'viewing-room-pass', 'gift' => aGift()],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.gift');

    expect(Order::count())->toBe(0);
});

it('names the line the gift was wrong on, and not the order', function () {
    aGiftableCatalogue();

    // Third line, so the key cannot pass by holding a zero.
    $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'month-ahead'],
        ['product' => 'viewing-room-pass'],
        ['product' => 'one-card', 'gift' => aGift()],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines.2.gift')
        ->assertJsonMissingValidationErrors(['lines.0.gift', 'lines.1.gift']);
});

/*
| And what it takes.
*/

it('takes a gift on a reading that may be given away', function () {
    aGiftableCatalogue();

    $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'month-ahead', 'gift' => aGift()],
    ]))->assertCreated();

    expect(Order::count())->toBe(1);
});

it('takes a gift and a self purchase on one order', function () {
    aGiftableCatalogue();

    // A basket may hold both, `API_CONTRACT.md:793`. Nothing about a gift line
    // refuses the line beside it.
    $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'one-card', 'question' => 'What should I focus on?'],
        ['product' => 'month-ahead', 'gift' => aGift()],
    ]))->assertCreated();

    expect(Order::count())->toBe(1);
});

it('stores the gift when the order is placed, and no code with it', function () {
    aGiftableCatalogue();

    $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'month-ahead', 'gift' => aGift()],
    ]))->assertCreated();

    $gift = Gift::sole();

    // **The row at placement, the code at settlement, and the split is the
    // decision this unit had to make.** The buyer's four fields are typed
    // before the money arrives and have to survive until it does, exactly as
    // `order_lines.question` does and for the same reason. The code cannot
    // travel with them: a code minted at placement is a live bearer credential
    // backed by a payment that may never arrive, one per abandoned checkout,
    // which is what B6 mints at settlement to avoid. A codeless row is inert.
    expect($gift->code)->toBeNull()
        ->and($gift->recipient_email)->toBe('sam@example.com')
        ->and($gift->signature)->toBe('Mum')
        ->and($gift->message)->toBe('Thought of you.')
        ->and($gift->redeemed_at)->toBeNull();
});

it('hangs the gift off the line it was bought on', function () {
    aGiftableCatalogue();

    $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'one-card', 'question' => 'What should I focus on?'],
        ['product' => 'month-ahead', 'gift' => aGift()],
    ]))->assertCreated();

    // The second line, not the first. A gift belongs to one line at one price,
    // and an order holding a gift beside a self purchase is the case where
    // getting that wrong sends the wrong reading to the wrong person.
    $gifted = Order::sole()->lines()->where('product_key', 'month-ahead')->sole();

    expect(Gift::sole()->order_line_id)->toBe($gifted->id);
});

it('writes no gift for a line nobody gifted', function () {
    aGiftableCatalogue();

    $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'month-ahead', 'question' => 'What should I focus on?'],
    ]))->assertCreated();

    // Null on a self purchase is the whole of ADR 0004's `gift_id`, and the
    // same fact from the other end: a line nobody gifted has no gift row.
    expect(Gift::count())->toBe(0);
});

it('writes no gift at all when one line of the basket is refused', function () {
    aGiftableCatalogue();

    $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'month-ahead', 'gift' => aGift()],
        ['product' => 'nothing-we-sell'],
    ]))->assertStatus(422);

    // One bad line refuses the whole order, and the gift is written inside the
    // same transaction as the order it belongs to. A gift row surviving a
    // refused basket would be a present hanging off a line that was never sold.
    expect(Gift::count())->toBe(0)
        ->and(Order::count())->toBe(0);
});

/*
| The object's own shape.
*/

it('needs an address to send the gift to', function () {
    aGiftableCatalogue();

    // The buyer never receives the code, so a missing address is unrecoverable
    // and nothing on this side can tell that a gift mail arrived nowhere.
    $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'month-ahead', 'gift' => aGift(['recipient_email' => null])],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.gift.recipient_email');
});

it('needs somebody to say it is from', function () {
    aGiftableCatalogue();

    // An unsolicited mail carrying a code and a link from a brand somebody has
    // never bought from is shaped exactly like phishing, and a named sender is
    // the strongest signal it is real.
    $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'month-ahead', 'gift' => aGift(['signature' => null])],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.gift.signature');
});

it('takes a gift with no message, which is the optional half', function () {
    aGiftableCatalogue();

    $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'month-ahead', 'gift' => aGift(['message' => null])],
    ]))->assertCreated();
});

it('refuses a locale nothing is published in', function () {
    aGiftableCatalogue();

    // The recipient's mail is rendered in it, so a language the site does not
    // have is a mail nobody can read.
    $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'month-ahead', 'gift' => aGift(['locale' => 'qq'])],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines.0.gift.locale');
});

it('refuses an empty gift object on a reading that may be given away', function () {
    aGiftableCatalogue();

    // **`{}` names nobody.** A form that posts the key it has, with every
    // field inside it left alone, sends this. Letting it past means a giftable
    // product mints a gift row carrying no recipient address, and the buyer
    // never receives the code, so an unaddressed gift is unrecoverable.
    //
    // **What refuses it is not the rule it looks like.** `required_with` counts
    // an empty array as an absent sibling, so this passes both required fields
    // on its own. `StoreOrderRequest::fillInTheGiftLanguage` has put a locale
    // in the object by the time either is read, which is the whole of the
    // defence. These two tests are what say so out loud, and they go red the
    // day somebody moves that defaulting to the seam.
    $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'month-ahead', 'gift' => []],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines.0.gift.recipient_email', 'lines.0.gift.signature']);

    expect(Order::count())->toBe(0)
        ->and(Gift::count())->toBe(0);
});

it('refuses an empty gift object on a reading that may not', function () {
    aGiftableCatalogue();

    // The same body on `one-card`, which is the product the refusal above is
    // proved on. An empty object is still a gift somebody asked for, so the
    // line is refused for the product as well as for the two fields it never
    // named.
    $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'one-card', 'gift' => []],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'lines.0.gift' => 'This one cannot be given as a gift.',
            'lines.0.gift.recipient_email',
            'lines.0.gift.signature',
        ]);

    expect(Order::count())->toBe(0);
});

/*
| A question and a gift on one line, which is neither.
*/

it('refuses a question and a gift on one line', function () {
    aGiftableCatalogue();

    // `API_CONTRACT.md:428` has said so since 2 September 2026, and until
    // 3 September nothing refused it: `PlaceOrder` wrote null instead, so a
    // buyer's words were dropped and nobody was told. The person who will ask
    // has not seen the present yet, and asking is what redemption is for.
    $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'month-ahead', 'question' => 'What should I focus on?', 'gift' => aGift()],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'lines.0.question' => 'A present carries no question. Whoever receives it asks their own.',
        ]);

    // **Refused rather than quietly dropped**, which is the whole of the
    // decision. The line beside it in this contract, a gift on something that
    // may not be given away, is refused, and two neighbouring fields behaving
    // differently is what this closes.
    expect(Order::count())->toBe(0)
        ->and(Gift::count())->toBe(0);
});

it('names the line the question was wrong on, and not the order', function () {
    aGiftableCatalogue();

    // Third line, so the key cannot pass by holding a zero, and the two lines
    // above it are untouched by a fault on this one.
    $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'one-card', 'question' => 'What should I focus on?'],
        ['product' => 'month-ahead'],
        ['product' => 'month-ahead', 'question' => 'And after that?', 'gift' => aGift()],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('lines.2.question')
        ->assertJsonMissingValidationErrors(['lines.0.question', 'lines.1.question']);
});

it('still refuses a question on a product that takes none, before mentioning the gift', function () {
    aGiftableCatalogue();

    // Two rules hang on `question` and the pass trips both. **The product's own
    // fact wins**, because a customer who cannot ask this thing anything at all
    // does not need to hear a second reason keyed to the same field. That is
    // the order they are declared in, and it is the only thing deciding it.
    $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'viewing-room-pass', 'question' => 'Why?', 'gift' => aGift()],
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors([
            'lines.0.question' => 'This one does not take a question.',
        ]);
});

it('takes a gift on a line whose question is an empty string', function () {
    aGiftableCatalogue();

    // **An empty string is nothing asked, not a question**, the same reading
    // `prepareForValidation` already gives an empty email. A form that posts
    // every field it has sends `""` for the one the buyer left alone, and
    // refusing that would refuse an ordinary present for a field nobody filled
    // in.
    $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'month-ahead', 'question' => '', 'gift' => aGift()],
    ]))->assertCreated();

    // Stored as nothing rather than as an empty string, which is what
    // `PlaceOrder` drops on a gift line.
    expect(Order::sole()->lines()->sole()->question)->toBeNull();
});

/*
| What comes back, which is the only confirmation the buyer gets.
*/

it('echoes the gift back on the line it was bought on', function () {
    aGiftableCatalogue();

    // **The address is the one thing the buyer can check**, and they never
    // receive the code, so this is not a courtesy. Nothing on this side can
    // tell that a gift mail arrived nowhere.
    $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'one-card', 'question' => 'What should I focus on?'],
        ['product' => 'month-ahead', 'gift' => aGift()],
    ]))
        ->assertCreated()
        ->assertJsonPath('lines.1.gift', [
            'recipient_email' => 'sam@example.com',
            'signature' => 'Mum',
            'message' => 'Thought of you.',
            'locale' => 'en',
        ])

        // A line nobody gifted says so, rather than leaving the frontend to
        // tell a missing key from a null one.
        ->assertJsonPath('lines.0.gift', null)

        // A gift line carries no question. The person who will ask has not
        // seen it yet.
        ->assertJsonPath('lines.1.question', null);
});

it('never sends the code back when the order is placed', function () {
    aGiftableCatalogue();

    $response = $this->postJson('/api/v1/orders', aBasketHolding([
        ['product' => 'month-ahead', 'gift' => aGift()],
    ]))->assertCreated();

    // **It does not exist yet, and it never appears here even once it does.**
    // The buyer is not the person who spends it, and a code in a placement
    // response is a credential in the buyer's browser history for a payment
    // that has not happened.
    expect($response->json('lines.0.gift'))->toBeArray()
        ->and($response->json('lines.0.gift'))->not->toHaveKey('code')
        ->and($response->json('lines.0.gift.recipient_email'))->toBe('sam@example.com');
});
