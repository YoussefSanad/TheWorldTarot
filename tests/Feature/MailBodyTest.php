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
 */
function orderFrom(string $name, string $email): Order
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
    $views = mailViews();

    expect($views)->not->toBeEmpty();

    foreach ($views as $view) {
        foreach (file($view, FILE_IGNORE_NEW_LINES) as $number => $line) {
            // `{{--` is a Blade comment and escapes nothing. `@{{` is an escaped
            // brace that Blade prints literally.
            expect(preg_match('/(?<!@)\{\{(?!--)/', $line))
                ->toBe(0, basename($view).':'.($number + 1).' escapes: '.trim($line));
        }
    }
});

/**
 * Every message is text, which is the whole reason a raw echo is safe.
 *
 * **This is the precondition the rule above rests on, and it was unenforced.**
 * Without it the fix for #45 is one careless mailable away from being worse
 * than the defect it replaced: `{!! !!}` in a view somebody renders as `html:`
 * or `view:` puts a customer's own name and their own typed question into a
 * document as markup, and a cosmetic bug becomes an injection. The two are only
 * ever correct together, so they are asserted together.
 *
 * The day a message is genuinely meant to be HTML, this is the test that has to
 * be told about it, and telling it means giving that view escaping back rather
 * than deleting the line.
 */
it('renders every message as text, which is what makes a raw echo safe', function () {
    $mailables = array_filter(
        glob(app_path('Mail/*.php')),
        fn (string $file): bool => ! (new ReflectionClass('App\\Mail\\'.basename($file, '.php')))->isAbstract(),
    );

    expect($mailables)->not->toBeEmpty();

    foreach ($mailables as $file) {
        preg_match_all('/new Content\(\s*(\w+):/', (string) file_get_contents($file), $found);

        expect($found[1])->not->toBeEmpty(basename($file).' builds no Content');

        foreach ($found[1] as $argument) {
            expect($argument)->toBe('text', basename($file)." renders {$argument}:, which escapes nothing and is not text");
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
        preg_match_all("/new Content\(\s*text:\s*'([^']+)'/", (string) file_get_contents($file), $found);

        foreach ($found[1] as $view) {
            expect(view()->exists($view))->toBeTrue("{$view} does not exist");

            expect(in_array(view()->getFinder()->find($view), mailViews(), true))
                ->toBeTrue(basename($file)." renders {$view}, which is outside resources/views/mail");
        }
    }
});
