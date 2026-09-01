<?php

use App\Mail\OrderPaid;
use App\Mail\ReadingToWrite;
use App\Mail\SetPasswordLink;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('gives Jennifer the address alone when the buyer gave no name', function () {
    $order = orderFrom(null, 'jennifer@example.com');

    Mail::to(config('mail.notify.fulfillment'))->send(new ReadingToWrite($order));

    // **She still has to be able to write back**, so the address is the part
    // that cannot go missing. `From: <jennifer@example.com>` reads as a bug in
    // our software rather than as a buyer who was never asked.
    expect(bodyMentioning('has been paid'))
        ->toContain('From: jennifer@example.com')
        ->not->toContain('From:  <');
});

it('gives Jennifer the buyer name exactly as the buyer typed it', function () {
    $order = orderFrom("Miss Jodie O'Reilly", 'jodie@example.com');

    Mail::to(config('mail.notify.fulfillment'))->send(new ReadingToWrite($order));

    // The question is the customer's own prose and the most likely place in any
    // of these messages to hold a character Blade would have escaped.
    expect(bodyMentioning('has been paid'))
        ->toContain("From: Miss Jodie O'Reilly <jodie@example.com>")
        ->toContain("What of Jodie's move & the <plan>?");
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
 * **Residual, stated rather than left implied**: a view that `@include`s one
 * from outside `mail/` is still unchecked. Nothing does today, and the fix if
 * anything ever does is to move the partial in.
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
