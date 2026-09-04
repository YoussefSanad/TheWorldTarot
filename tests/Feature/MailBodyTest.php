<?php

use App\Enums\ProductKey;
use App\Mail\GiftToRedeem;
use App\Mail\OrderPaid;
use App\Mail\ReadingOnItsWay;
use App\Mail\ReadingToWrite;
use App\Mail\SetPasswordLink;
use App\Models\Customer;
use App\Models\Gift;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Reading;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
| The body as it leaves the application, which is the layer nothing asserted on.
|
| Issue #45. Every mailable renders a **text** view, and every view interpolated
| with `{{ }}`, which HTML-escapes. There is no HTML context in a text body, so
| the escaping was pure corruption: the `&` joining the two query parameters of
| a password link left here as `&amp;`, and every claim link and every reset
| link this application ever sent was dead on arrival.
|
| Two tests stood either side of it and neither could see it. `FrontendUrlTest`
| asserts the URL the builder returns, and the builder was always correct.
| `MarkOrderPaidTest` asserts on `$mail->render()`. This file is the layer in
| between, read off the array transport rather than off a mailable, because the
| question is what was sent and not what a mailable would say if asked.
|
| **Every message here is sent directly rather than settled into existence.**
| Which order causes which mail is a policy that moves, and it moved while this
| file was being written. It is `MarkOrderPaidTest`'s question and not this
| one's: what is asked here is what a message says once something has decided to
| send it, so the fixture is the mailable itself.
|
| **`Mail::fake()` must never be set in this file.** A fake replaces the
| transport, so there is no sent message to read and every assertion here would
| be about a message that was never rendered.
|
| The last three are convention guards rather than behavioural tests, and they
| live here rather than beside their sibling in `MailTransportTest` because all
| three are about the same thing this file is about: what ends up in a body.
*/

function textBodiesSent(): array
{
    return array_map(
        fn ($sent): string => $sent->getOriginalMessage()->getTextBody(),
        app('mailer')->getSymfonyTransport()->messages()->all(),
    );
}

/**
 * The same, for the part a person actually looks at.
 *
 * **A separate reader rather than a flag on the one above**, because the two
 * parts of one message are two different documents and every assertion here is
 * about one of them. `getHtmlBody()` answers null for a message that has no
 * markup part, which is what a mailable that lost its `html:` looks like.
 *
 * @return array<int, ?string>
 */
function htmlBodiesSent(): array
{
    return array_map(
        fn ($sent): ?string => $sent->getOriginalMessage()->getHtmlBody(),
        app('mailer')->getSymfonyTransport()->messages()->all(),
    );
}

/**
 * The markup part of the one message that was sent.
 *
 * Sole rather than first: every test using this sends exactly one message, and
 * a second arriving would make an index quietly pick the wrong document.
 */
function theHtmlSent(): string
{
    $bodies = htmlBodiesSent();

    expect($bodies)->toHaveCount(1)
        ->and($bodies[0])->not->toBeNull('The message was sent without a markup part');

    return (string) $bodies[0];
}

/**
 * The one body that mentions a thing, failing loudly when none does.
 *
 * Settling an order sends three messages, so picking by index would silently
 * follow whichever order `tell()` happens to send them in.
 */
function bodyMentioning(string $needle): string
{
    $found = array_values(array_filter(
        textBodiesSent(),
        fn (string $body): bool => str_contains($body, $needle),
    ));

    expect($found)->toHaveCount(1, "No single sent message mentions '{$needle}'");

    return $found[0];
}

/**
 * The query string of the first link in a body, as a parser reads it.
 *
 * **Parsed rather than string-matched, and parsed by PHP rather than by us.**
 * A `str_contains($body, 'email=')` passes on the broken link, because
 * `&amp;email=` contains `email=`. The defect is only visible to something that
 * reads the query the way the browser does, which is what refused these links
 * in front of real customers.
 *
 * @return array<string, string>
 */
function queryOfLinkIn(string $body): array
{
    expect($body)->toMatch('#https://\S+#');

    preg_match('#https://\S+#', $body, $matches);
    parse_str((string) parse_url($matches[0], PHP_URL_QUERY), $query);

    return $query;
}

/**
 * An order for one reading, belonging to somebody with an awkward name.
 *
 * **`null` is a real buyer since issue #52**, not a broken fixture. Nothing on
 * the road a customer actually takes asks them their name, so one created at
 * settlement carries whatever Stripe collected, and the wallet may collect
 * nothing at all.
 */
function orderFrom(?string $name, string $email): Order
{
    return Order::factory()
        ->for(Customer::factory()->create(['name' => $name, 'email' => $email, 'password' => null]))
        ->forProduct(Product::factory()->create(), 1, "What of Jodie's move & the <plan>?")
        ->create();
}

/**
 * What was asked on an order, as `ReadingToWrite` is handed it.
 *
 * **The mail carries the readings rather than the order's lines since B6
 * (#72)**, because a basket may hold a present and a self purchase together and
 * only one of them is work. The rows are made directly here for the reason this
 * file gives at the top: which order causes which reading is
 * `MarkOrderPaidTest`'s question, and what is asked here is what a message says
 * once something has decided to send it.
 *
 * @return Collection<int, Reading>
 */
function askedOn(Order $order): Collection
{
    return $order->lines->map(fn (OrderLine $line): Reading => Reading::factory()->create([
        'order_line_id' => $line->id,
        'question' => $line->question,
        'querent_email' => $order->customer->email,
        'querent_name' => $order->customer->name,
    ]));
}

it('sends a claim link a browser can read the address out of', function () {
    config()->set('frontend.url', 'https://staging.theworldtarot.com');

    $customer = Customer::factory()->create(['email' => 'jennifer@example.com', 'password' => null]);
    Mail::to($customer->email)->send(new SetPasswordLink($customer, passwordTokenFor($customer)));

    $query = queryOfLinkIn(bodyMentioning('/set-password/'));

    // Both halves, because the reset is verified against the pair and a token
    // alone identifies nobody. Escaped, the second parameter arrived named
    // `amp;email`, the website read `email` as null, and it refused the link
    // before making a request. That empty Network tab is why frontend #49 item
    // 2 sat on `needs-info` waiting for a 422 body that never existed.
    expect($query)->toHaveKey('email')
        ->and($query['email'])->toBe('jennifer@example.com')
        ->and($query)->toHaveKey('token')
        ->and($query['token'])->not->toBeEmpty()
        ->and(array_keys($query))->toBe(['token', 'email']);
});

it('sends a reset link a browser can read the address out of', function () {
    config()->set('frontend.url', 'https://staging.theworldtarot.com');

    $customer = Customer::factory()->create(['email' => 'jennifer@example.com']);
    $customer->sendPasswordResetNotification(passwordTokenFor($customer));

    $query = queryOfLinkIn(bodyMentioning('/reset-password/'));

    expect(array_keys($query))->toBe(['token', 'email'])
        ->and($query['email'])->toBe('jennifer@example.com');
});

it('greets somebody by the name they gave, apostrophe and all', function () {
    $order = orderFrom("Miss Jodie O'Reilly", 'jodie@example.com');
    $customer = $order->customer;

    Mail::to($customer->email)->send(new OrderPaid($order));
    Mail::to($customer->email)->send(new SetPasswordLink($customer, passwordTokenFor($customer)));

    // Escaped, a real captured receipt opened "Hello Miss Jodie O&#039;Reilly,".
    // Anybody with an apostrophe, an ampersand or a quote in their name has been
    // reading that since 9 August 2026.
    foreach ([bodyMentioning('payment has gone through'), bodyMentioning('/set-password/')] as $body) {
        expect($body)->toContain("Hello Miss Jodie O'Reilly,")
            ->and($body)->not->toContain('&#039;');
    }
});

it('opens without a name rather than inventing one, for a buyer who gave none', function () {
    $order = orderFrom(null, 'jennifer@example.com');
    $customer = $order->customer;

    Mail::to($customer->email)->send(new OrderPaid($order));
    Mail::to($customer->email)->send(new SetPasswordLink($customer, passwordTokenFor($customer)));
    $customer->sendPasswordResetNotification(passwordTokenFor($customer));

    // Issue #52. A real captured receipt opened "Hello Card Holder Name,", and
    // the name in it was never asked for by this application. The placeholder
    // itself is undetectable, so what is fixed is the case with a right answer.
    $bodies = [
        bodyMentioning('payment has gone through'),
        bodyMentioning('/set-password/'),
        bodyMentioning('/reset-password/'),
    ];

    foreach ($bodies as $body) {
        expect($body)->toContain('Hello,')
            // The two ways this goes wrong, and both have shipped. `Hello ,`
            // is the empty interpolation the old fallback existed to avoid.
            ->and($body)->not->toContain('Hello ,')
            // And `Hello jennifer,` is that fallback, which invented a form of
            // address out of the routing half of an email address.
            ->and($body)->not->toContain('jennifer,');
    }
});

it('names a product with nothing left in its name the way the panel names it', function () {
    // Blank in the read locale and blank in `en`, which is the one state the
    // receipt and the panel used to disagree about. `??` never fired, because a
    // translatable attribute resolves to the empty string rather than to null,
    // so the panel said `3 CARD READING` and this message said nothing at all
    // where the product should be.
    $product = Product::factory()->create(['key' => ProductKey::ThreeCard]);
    $product->setTranslation('name', 'en', '')->save();

    $order = Order::factory()
        ->for(Customer::factory()->create(['email' => 'jodie@example.com', 'password' => null]))
        ->forProduct($product)
        ->create();

    Mail::to('jodie@example.com')->send(new OrderPaid($order));

    $text = bodyMentioning('payment has gone through');
    $html = theHtmlSent();

    // **The same string `OrderLineTest` pins the panel to**, which is what
    // "the same name everywhere" is worth asserting as. `three-card` is the URL
    // slug the `?:` sites fell back to, and it is what a customer was shown.
    expect($text)->toContain('3 CARD READING')
        ->and($text)->not->toContain('three-card')
        ->and($html)->toContain('3 CARD READING')
        ->and($html)->not->toContain('three-card')
        // And the `<title>`, which is the one place a missing name renders as a
        // blank a reader cannot see. `MailBodyTest` already guards the shape of
        // this section; this guards what it says.
        ->and($html)->toContain('<title>Your 3 CARD READING is on its way</title>');
});

it('gives Jennifer the address alone when the buyer gave no name', function () {
    $order = orderFrom(null, 'jennifer@example.com');

    Mail::to(config('mail.notify.fulfillment'))->send(new ReadingToWrite($order, askedOn($order)));

    // **She still has to be able to write back**, so the address is the part
    // that cannot go missing. `From: <jennifer@example.com>` reads as a bug in
    // our software rather than as a buyer who was never asked.
    expect(bodyMentioning('has been paid'))
        ->toContain('From: jennifer@example.com')
        ->not->toContain('From:  <');
});

it('gives Jennifer the buyer name exactly as the buyer typed it', function () {
    $order = orderFrom("Miss Jodie O'Reilly", 'jodie@example.com');

    Mail::to(config('mail.notify.fulfillment'))->send(new ReadingToWrite($order, askedOn($order)));

    // The question is the customer's own prose and the most likely place in any
    // of these messages to hold a character Blade would have escaped.
    expect(bodyMentioning('has been paid'))
        ->toContain("From: Miss Jodie O'Reilly <jodie@example.com>")
        ->toContain("What of Jodie's move & the <plan>?");
});

/*
| The querent's own mail, and the one promise this system is allowed to make.
|
| B8, issue #74. She has no account, no order and no lookup, so the confirmation
| the redemption page renders dies with her tab and this message is the only
| durable record that a clock started. `ReadingOnItsWay` carries the argument.
|
| **The body is where the promise is observable at all.** That it was queued is
| `RedeemAGiftTest`'s question; whether it actually says when the reading is due
| can only be read off the text, which is what this file exists to read.
*/

it('promises the querent a reading, counted from the day she asked', function () {
    // Bought in June and asked for in September, which is the parting a gift
    // makes and the whole reason `asked_at` exists. The frontend's `CONTEXT.md`
    // says it under **Ask**: "within 24 hours" of a gift bought three months
    // earlier is a promise about nothing.
    $this->travelTo('2026-06-01 09:00:00');
    $gift = aGiftOf([], ['key' => ProductKey::MonthAhead, 'name' => ['en' => 'THE MONTH AHEAD']]);

    $this->travelTo('2026-09-04 10:03:00');
    $reading = Reading::factory()->create([
        'order_line_id' => $gift->order_line_id,
        'gift_id' => $gift->id,
        'question' => "What of Jodie's move & the <plan>?",
        'querent_name' => 'Sam',
        'asked_at' => now(),
    ]);

    Mail::to($reading->querent_email)->send(new ReadingOnItsWay($reading));

    $body = bodyMentioning('will be with you');

    // **The moment, written out rather than computed the way the code does.**
    // A `$reading->dueAt()->format(...)` here would pass by construction and
    // could never disagree with a wrong sum. THE MONTH AHEAD promises 24 hours,
    // so an asking at 10:03 on 4 September is due at 10:03 on the 5th.
    expect($body)->toContain('It will be with you within 24 hours, by 5 September 2026 at 10:03 UTC.')
        // Not the purchase, three months earlier, which is the promise about
        // nothing that this whole unit exists to avoid making.
        ->and($body)->not->toContain('2 June 2026');

    // What she asked for, in her own words, unescaped like everything else in
    // this directory.
    expect($body)->toContain('Hello Sam,')
        ->and($body)->toContain("What of Jodie's move & the <plan>?")
        ->and($body)->not->toContain('&#039;');

    // **The reading is named, and this assertion is here because it was not.**
    // A mailable's public properties are merged over its `with:` array, so the
    // `$reading` model won the view's `reading` key and the body read "You have
    // asked for {"id":3,"order_line_id":5,...}". It sent, it rendered, and every
    // other assertion in this test passed over it. Found only because an
    // unrelated failure printed the body.
    expect($body)->toContain('You have asked for THE MONTH AHEAD.')
        ->and($body)->not->toContain('order_line_id');
});

/*
| And the same message as a document, issue #85.
|
| It was a text part and nothing else until 4 September 2026, which left the
| querent holding the least designed message in a flow whose other two are
| drawn: the buyer's receipt since #53 and the recipient's present since #81.
| She is also the reader with the least standing to check it, having no
| account, no order and no lookup, so the frame the other two are drawn in is
| the same argument here that it was there.
|
| The two tests below are the halves of what changed: that the designed part
| exists, is drawn in that frame and escapes her own typing on the way in, and
| that it withholds the two things this message must not carry.
*/

/**
 * A present, spent, with a question typed in the querent's own words.
 *
 * **The gift fixture from the section below, deliberately.** Every
 * `ReadingOnItsWay` in this application is a redeemed present, per
 * `AskForReading::forGift()`, so what the denial test below has to prove is
 * that this document carries nothing of *that* gift but the reading it names.
 * A fixture of its own would be asserting the absence of words no test ever
 * put anywhere near it.
 *
 * **`asked_at` is written rather than travelled to**, because the promise is
 * counted from it and nothing else here depends on today. The test above,
 * which is about the parting a gift makes between buying and asking, travels.
 *
 * **The present is an argument where one has already been sent**, since two
 * fixtures would mean two products keyed `in-depth` and the column is unique.
 * The frame test hands over the gift it has just drawn, which is also the
 * truer arrangement: one present, and the two messages it causes.
 */
function aReadingAskedInAwkwardWords(?Gift $gift = null): Reading
{
    $gift ??= aGiftWithAwkwardWords();

    return Reading::factory()->create([
        'order_line_id' => $gift->order_line_id,
        'gift_id' => $gift->id,
        'question' => "What of Jodie's move & the <plan>?",
        'querent_name' => 'Sam',
        'querent_email' => 'sam@example.com',
        'asked_at' => '2026-09-04 10:03:00',
    ]);
}

it('sends the querent her reading as a document and as text, and escapes only the document', function () {
    $reading = aReadingAskedInAwkwardWords();

    Mail::to($reading->querent_email)->send(new ReadingOnItsWay($reading));

    $html = theHtmlSent();

    // **Her own typing, in a document that has tags to close.** It is the
    // longest free text in this message and the only value in it she wrote
    // herself, which is why the escaping this directory enforces is not
    // theoretical on this document either.
    expect($html)->toContain('&ldquo;What of Jodie&#039;s move &amp; the &lt;plan&gt;?&rdquo;')
        ->and($html)->not->toContain('<plan>');

    // Who she is, what she asked for, and the catalogue's second line under it.
    expect($html)->toContain('Hello Sam,')
        ->and($html)->toContain('THE IN-DEPTH READING')
        ->and($html)->toContain('The Full Picture')
        // The title, which is the one place an unescaped value looks harmless
        // right up until it is not.
        ->and($html)->toContain('<title>Your THE IN-DEPTH READING is on its way</title>');

    // **The promise, written out rather than computed the way the code does.**
    // A `$reading->dueAt()->format(...)` here would pass by construction and
    // could never disagree with a wrong sum. THE IN-DEPTH READING promises 48
    // hours, so an asking at 10:03 on 4 September is due at 10:03 on the 6th.
    expect($html)->toContain('It will be with you within 48 hours, by 6 September 2026 at 10:03 UTC.');

    // **And the text part is untouched, which is the half that is easy to lose
    // by adding the other one.** It escapes nothing, per #45, and it is what a
    // client that will not render markup reads.
    $text = bodyMentioning('There is nothing more to do');

    expect($text)->toContain('You have asked for THE IN-DEPTH READING.')
        ->and($text)->toContain("What of Jodie's move & the <plan>?")
        ->and($text)->toContain('It will be with you within 48 hours, by 6 September 2026 at 10:03 UTC.')
        ->and($text)->not->toContain('&lt;')
        ->and($text)->not->toContain('&#039;')
        ->and($text)->not->toContain('&amp;');
});

/**
 * The two things this document must not carry, and one is a social injury.
 *
 * **A denial test, for the same reason the present's is.** This message is
 * drawn in the receipt's own frame, and the receipt carries a price in gold at
 * 24px, a total, and the name a present was signed with is one row along in
 * the message beside it. Bringing any of them across is exactly what sharing a
 * frame makes easy, and none of the three fails visibly.
 *
 * **She did not pay and may never learn what was paid**, which is the whole
 * point of a present. **And the signature is not repeated** because
 * `GiftToRedeem` already told her who it came from: saying it twice is a second
 * place to get a stranger's business wrong, on the message furthest from the
 * person who typed it.
 */
it('tells the querent nothing of the price, and nothing of who paid for it', function () {
    $reading = aReadingAskedInAwkwardWords();

    $line = $reading->orderLine;
    $order = $line->order;

    Mail::to($reading->querent_email)->send(new ReadingOnItsWay($reading));

    $html = theHtmlSent();

    // **What was paid**, in the two forms the receipt prints it: the line and
    // the order total. Read off the row rather than written out, so a change to
    // what the factory charges cannot quietly make this assert nothing.
    expect(Money::toMajor($line->subtotal()))->not->toBeEmpty();

    expect($html)->not->toContain(Money::toMajor($line->subtotal()))
        ->and($html)->not->toContain(Money::toMajor($order->total_amount))
        ->and($html)->not->toContain('Total')
        // The receipt's price row, which is the thing that would have been
        // carried across with the card it sits in.
        ->and($html)->not->toContain('class="price"');

    // **The signature, which is the recipient's mail's opening line and has no
    // second home.** Both its forms, since this document escapes and that one
    // does too.
    expect($reading->gift->signature)->toBe('Mum <3');

    expect($html)->not->toContain('Mum <3')
        ->and($html)->not->toContain('Mum &lt;3')
        ->and($html)->not->toContain('has bought you a gift')
        // And the buyer's note, which was written to the recipient and not to
        // whoever the code was passed on to.
        ->and($html)->not->toContain('Thinking of you');

    // **The buyer themselves**, who is not being introduced. The recipient was
    // never told their address or the name on their card, per `GiftToRedeem`,
    // and the querent has even less claim on either.
    expect($order->customer->email)->not->toBeEmpty();

    expect($html)->not->toContain($order->customer->email)
        ->and($html)->not->toContain((string) $order->customer->name)
        // The receipt's own row naming where a present went, which belongs to
        // the buyer's copy alone.
        ->and($html)->not->toContain('Sent to');
});

/*
| The present, as the person who was given it receives it. Issue #81.
|
| `GiftToRedeem` was a text part and nothing else until 3 September 2026, which
| made the least branded message this application sends the one going to the
| only person in the system who has never bought anything and may never have
| heard of the brand, carrying a code and a link. Its own docblock says that
| message is shaped exactly like phishing.
|
| The three tests below are the three halves of what changed: that the designed
| part exists and is drawn in the receipt's frame, that a customer's own words
| are escaped on the way into it, and that it withholds the three things the
| recipient must not be told.
*/

/**
 * A present with a signature, a note and a name that all carry markup.
 *
 * **`Mum <3` is not a contrived fixture.** It is a signature, which is what
 * somebody signs a present with rather than what is on their card, and a heart
 * typed the way people type it opens a tag. The note is the buyer's own prose
 * and the longest free text in the message.
 */
function aGiftWithAwkwardWords(): Gift
{
    return aGiftOf(
        ['code' => 'ABCD2345EFGH', 'signature' => 'Mum <3', 'message' => 'Thinking of you & the <move>.'],
        ['key' => ProductKey::InDepth, 'name' => ['en' => 'THE IN-DEPTH READING']],
    );
}

it('sends the present as a document and as text, and escapes only the document', function () {
    config()->set('frontend.url', 'https://staging.theworldtarot.com');

    $gift = aGiftWithAwkwardWords();

    Mail::to($gift->recipient_email)->send(new GiftToRedeem($gift));

    $html = theHtmlSent();

    // The signature, the note and the reading's name, all typed by somebody and
    // all three in a document that has tags to close.
    expect($html)->toContain('Mum &lt;3 has bought you a gift.')
        ->and($html)->toContain('&ldquo;Thinking of you &amp; the &lt;move&gt;.&rdquo;')
        ->and($html)->toContain('THE IN-DEPTH READING')
        // The title carries it too, and a title is the one place an unescaped
        // value looks harmless right up until it is not.
        ->and($html)->toContain('<title>A gift from Mum &lt;3</title>')
        ->and($html)->not->toContain('Mum <3');

    // The credential, in both of its forms, spaced the way it is meant to be
    // typed.
    expect($html)->toContain('https://staging.theworldtarot.com/redeem/?code=ABCD-2345-EFGH')
        ->and($html)->toContain('>ABCD-2345-EFGH<');

    // **And the text part is untouched, which is the half that is easy to lose
    // by adding the other one.** It escapes nothing, per #45, and it is what a
    // client that will not render markup reads.
    $text = bodyMentioning('A gift code does not expire');

    expect($text)->toContain('Mum <3 has bought you a gift: THE IN-DEPTH READING.')
        ->and($text)->toContain('Thinking of you & the <move>.')
        ->and($text)->toContain('https://staging.theworldtarot.com/redeem/?code=ABCD-2345-EFGH')
        ->and($text)->not->toContain('&lt;')
        ->and($text)->not->toContain('&amp;');
});

/**
 * The word for what has arrived, and the control that opens it.
 *
 * **A reading is a document written by hand against a question somebody has
 * asked**, and the reader of this message has asked nothing: what they hold is
 * a code, and it stays one until they spend it. The subject line took that
 * correction on 3 September 2026 and both bodies followed it the same day —
 * which is worth a test of its own, because a subject and a document
 * disagreeing about what arrived is exactly the kind of half-landed copy change
 * nobody sees in a diff.
 *
 * **The card still names the reading**, and that is the assertion this pairs
 * with: which reading the gift is *for* is not a claim that a reading exists,
 * and a document withholding it would leave the reader unable to see what they
 * have been given.
 *
 * **And the way in is a button, and only a button.** ~~With the address still
 * printed under it.~~ The printed URL came out: it was the button's own `href`
 * set a second time, one line below it, wrapping across a phone. What the
 * reader who will not press an unfamiliar control still has is the **code**,
 * which is asserted with it — that is the second form of the credential, and
 * the one that half was ever about.
 */
it('says a gift rather than a reading, and opens it with a button', function () {
    config()->set('frontend.url', 'https://staging.theworldtarot.com');

    $gift = aGiftWithAwkwardWords();

    Mail::to($gift->recipient_email)->send(new GiftToRedeem($gift));

    $html = theHtmlSent();
    $link = 'https://staging.theworldtarot.com/redeem/?code=ABCD-2345-EFGH';

    // Nothing in this document says the reader has been given a reading — not
    // the heading, not the opening line, not the title.
    expect($html)->toContain('You Have Been<br>Given A Gift')
        ->and($html)->toContain('has bought you a gift.')
        ->and($html)->toContain('<title>A gift from')
        ->and($html)->not->toContain('Given A Reading')
        ->and($html)->not->toContain('bought you a reading')
        // But the card still names which reading it is for.
        ->and($html)->toContain('THE IN-DEPTH READING');

    // The button, in both of the forms this document ships it in: the anchor
    // every client but one renders, and the VML Word draws instead.
    expect($html)->toContain('<a href="'.$link.'" style="display:block;')
        ->and($html)->toContain('>Open Your Gift</a>')
        ->and($html)->toContain('<v:roundrect')
        ->and($html)->toContain('OPEN YOUR GIFT</center>')
        // The fill and the ink, which the frame restates for the two dark modes
        // because this is the one control here that is dark on light.
        ->and($html)->toContain('bgcolor="#E4C46A"')
        ->and($html)->toContain('.btn a { color:#101B2E !important; }');

    // **And the address is drawn nowhere but that button.** It is a bearer
    // credential the length of a phone screen, and printing it under the
    // control that already carries it set the same string twice. The reader who
    // will not press it is served by the code, which this document still
    // prints for them to type into a page they navigated to themselves.
    expect($html)->not->toContain('>'.$link.'</a>')
        ->and($html)->toContain('>ABCD-2345-EFGH</td>');

    // The text part has no controls and prints the address, as it always has.
    $text = bodyMentioning('A gift code does not expire');

    expect($text)->toContain('has bought you a gift: THE IN-DEPTH READING.')
        ->and($text)->toContain($link)
        ->and($text)->not->toContain('bought you a reading');
});

/**
 * The three things the recipient is the one person who must not be told.
 *
 * **A denial test, and the reason the issue is worth its own ticket.** The
 * receipt this document now shares a frame with carries all three: a price in
 * gold at 24px, the buyer, and a delivery promise. Drawing the gift in that
 * frame is exactly the change that could bring them across, and none of the
 * three fails visibly. A price on a present is a social injury nobody reports
 * as a bug.
 */
it('tells the recipient nothing of the price, the buyer, or any clock', function () {
    $gift = aGiftWithAwkwardWords();

    $line = $gift->orderLine;
    $order = $line->order;

    Mail::to($gift->recipient_email)->send(new GiftToRedeem($gift));

    $html = theHtmlSent();

    // **What was paid**, in the two forms the receipt prints it: the line and
    // the order total. Read off the row rather than written out, so a change to
    // what the factory charges cannot quietly make this assert nothing.
    expect(Money::toMajor($line->subtotal()))->not->toBeEmpty();

    expect($html)->not->toContain(Money::toMajor($line->subtotal()))
        ->and($html)->not->toContain(Money::toMajor($order->total_amount))
        ->and($html)->not->toContain('Total')
        // The receipt's price row, which is the thing that would have been
        // carried across with the card it sits in.
        ->and($html)->not->toContain('class="price"');

    // **The buyer, who is not being introduced.** A present is not an
    // introduction between two people, and the buyer typed this address rather
    // than the other way round. Nothing of theirs but the signature.
    expect($order->customer->email)->not->toBeEmpty();

    expect($html)->not->toContain($order->customer->email)
        ->and($html)->not->toContain((string) $order->customer->name)
        // The receipt's own row naming where a present went, which belongs to
        // the buyer's copy alone.
        ->and($html)->not->toContain('Sent to');

    // **A clock nobody has started.** Nothing has been asked for, so nothing is
    // owed by any hour. That promise is `ReadingOnItsWay`'s, counted from the
    // day the querent asks, per B8 (#74).
    expect($line->product_key->deliveryWindow())->not->toBeNull();

    expect($html)->not->toContain('Delivery within')
        ->and($html)->not->toContain((string) $line->product_key->deliveryWindow())
        ->and($html)->not->toContain('On Its Way');
});

/**
 * One frame, and every designed message drawn in it.
 *
 * **The receipt is the message the most customers see and it is signed off**,
 * so the risk in extracting its chrome is that the extraction quietly drops a
 * piece of it. The parts below are the frame: the top and bottom bands, the
 * four corner ornaments, the logo, the divider, the compass, the domain, the
 * sentence saying nothing here can be replied to, and the two legal links.
 *
 * ~~Asserted of both documents at once~~ **of all three, from 4 September 2026**
 * (#85), because the whole point of the frame is that none of them can have a
 * different answer.
 */
it('draws every designed message in one frame', function () {
    config()->set('frontend.url', 'https://staging.theworldtarot.com');
    config()->set('mail.assets.url', 'https://assets.theworldtarot.com/email');

    // Keyed rather than left to `orderFrom`, whose product takes whichever
    // reading the factory's cycle is on next and would sooner or later be the
    // one the gift below pins.
    $order = Order::factory()
        ->for(Customer::factory()->create(['name' => "Miss Jodie O'Reilly", 'email' => 'jodie@example.com']))
        ->forProduct(Product::factory()->create(['key' => ProductKey::MonthAhead]), 1)
        ->create();

    $gift = aGiftWithAwkwardWords();

    Mail::to('jodie@example.com')->send(new OrderPaid($order));
    Mail::to('sam@example.com')->send(new GiftToRedeem($gift));
    Mail::to('sam@example.com')->send(new ReadingOnItsWay(aReadingAskedInAwkwardWords($gift)));

    $bodies = htmlBodiesSent();

    expect($bodies)->toHaveCount(3);

    foreach ($bodies as $index => $body) {
        expect($body)->not->toBeNull("Message {$index} was sent without a markup part");

        foreach ([
            'band-top.png', 'band-bottom.png',
            'orn-tl.png', 'orn-tr.png', 'orn-bl.png', 'orn-br.png',
            'logo.png', 'divider.png', 'compass.png',
        ] as $asset) {
            // Absolute, from `mail.assets.url`. An email client has no document
            // base to resolve a relative path against.
            expect($body)->toContain("https://assets.theworldtarot.com/email/{$asset}");
            expect($body)->toContain('https://assets.theworldtarot.com/email/'.str_replace('.png', '@2x.png', $asset));
        }

        expect($body)->toContain('This is an automated message. Please do not reply.')
            ->and($body)->toContain('>staging.theworldtarot.com</a>')
            ->and($body)->toContain('https://staging.theworldtarot.com/privacy-policy')
            ->and($body)->toContain('https://staging.theworldtarot.com/terms-of-service')
            // The heading slot, filled by both and by neither the same way.
            ->and($body)->toMatch('/class="gold head"/');
    }
});

/**
 * Every view a message can reach, including any it reaches through another.
 *
 * **Recursive, and not `glob('*.blade.php')`.** A flat glob does not descend,
 * so a partial added at `mail/partials/greeting.blade.php` would escape the
 * rule silently. That is not hypothetical: it was planted during review and the
 * suite stayed quiet about it.
 *
 * @return array<int, string>
 */
function mailViews(): array
{
    $found = [];

    $tree = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('views/mail'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($tree as $file) {
        if (str_ends_with((string) $file, '.blade.php')) {
            $found[] = (string) $file;
        }
    }

    sort($found);

    return $found;
}

/**
 * Where a view being HTML is decided, and it is the directory it sits in.
 *
 * **Issue #53 is the day the file above warned about**, when a message became
 * genuinely meant to be HTML. The two rules underneath were only ever correct
 * together, so neither is deleted: they are given a boundary instead, and the
 * boundary is a path rather than a naming convention or a comment, because a
 * path is the one thing a person adding a view cannot forget to apply.
 *
 * `resources/views/mail/html/` renders as markup and **must** escape.
 * Everything else in `resources/views/mail/` is text and **must not**.
 */
function isHtmlMailView(string $view): bool
{
    return str_starts_with($view, resource_path('views/mail/html').DIRECTORY_SEPARATOR);
}

/**
 * @return array<int, string>
 */
function textMailViews(): array
{
    return array_values(array_filter(mailViews(), fn (string $view): bool => ! isHtmlMailView($view)));
}

/**
 * @return array<int, string>
 */
function htmlMailViews(): array
{
    return array_values(array_filter(mailViews(), 'isHtmlMailView'));
}

/**
 * A view's lines, with anything inside a Blade comment blanked out.
 *
 * **A comment is not an interpolation, and the rules below scan for text.** The
 * HTML view's own header explains the escaping split and quotes `{!! !!}` while
 * doing it, which the raw-echo check read as a raw echo. Blanking rather than
 * removing, so a reported line number still points at the right line.
 *
 * `{{--` was already exempt from the text-side rule for the same reason. This
 * generalises that to the whole comment rather than to its opening brace, since
 * a comment spanning lines put the exemption on the wrong one.
 *
 * @return array<int, string>
 */
function viewLinesWithoutComments(string $view): array
{
    $source = (string) preg_replace_callback(
        '/\{\{--.*?--\}\}/s',
        fn (array $match): string => str_repeat("\n", substr_count($match[0], "\n")),
        (string) file_get_contents($view),
    );

    return explode("\n", $source);
}

/**
 * The rule, made structural rather than remembered.
 *
 * `QueuedMail` already carries two of these: nothing is sent inline, and every
 * message names its sender. This is a third of the same kind, and #45 is the
 * argument that it needs enforcing. The behavioural tests above prove the four
 * places that carry a customer's own text; this proves the rest, and every view
 * written after today.
 *
 * **It reads the views rather than a rendered message on purpose.** A
 * behavioural test can only see the escaping when the data it is given happens
 * to contain a character worth escaping, so a view added next month with a
 * clean fixture would pass while being wrong. The escape sequence itself is the
 * defect, so the escape sequence is what is asserted on.
 */
it('never lets a text mail view HTML-escape what it interpolates', function () {
    $views = textMailViews();

    expect($views)->not->toBeEmpty();

    foreach ($views as $view) {
        foreach (viewLinesWithoutComments($view) as $number => $line) {
            // `@{{` is an escaped brace that Blade prints literally. Comments
            // are already gone, so `{{--` needs no exemption here.
            expect(preg_match('/(?<!@)\{\{/', $line))
                ->toBe(0, basename($view).':'.($number + 1).' escapes: '.trim($line));
        }
    }
});

/**
 * And the same rule read the other way, for the views that are markup.
 *
 * **This is the half issue #53 added, and it is the more dangerous half.** A
 * text view that escapes sends `O&#039;Reilly` to a customer, which is
 * embarrassing. An HTML view that does not escape puts a customer's own name and
 * their own typed question into a document as markup, which is an injection, and
 * `{!! !!}` is what every view in the sibling directory correctly uses. Somebody
 * copying an existing mail view into `html/` to start a new one carries the raw
 * echo across with it, and that is the mistake this exists to catch.
 *
 * Read off the view source rather than a rendered body for the reason the rule
 * above is: a behavioural assertion only sees it when the fixture happens to
 * hold a character worth escaping.
 */
it('makes every HTML mail view escape what it interpolates', function () {
    $views = htmlMailViews();

    expect($views)->not->toBeEmpty();

    foreach ($views as $view) {
        foreach (viewLinesWithoutComments($view) as $number => $line) {
            expect(preg_match('/\{!!/', $line))
                ->toBe(0, basename($view).':'.($number + 1).' echoes raw into markup: '.trim($line));
        }
    }
});

/**
 * And every directive in those views is somewhere Blade will actually read it.
 *
 * **Blade's directive pattern is anchored with `\B`**, which fails at the one
 * position a word character sits immediately before the `@`. So `way@else`
 * compiles to nothing at all: the text stays in the document as typed, and the
 * `@if` it was meant to close runs on to the next `@endif` it can see.
 *
 * **Found on 3 September 2026, in the receipt's `<title>`, by rendering it.**
 * `@if (...)Your ... is on its way@else Your order is confirmed @endif` had
 * been sending `<title></title>` on every order of more than one line, and the
 * literal string `@else Your order is confirmed` on every order of exactly
 * one, since issue #53 shipped it. Nothing was red. The view compiled, the
 * message sent, and both escaping rules above were satisfied by a line that
 * put Blade source in front of a customer.
 *
 * Scanned rather than rendered, for the reason the two rules above are: a
 * behavioural assertion only catches the branch its fixture happens to take,
 * and this defect hid in the branch nobody rendered.
 */
it('never puts a Blade directive where Blade will not read it', function () {
    $views = mailViews();

    expect($views)->not->toBeEmpty();

    // Named rather than `\w@\w+`, because a text view is entitled to carry an
    // email address and `noreply@example.com` is the same shape. Control flow
    // and inclusion only: those are what silently change a document's meaning.
    $directives = 'if|else|elseif|endif|unless|endunless|isset|endisset|empty|endempty|'
        .'foreach|endforeach|forelse|endforelse|for|endfor|while|endwhile|'
        .'switch|case|default|endswitch|php|endphp|'
        .'include|includeIf|extends|section|endsection|show|yield|parent|stop|append';

    foreach ($views as $view) {
        foreach (viewLinesWithoutComments($view) as $number => $line) {
            expect(preg_match('/\w@('.$directives.')\b/', $line))
                ->toBe(0, basename($view).':'.($number + 1).' writes a directive Blade will not compile: '.trim($line));
        }
    }
});

/**
 * Every message still carries a text part, which is what makes a raw echo safe.
 *
 * **This is the precondition the rule above rests on, and it was unenforced.**
 * Without it the fix for #45 is one careless mailable away from being worse
 * than the defect it replaced: `{!! !!}` in a view somebody renders as `html:`
 * or `view:` puts a customer's own name and their own typed question into a
 * document as markup, and a cosmetic bug becomes an injection. The two are only
 * ever correct together, so they are asserted together.
 *
 * **Until 1 September 2026 this said `text` and nothing else, and issue #53 is
 * the day it was told about HTML.** It was told by narrowing rather than by
 * deleting, which is what the note it used to end on asked for. What changed:
 * `html:` is now an accepted argument, its view has to live in the one
 * directory where escaping is enforced, and `text:` is still required on every
 * message. What did not change is the part that matters: no mailable may render
 * markup out of a view that echoes raw.
 *
 * **`view:` is still refused**, and deliberately, even though Laravel treats it
 * as an alias for `html:`. Two spellings of one thing means the rule above has
 * two ways to be escaped and a reader has two things to recognise.
 *
 * The text part is required rather than optional because dropping it is a
 * silent change: the message still sends, still looks right in every client the
 * developer checks, and quietly loses the alternative part that a plain-text
 * client and a spam filter both read.
 */
it('gives every message a text part, and takes markup only from the checked directory', function () {
    $mailables = array_filter(
        glob(app_path('Mail/*.php')),
        fn (string $file): bool => ! (new ReflectionClass('App\\Mail\\'.basename($file, '.php')))->isAbstract(),
    );

    expect($mailables)->not->toBeEmpty();

    foreach ($mailables as $file) {
        // Every `name: 'value'` in the file, filtered down to the four a
        // Content can be built from. Matching the whole `new Content(...)` call
        // in one pattern needs it to survive line breaks, trailing commas and a
        // `with:` array, and a pattern that does all that is harder to read
        // than the two lines it saves.
        preg_match_all('/(\w+):\s*\'([^\']+)\'/', (string) file_get_contents($file), $arguments);

        $rendered = [];

        foreach ($arguments[1] as $index => $argument) {
            if (in_array($argument, ['text', 'html', 'view', 'markdown'], true)) {
                $rendered[$argument] = $arguments[2][$index];
            }
        }

        expect($rendered)->not->toBeEmpty(basename($file).' builds no Content');

        // **`toHaveKey`'s second argument is an expected value and not a
        // failure message**, so writing this the obvious way asserts that the
        // text view is named after the sentence describing the bug.
        expect(array_key_exists('text', $rendered))
            ->toBeTrue(basename($file).' sends no text part, so its alternative body is gone');

        foreach (array_keys($rendered) as $argument) {
            expect(in_array($argument, ['text', 'html'], true))
                ->toBeTrue(basename($file)." renders {$argument}:, which is neither text nor a checked HTML view");
        }

        if (isset($rendered['html'])) {
            expect(isHtmlMailView(view()->getFinder()->find($rendered['html'])))
                ->toBeTrue(basename($file)." renders markup from {$rendered['html']}, which is outside resources/views/mail/html");
        }
    }
});

/**
 * And every one of those views is somewhere the rule above is looking.
 *
 * The two rules meet here. Scanning `views/mail/` proves nothing about a
 * mailable that renders `text: 'emails.whatever'`, which would extend
 * `QueuedMail`, satisfy every other rule, and never be read at all. Naming a
 * view outside this directory is the one way left to escape #45, so it fails
 * here rather than passing quietly.
 *
 * **Residual, stated rather than left implied**: a view that `@include`s or
 * `@extends` one from outside `mail/` is still unchecked. Nothing does today.
 * `mail.html.frame`, which both designed messages extend since #81, is inside
 * it and so is scanned like any other. The fix if anything ever does is to
 * move the partial in.
 */
it('keeps every message its own view inside the directory that is checked', function () {
    foreach (glob(app_path('Mail/*.php')) as $file) {
        // Both parts, since #53. A confirmation that took its markup from
        // `emails.order-paid` would extend `QueuedMail`, carry a text part,
        // satisfy every other rule here, and never be read by the escaping
        // check at all.
        preg_match_all("/(?:text|html):\s*'([^']+)'/", (string) file_get_contents($file), $found);

        foreach ($found[1] as $view) {
            expect(view()->exists($view))->toBeTrue("{$view} does not exist");

            expect(in_array(view()->getFinder()->find($view), mailViews(), true))
                ->toBeTrue(basename($file)." renders {$view}, which is outside resources/views/mail");
        }
    }
});
