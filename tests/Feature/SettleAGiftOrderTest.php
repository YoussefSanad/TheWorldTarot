<?php

use App\Actions\AskForReading;
use App\Actions\MarkOrderPaid;
use App\Enums\OrderStatus;
use App\Enums\ProductKey;
use App\Mail\GiftToRedeem;
use App\Mail\OrderPaid;
use App\Mail\ReadingToWrite;
use App\Models\Customer;
use App\Models\Gift;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reading;
use App\Payments\OrderBelongsToNobody;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
});

/*
| The code is minted at settlement, and two people are mailed.
|
| B6, issue #72. `docs/adr/0004-a-reading-is-a-row-of-its-own.md` decides that a
| reading exists from the moment somebody asks; the migration
| `database/migrations/2026_09_03_000000_a_gift_is_a_line_bought_for_somebody_else.php`
| decides that the gift row exists from placement and its code does not. This is
| the unit that fills the code in.
|
| **The trap this file exists to close.** `MarkOrderPaid::linesNeedingAPerson`
| filters on `Fulfillment::ManualPdf`, and a gifted `month-ahead` line is
| exactly that, so before this unit a settled gift order created a reading with
| the buyer as querent and put it on Jennifer's screen. A gift tells her nothing
| until it is redeemed.
|
| **Minting is at settlement and never at placement**, because a code minted at
| placement is a live bearer credential behind every abandoned checkout.
| `MarkOrderPaid` is the one seam both the webhook and a hand settlement reach,
| it is already locked, and it already survives redelivery, so minting there
| inherits all of it.
*/

/**
 * Settle an order the way both roads into payment do.
 */
function settlingAGift(Order $order): bool
{
    return app(MarkOrderPaid::class)($order);
}

/**
 * An order holding one reading bought for somebody else, with no code yet.
 *
 * **The gift row is written and its `code` is null**, which is what `PlaceOrder`
 * leaves behind and therefore what settlement actually receives. Building it
 * through the factory rather than through `PlaceOrder` keeps this file about
 * what settlement does with a gift, not about how one comes to exist.
 */
function anOrderGifting(array $gift = [], ?Customer $buyer = null): Order
{
    $order = Order::factory()
        ->for($buyer ?? Customer::factory()->create())
        ->forProduct(Product::factory()->create(['key' => ProductKey::MonthAhead]))
        ->create();

    Gift::factory()->unminted()->create([
        'order_line_id' => $order->lines->sole()->id,
        ...$gift,
    ]);

    return $order;
}

/*
| The code.
|
| **What a code is, is `tests/Unit/GiftCodeTest.php`**: the alphabet, the
| length, and the printed form, none of which need a database or a settled
| order to be wrong. `.claude/testing.md` names "Gift code generation, format,
| and redemption rules" as unit work. What is proved here is the half that only
| settlement can prove, which is when one comes into existence and what is done
| with it.
*/

it('mints a code when the money arrives and not before', function () {
    $order = anOrderGifting();

    expect($order->lines->sole()->gift->code)->toBeNull();

    settlingAGift($order);

    expect($order->lines()->first()->gift->isMinted())->toBeTrue();
});

it('mints a different code for every gift on one order', function () {
    $order = Order::factory()->create();

    foreach ([ProductKey::MonthAhead, ProductKey::ThreeCard] as $key) {
        $line = $order->lines()->create([
            'product_id' => Product::factory()->create(['key' => $key])->id,
            'product_key' => $key,
            'unit_amount' => 2500,
            'quantity' => 1,
        ]);

        Gift::factory()->unminted()->create(['order_line_id' => $line->id]);
    }

    settlingAGift($order);

    expect(Gift::pluck('code')->unique())->toHaveCount(2);
});

/*
| Running twice.
|
| Managed queues deliver at least once and `payment_intent.succeeded` is
| redelivered as a matter of routine. A second code for one payment is a second
| live bearer credential for one present.
*/

it('mints one code and not two when a payment is redelivered', function () {
    $order = anOrderGifting();

    expect(settlingAGift($order))->toBeTrue()
        ->and(settlingAGift($order))->toBeFalse()
        ->and(settlingAGift($order))->toBeFalse();

    $code = Gift::sole()->code;

    expect($code)->not->toBeNull();

    // Not merely one row. The row was already there at placement, so the
    // question is whether the second delivery rewrote the code somebody has
    // already been emailed.
    settlingAGift($order);

    expect(Gift::sole()->code)->toBe($code);

    Mail::assertQueued(GiftToRedeem::class, 1);
    Mail::assertQueued(OrderPaid::class, 1);
});

it('mints nothing for an order that was never pending', function () {
    $order = anOrderGifting();
    $order->forceFill(['status' => OrderStatus::Cancelled])->save();

    expect(settlingAGift($order))->toBeFalse()
        ->and(Gift::sole()->code)->toBeNull();

    Mail::assertNothingQueued();
});

/*
| A gift tells Jennifer nothing until it is redeemed.
|
| The trap this unit exists to avoid reopening.
*/

it('creates no reading, because nobody has asked for one', function () {
    settlingAGift(anOrderGifting());

    expect(Reading::count())->toBe(0);
});

it('sends no ReadingToWrite and reaches no work queue', function () {
    settlingAGift(anOrderGifting());

    Mail::assertNotQueued(ReadingToWrite::class);

    // Readings is the queue since B4, and an unredeemed gift has no row in it.
    // That is the whole of ADR 0006's argument for moving it: the condition
    // stops being a rule anybody has to remember and becomes a thing that does
    // not exist.
    expect(Reading::count())->toBe(0);
});

it('tells Jennifer about the reading only, on an order holding a gift and a self purchase', function () {
    $order = Order::factory()->create();

    $gifted = $order->lines()->create([
        'product_id' => Product::factory()->create(['key' => ProductKey::MonthAhead])->id,
        'product_key' => ProductKey::MonthAhead,
        'unit_amount' => 2500,
        'quantity' => 1,
    ]);

    $order->lines()->create([
        'product_id' => Product::factory()->create(['key' => ProductKey::ThreeCard])->id,
        'product_key' => ProductKey::ThreeCard,
        'unit_amount' => 2500,
        'quantity' => 1,
        'question' => 'Will the move be right for me?',
    ]);

    Gift::factory()->unminted()->create([
        'order_line_id' => $gifted->id,
        'recipient_email' => 'sam@example.com',
    ]);

    settlingAGift($order);

    expect(Reading::count())->toBe(1)
        ->and(Reading::sole()->question)->toBe('Will the move be right for me?');

    Mail::assertQueued(ReadingToWrite::class, function (ReadingToWrite $mail): bool {
        $body = $mail->render();

        // One mail per asking, and the asking is the self purchase. The gifted
        // line is not work: printing it here would put a job in front of her
        // that nobody has asked a question of and she cannot write.
        return str_contains($body, 'Will the move be right for me?')
            && ! str_contains($body, 'MONTH AHEAD')
            && ! str_contains($body, 'No question was asked.');
    });
});

it('is not delivered while a present nobody has redeemed is outstanding', function () {
    $order = anOrderGifting();

    settlingAGift($order);

    // An order holding an unredeemed gift is not delivered, because it plainly
    // is not. `Order::everythingItOwesHasGone` is where that is decided and B4
    // built it; this is the payment path learning to ask it.
    expect($order->refresh()->isFulfilled())->toBeFalse();
});

/*
| Two mails, and the buyer never receives the code.
*/

it('mails the recipient a link and the code, under the signature it was signed with', function () {
    config()->set('frontend.url', 'https://staging.theworldtarot.com');
    config()->set('mail.senders', [
        'support' => 'support@example.test',
        'noreply' => 'noreply@example.test',
        'fulfillment' => 'fulfillment@example.test',
    ]);

    settlingAGift(anOrderGifting([
        'recipient_email' => 'sam@example.com',
        'signature' => 'Mum',
        'message' => 'Thought of you the moment I saw this.',
    ]));

    $printed = Gift::sole()->printedCode();

    Mail::assertQueued(GiftToRedeem::class, function (GiftToRedeem $mail) use ($printed): bool {
        $body = $mail->render();

        // **The signature is in the subject** because an unsolicited mail
        // carrying a code and a link from a brand somebody has never bought
        // from is shaped exactly like phishing, and a named sender is the
        // strongest signal it is real.
        return $mail->hasTo('sam@example.com')
            && $mail->hasFrom('noreply@example.test')
            && str_contains((string) $mail->envelope()->subject, 'Mum')
            && str_contains($body, 'https://staging.theworldtarot.com/redeem/?code='.$printed)
            // Printed underneath, for somebody typing it off the mail.
            && str_contains($body, $printed)
            && str_contains($body, 'Thought of you the moment I saw this.');
    });
});

it('never sends the recipient the buyer address, and never sends the buyer the code', function () {
    $buyer = Customer::factory()->create(['email' => 'jodie@example.com']);

    settlingAGift(anOrderGifting(['recipient_email' => 'sam@example.com'], $buyer));

    $code = (string) Gift::sole()->code;

    Mail::assertQueued(OrderPaid::class, function (OrderPaid $mail) use ($code): bool {
        $body = $mail->render().' '.view('mail.order-paid', $mail->buildViewData())->render();

        // **The buyer never receives the code**, decided 2 September 2026. The
        // recipient's address is then the only thing they can check, which is
        // why it is on the receipt at all.
        return $mail->hasTo('jodie@example.com')
            && str_contains($body, 'sam@example.com')
            && ! str_contains($body, $code);
    });
});

it('puts the recipient address where the delivery promise goes, not in a footnote', function () {
    settlingAGift(anOrderGifting(['recipient_email' => 'sam@example.com']));

    Mail::assertQueued(OrderPaid::class, function (OrderPaid $mail): bool {
        $html = $mail->render();

        // A gift is not on its way to the buyer, so the delivery line that
        // says it is has no business on this receipt. This is the same defect
        // the heading below already had for a Viewing Room pass.
        return str_contains($html, 'sam@example.com')
            && ! str_contains($html, 'Delivery within');
    });
});

it('does not tell a gift buyer their own reading is on its way', function () {
    settlingAGift(anOrderGifting());

    Mail::assertQueued(OrderPaid::class, function (OrderPaid $mail): bool {
        $html = $mail->render();

        // Nothing is being written for them. The fallback is the subject line
        // this message already carries, so it is copy that has been signed off.
        return str_contains($html, 'Your Order')
            && ! str_contains($html, 'Your Reading');
    });
});

it('renders the recipient mail in the language the gift was bought in', function () {
    settlingAGift(anOrderGifting(['locale' => 'es']));

    // **A property of the gift rather than of the buyer**, because the person
    // it is sent to never chose it and an order carries no language of its own.
    Mail::assertQueued(
        GiftToRedeem::class,
        fn (GiftToRedeem $mail): bool => $mail->locale === 'es',
    );
});

/*
| Mail is queued after the commit, never inside the transaction.
|
| A message sent inside a transaction that then rolls back has still been sent,
| and no amount of rolling back unsends it.
*/

it('queues both mails rather than sending either one while the order is being settled', function () {
    settlingAGift(anOrderGifting());

    // **Nothing here holds on its own and that is worth saying.** `QueuedMail`
    // makes every message in this application `ShouldQueue`, so this pair
    // passes wherever the `send()` sits. It is the near assertion; the one
    // below is the one that can go red.
    Mail::assertNothingSent();
    Mail::assertQueued(GiftToRedeem::class);
    Mail::assertQueued(OrderPaid::class);
});

it('queues no mail and keeps no code when the transaction it minted in rolls back', function () {
    $order = Order::factory()->create();

    $gifted = $order->lines()->create([
        'product_id' => Product::factory()->create(['key' => ProductKey::MonthAhead])->id,
        'product_key' => ProductKey::MonthAhead,
        'unit_amount' => 2500,
        'quantity' => 1,
    ]);

    $order->lines()->create([
        'product_id' => Product::factory()->create(['key' => ProductKey::ThreeCard])->id,
        'product_key' => ProductKey::ThreeCard,
        'unit_amount' => 2500,
        'quantity' => 1,
        'question' => 'Will the move be right for me?',
    ]);

    Gift::factory()->unminted()->create(['order_line_id' => $gifted->id]);

    // **A failure after the mint and inside the transaction**, which is the one
    // arrangement that can tell the two placements apart. `AskForReading` runs
    // between minting and the commit, so an action that throws there leaves a
    // settlement that got as far as writing a code and then lost it.
    //
    // A mail sent from inside `fulfill()` would already be queued at this
    // point and would stay queued, because no amount of rolling back unsends a
    // message. Sent from `tell()` it never happens at all, since `tell()` is
    // reached only after `DB::transaction` returns. **That is the whole
    // difference and it is not observable any other way**: the assertions in
    // the case above pass under both placements.
    app()->instance(AskForReading::class, new class extends AskForReading
    {
        public function __invoke(Collection $lines, string $querentEmail, ?string $querentName = null): Collection
        {
            throw new RuntimeException('the asking failed');
        }
    });

    expect(fn () => settlingAGift($order))->toThrow(RuntimeException::class);

    expect($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and(Gift::sole()->code)->toBeNull();

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

it('mails nobody and mints nothing when the settlement itself fails', function () {
    $order = anOrderGifting();
    $order->forceFill(['customer_id' => null])->save();

    // The one failure reachable inside that transaction: an order nobody owns
    // cannot be settled, and it throws rather than spending the transition on
    // nobody. What this proves is where the mail sits relative to it.
    expect(fn () => settlingAGift($order))->toThrow(OrderBelongsToNobody::class);

    expect(Gift::sole()->code)->toBeNull();

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});
