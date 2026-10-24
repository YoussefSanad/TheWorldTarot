<?php

use App\Enums\ProductKey;
use App\Mail\OrderPaid;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Support\FrontendUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
| The designed confirmation, issue #53.
|
| **This is the day `QueuedMail` and `MailBodyTest` both said would come.** Every
| message in this application was text, every view interpolated with `{!! !!}`,
| and both of those files say in as many words that HTML is the one change that
| has to revisit the rule before making it. So it is revisited rather than
| removed: the confirmation is multipart now, the text part is the one that
| shipped before and still echoes raw, and the HTML part escapes. `MailBodyTest`
| holds that split structurally. This file holds what the HTML part says.
|
| **The design supplied three files, and they differ in five strings**: the
| title, the product name, the subtitle, the price and the delivery line.
| Everything else, the gold frame, the fonts, the dark-mode restatements and the
| Outlook fallbacks, is identical across all three. So there is one view and
| the five strings come from the order, which is what the "fill them with real
| data" item on #53 asks for.
|
| **The prices in the supplied files are decoration and are not asserted
| against.** `in-depth` is drawn at $125 and the catalogue's starting price is
| $120. A receipt states what was charged, so the number rendered is
| `unit_amount` off the line, captured at purchase, and never the catalogue's
| price and never the mockup's.
|
| `Mail::fake()` must never be set here, for `MailBodyTest`'s reason: a fake
| replaces the transport, and there is then no rendered message to read.
*/

/**
 * The HTML part of the one sent message, which is the half under test.
 *
 * **Read off the transport rather than off `$mail->render()`.** `render()`
 * answers the HTML part alone and would pass just as happily if the text part
 * had been dropped on the way out, which is the regression that would undo the
 * whole of #45's fix.
 */
function htmlBodySent(): string
{
    $messages = app('mailer')->getSymfonyTransport()->messages()->all();

    expect($messages)->toHaveCount(1, 'Expected exactly one sent message');

    return (string) $messages[0]->getOriginalMessage()->getHtmlBody();
}

/**
 * And the text part of that same message, which has to still be there.
 */
function textBodySent(): string
{
    $messages = app('mailer')->getSymfonyTransport()->messages()->all();

    return (string) $messages[0]->getOriginalMessage()->getTextBody();
}

/**
 * An order for one reading of a named kind, at a price this test chose.
 *
 * The price is passed rather than defaulted because what a receipt says it cost
 * is most of what these assertions are about.
 */
function paidOrderFor(ProductKey $key, int $unitAmount, ?string $question = null, ?string $name = 'Miss Jodie O\'Reilly'): Order
{
    return Order::factory()
        ->for(Customer::factory()->create(['name' => $name, 'email' => 'jodie@example.com', 'password' => null]))
        ->forProduct(Product::factory()->create([
            'key' => $key,
            'name' => ['en' => $key->defaultName()],
            'price_usd' => $unitAmount,
        ]), 1, $question)
        ->create();
}

it('sends the confirmation as both an HTML part and the text part that shipped before', function () {
    $order = paidOrderFor(ProductKey::MonthAhead, 7500);

    Mail::to($order->customer->email)->send(new OrderPaid($order));

    // **Both halves, and the text one is not incidental.** A client that will
    // not render HTML, and a spam filter that scores a message with no
    // alternative part, are the two reasons this is multipart rather than the
    // HTML alone. The text part is the body #45 fixed, unchanged.
    expect(htmlBodySent())->toContain('<html')
        ->and(textBodySent())->toContain('Thank you, your payment has gone through.');
});

it('escapes a customer name in the HTML part, which is the opposite of what the text part does', function () {
    $order = paidOrderFor(ProductKey::ThreeCard, 5200);

    Mail::to($order->customer->email)->send(new OrderPaid($order));

    // **The inversion is the point of #53's risk.** In a text body an escape is
    // corruption and #45 is what it cost. In a document rendered as markup an
    // unescaped apostrophe is merely wrong and an unescaped `<` from a
    // customer's own typing is an injection. Same value, same message, opposite
    // correct answers, so both are asserted in one place.
    expect(htmlBodySent())->toContain('Miss Jodie O&#039;Reilly')
        ->and(htmlBodySent())->not->toContain("Miss Jodie O'Reilly")
        ->and(textBodySent())->toContain("Miss Jodie O'Reilly")
        ->and(textBodySent())->not->toContain('&#039;');
});

it('escapes a question the customer typed, markup and all', function () {
    $order = paidOrderFor(ProductKey::InDepth, 12000, 'What of Jodie\'s move & the <plan>?');

    Mail::to($order->customer->email)->send(new OrderPaid($order));

    // The customer's own prose is the likeliest place in any of these messages
    // to carry a character that closes a tag.
    expect(htmlBodySent())->toContain('&lt;plan&gt;')
        ->and(htmlBodySent())->not->toContain('<plan>');
});

it('prints the price the customer was actually charged, not the one drawn in the mockup', function () {
    // $125 is what the supplied in-depth file draws. 12000 is the catalogue's
    // starting price. Neither is authority over a receipt: the line is.
    $order = paidOrderFor(ProductKey::InDepth, 11900);

    Mail::to($order->customer->email)->send(new OrderPaid($order));

    expect(htmlBodySent())->toContain('119.00')
        ->and(htmlBodySent())->not->toContain('$125')
        ->and(htmlBodySent())->not->toContain('$120');
});

it('names the product, its subtitle and its delivery window from the catalogue', function () {
    $order = paidOrderFor(ProductKey::MonthAhead, 7500);

    Mail::to($order->customer->email)->send(new OrderPaid($order));

    expect(htmlBodySent())->toContain('MONTH AHEAD')
        ->and(htmlBodySent())->toContain('A Clear Path Forward')
        ->and(htmlBodySent())->toContain('Delivery within 24 hours');
});

it('gives the in-depth reading its own subtitle and its longer delivery window', function () {
    $order = paidOrderFor(ProductKey::InDepth, 12000);

    Mail::to($order->customer->email)->send(new OrderPaid($order));

    expect(htmlBodySent())->toContain('The Full Picture')
        ->and(htmlBodySent())->toContain('Delivery within 48 hours');
});

it('repeats the box for every line and totals an order carrying more than one', function () {
    $customer = Customer::factory()->create(['name' => 'Jennifer', 'email' => 'jennifer@example.com']);

    // **`name` has to be set alongside `key`.** `ProductFactory` cycles through
    // the readings and derives its default name from whichever one it picked,
    // not from an overridden key, so a product asked for by key alone is named
    // after a different reading entirely.
    $order = Order::factory()
        ->for($customer)
        ->forProduct(Product::factory()->create([
            'key' => ProductKey::ThreeCard,
            'name' => ['en' => ProductKey::ThreeCard->defaultName()],
            'price_usd' => 5200,
        ]), 1)
        ->forProduct(Product::factory()->create([
            'key' => ProductKey::InDepth,
            'name' => ['en' => ProductKey::InDepth->defaultName()],
            'price_usd' => 12000,
        ]), 2)
        ->create();

    Mail::to($customer->email)->send(new OrderPaid($order));

    $html = htmlBodySent();

    // **The design draws one product and an order carries up to ten.** Both
    // boxes, the quantity that is not one, and a total, because a receipt for
    // three readings that shows the price of one is wrong in the direction that
    // costs money.
    // 5200 once and 12000 twice, so the boxes read $52.00 and $240.00 and the
    // total is $292.00.
    expect($html)->toContain('3 CARD READING')
        ->and($html)->toContain('IN DEPTH READING')
        ->and($html)->toContain('&times;2')
        ->and($html)->toContain('$52.00')
        ->and($html)->toContain('$240.00')
        ->and($html)->toContain('Total')
        ->and($html)->toContain('$292.00');
});

it('shows no total on a single line order, where it would only repeat the price', function () {
    $order = paidOrderFor(ProductKey::ThreeCard, 5200);

    Mail::to($order->customer->email)->send(new OrderPaid($order));

    // **Anchored on the product, because `not->toContain` passes on an empty
    // body.** Without the first line this test was green before the view
    // existed, which is the shape section 0.5 warns about.
    expect(htmlBodySent())->toContain('3 CARD READING')
        ->and(htmlBodySent())->not->toContain('Total');
});

it('greets by name in the HTML part too', function () {
    $order = paidOrderFor(ProductKey::OneCard, 1200);

    Mail::to($order->customer->email)->send(new OrderPaid($order));

    expect(htmlBodySent())->toContain('Hello Miss Jodie O&#039;Reilly,');
});

it('opens the HTML part without a name rather than inventing one', function () {
    // Issue #52's rule reaches the designed message too. Its own test asserts
    // this against the text part, which until today was the whole of what was
    // sent, so the half a customer actually looks at was unproved.
    $order = paidOrderFor(ProductKey::OneCard, 1200, name: null);

    Mail::to($order->customer->email)->send(new OrderPaid($order));

    expect(htmlBodySent())->toContain('Hello,')
        // The two ways this has shipped wrong before: the empty interpolation,
        // and a form of address invented from an email address.
        ->and(htmlBodySent())->not->toContain('Hello ,')
        ->and(htmlBodySent())->not->toContain('jodie,');
});

it('loads every image from the configured host and never from the preview one', function () {
    config()->set('mail.assets.url', 'https://cdn.example.com/email');

    $order = paidOrderFor(ProductKey::MonthAhead, 7500);

    Mail::to($order->customer->email)->send(new OrderPaid($order));

    $html = htmlBodySent();

    // **The one thing the designer's README says to do before integrating.**
    // Left alone, every ornament in every receipt this business sends is served
    // by somebody's temporary Netlify preview, which is a dependency nobody
    // agreed to and which will one day answer 404 into a paying customer's
    // inbox.
    expect($html)->not->toContain('theworldtarot-emails.netlify.app')
        ->and($html)->toContain('https://cdn.example.com/email/band-top.png')
        ->and($html)->toContain('https://cdn.example.com/email/band-top@2x.png 2x');

    // Every src and srcset in the document, so a single missed find-and-replace
    // fails here rather than in an inbox.
    preg_match_all('/(?:src|srcset)="([^"]+)"/', $html, $found);

    expect($found[1])->not->toBeEmpty();

    foreach ($found[1] as $reference) {
        expect($reference)->toStartWith('https://cdn.example.com/email/');
    }
});

it('ships every image the view asks for', function () {
    config()->set('mail.assets.url', 'https://cdn.example.com/email');

    $order = paidOrderFor(ProductKey::MonthAhead, 7500);

    Mail::to($order->customer->email)->send(new OrderPaid($order));

    preg_match_all('/(?:src|srcset)="([^"]+)"/', htmlBodySent(), $found);

    // **A loop over nothing is green**, so the count is pinned before it runs.
    // Ten images, each referenced at 1x and again at 2x through `srcset`. The
    // tenth is the wordmark, which was type until the frontend's own artwork
    // replaced it.
    $files = array_unique(array_map(
        fn (string $reference): string => basename((string) strtok($reference, ' ')),
        $found[1],
    ));

    expect($files)->toHaveCount(20);

    // **An email cannot fall back to a missing image the way a page can.** A
    // referenced file that was never committed is a gap in the gold frame in
    // every client, and nothing else in this suite would notice.
    foreach ($files as $file) {
        expect(file_exists(public_path('email/'.$file)))
            ->toBeTrue("{$file} is referenced by the view and is not in the repository");
    }
});

it('confirms a Viewing Room pass without promising to deliver it', function () {
    $customer = Customer::factory()->create(['name' => 'Jennifer', 'email' => 'jennifer@example.com']);

    $order = Order::factory()
        ->for($customer)
        ->forProduct(Product::factory()->pass()->create(['price_usd' => 2900]), 1)
        ->create();

    Mail::to($customer->email)->send(new OrderPaid($order));

    $html = htmlBodySent();

    // **The three supplied designs were all reading confirmations, and this
    // mailable confirms every purchase.** A pass is open the moment it is paid
    // for: nothing is written by hand and nothing is on its way. Rendered
    // against the reading copy it said "Your Reading Is On Its Way" over
    // "DELIVERY WITHIN IMMEDIATELY", which is two wrong sentences in the two
    // largest pieces of type in the document.
    expect($html)->not->toContain('Your Reading')
        ->and($html)->not->toContain('Delivery within')
        ->and($html)->toContain('Your Order');
});

it('still promises delivery on an order holding a pass and a reading together', function () {
    $customer = Customer::factory()->create(['name' => 'Jennifer', 'email' => 'jennifer@example.com']);

    $order = Order::factory()
        ->for($customer)
        ->forProduct(Product::factory()->pass()->create(['price_usd' => 2900]), 1)
        ->forProduct(Product::factory()->create([
            'key' => ProductKey::InDepth,
            'name' => ['en' => ProductKey::InDepth->defaultName()],
            'price_usd' => 12000,
        ]), 1)
        ->create();

    Mail::to($customer->email)->send(new OrderPaid($order));

    // One reading on the order is enough for something to be on its way, and
    // the pass box beside it still carries no delivery promise of its own.
    expect(htmlBodySent())->toContain('Your Reading')
        ->and(substr_count(htmlBodySent(), 'Delivery within'))->toBe(1);
});

/*
| The pass copy, issue #98.
*/

function passOrderFor(?string $password, bool $withReading = false): Order
{
    $builder = Order::factory()
        ->for(Customer::factory()->create(['name' => 'Jennifer', 'email' => 'jennifer@example.com', 'password' => $password]))
        ->forProduct(Product::factory()->pass()->create(['price_usd' => 2900]), 1);

    if ($withReading) {
        $builder = $builder->forProduct(Product::factory()->create([
            'key' => ProductKey::InDepth,
            'name' => ['en' => ProductKey::InDepth->defaultName()],
            'price_usd' => 12000,
        ]), 1);
    }

    return $builder->create();
}

const PASS_SENTENCE = 'Your Viewing Room pass is yours for good.';
const PASSWORD_SENTENCE = 'You still need to choose a password. A separate email from us carries the link to set one, or you can ask for a new one from the sign in page at any time.';

it('tells the buyer of a pass it is theirs for good, with a link in, in both parts', function () {
    $order = passOrderFor(null);

    Mail::to($order->customer->email)->send(new OrderPaid($order));

    $url = FrontendUrl::viewingRoom();

    expect(htmlBodySent())->toContain(PASS_SENTENCE)
        ->and(htmlBodySent())->toContain('href="'.$url.'"')
        ->and(htmlBodySent())->toContain('Enter the Viewing Room')
        ->and(textBodySent())->toContain(PASS_SENTENCE)
        ->and(textBodySent())->toContain($url);
});

it('says a password is still to be chosen when the customer is unclaimed', function () {
    $order = passOrderFor(null);

    Mail::to($order->customer->email)->send(new OrderPaid($order));

    expect(htmlBodySent())->toContain(PASSWORD_SENTENCE)
        ->and(textBodySent())->toContain(PASSWORD_SENTENCE);
});

it('leaves out the password sentence for a claimed customer', function () {
    $order = passOrderFor('a-long-enough-password');

    Mail::to($order->customer->email)->send(new OrderPaid($order));

    // Anchored on the pass sentence so the absence is not asserted on an empty body.
    expect(htmlBodySent())->toContain(PASS_SENTENCE)
        ->and(htmlBodySent())->not->toContain('You still need to choose a password')
        ->and(textBodySent())->toContain(PASS_SENTENCE)
        ->and(textBodySent())->not->toContain('You still need to choose a password');
});

it('never carries a set-password link or token in the receipt', function (?string $password) {
    $order = passOrderFor($password);

    Mail::to($order->customer->email)->send(new OrderPaid($order));

    $setPassword = parse_url(FrontendUrl::setPassword('jennifer@example.com', 'tok'), PHP_URL_PATH);

    expect(htmlBodySent())->toContain(PASS_SENTENCE)
        ->and(htmlBodySent())->not->toContain($setPassword)
        ->and(htmlBodySent())->not->toContain('token=')
        ->and(textBodySent())->toContain(PASS_SENTENCE)
        ->and(textBodySent())->not->toContain($setPassword)
        ->and(textBodySent())->not->toContain('token=');
})->with([[null], ['a-long-enough-password']]);

it('carries no pass copy and no Viewing Room link on a reading-only order', function () {
    $order = paidOrderFor(ProductKey::MonthAhead, 7500);

    Mail::to($order->customer->email)->send(new OrderPaid($order));

    $url = FrontendUrl::viewingRoom();

    // Anchored on the reading so an empty body cannot pass.
    expect(htmlBodySent())->toContain('MONTH AHEAD')
        ->and(htmlBodySent())->not->toContain('yours for good')
        ->and(htmlBodySent())->not->toContain('Enter the Viewing Room')
        ->and(htmlBodySent())->not->toContain($url)
        ->and(htmlBodySent())->not->toContain('choose a password')
        ->and(textBodySent())->toContain('Thank you, your payment has gone through.')
        ->and(textBodySent())->not->toContain('yours for good')
        ->and(textBodySent())->not->toContain($url)
        ->and(textBodySent())->not->toContain('choose a password');
});

it('carries both the pass copy and the reading copy on an order holding both', function () {
    $order = passOrderFor(null, withReading: true);

    Mail::to($order->customer->email)->send(new OrderPaid($order));

    expect(htmlBodySent())->toContain(PASS_SENTENCE)
        ->and(htmlBodySent())->toContain('Your Reading')
        ->and(htmlBodySent())->toContain('Delivery within 48 hours')
        ->and(textBodySent())->toContain(PASS_SENTENCE)
        ->and(textBodySent())->toContain(FrontendUrl::viewingRoom());
});

it('gives every reading a subtitle and a delivery window', function () {
    // **All four, not the three that were drawn.** The package covers month
    // ahead, three card and in depth. One card is sold today and would
    // otherwise render a box with an empty line where a subtitle goes.
    foreach (ProductKey::readings() as $key) {
        expect($key->emailSubtitle())->not->toBeEmpty("{$key->value} has no subtitle")
            ->and($key->deliveryWindow())->not->toBeEmpty("{$key->value} has no delivery window");
    }
});

it('gives a subtitle but no delivery window to everything that is not a reading', function () {
    // **This loop is the one that was missing**, and its absence is why
    // "DELIVERY WITHIN IMMEDIATELY" reached a rendered document. `readings()`
    // is three quarters of the catalogue and the pass is orderable today.
    $notReadings = array_filter(
        ProductKey::cases(),
        fn (ProductKey $key): bool => ! in_array($key, ProductKey::readings(), true),
    );

    expect($notReadings)->not->toBeEmpty();

    foreach ($notReadings as $key) {
        expect($key->emailSubtitle())->not->toBeEmpty("{$key->value} has no subtitle")
            ->and($key->deliveryWindow())->toBeNull("{$key->value} promises a delivery window it cannot keep");
    }
});
