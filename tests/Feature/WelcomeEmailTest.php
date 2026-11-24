<?php

use App\Actions\ClaimAccount;
use App\Actions\MarkOrderPaid;
use App\Enums\ClaimOutcome;
use App\Enums\PasswordLink;
use App\Enums\ProductKey;
use App\Mail\OrderPaid;
use App\Mail\SetPasswordLink;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Support\FrontendUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

/*
| The welcome email, issue #135, 24 November 2026.
|
| The client restated the pass flow: the buyer pays, lands in the Viewing Room
| signed in, and **a welcome email arrives separately** with a secure link to
| set a password for future visits. So the link moves out of the receipt
| (#108 put it there, decision 6 of #126 kept it there; both reversed) into
| `SetPasswordLink`, revived for the purpose.
|
| **A repeat buyer gets the same email.** Same subject, same words, with the
| reset page behind the button, because `/set-password` refuses an account that
| already has a password. That is decision 1 of #126 reversed on the same day.
|
| What is asserted here is what leaves `MarkOrderPaid`: which messages, to
| whom, in which order, and whether the link in them works against the broker.
| The headers are `MailHeadersTest`'s, the escaping `LinkEmailsHtmlTest`'s and
| `MailBodyTest`'s.
*/

beforeEach(function () {
    Mail::fake();

    // The default under test is http://localhost:3000, which the https link
    // match below would never see.
    config()->set('frontend.url', 'https://theworldtarot.com');
});

function settleForWelcome(Order $order): void
{
    expect(app(MarkOrderPaid::class)($order))->toBeTrue();
}

function aPassOrderFor(Customer $customer): Order
{
    return Order::factory()
        ->for($customer)
        // One pass row however many orders a test makes, since `key` is unique.
        ->forProduct(Product::where('key', ProductKey::ViewingRoomPass)->first() ?? Product::factory()->pass()->create())
        ->create();
}

/** A rendered part of a mailable, raw for text, as the transport would get it. */
function welcomeParts(Mailable $mail): array
{
    $content = $mail->content();

    return [
        'text' => view($content->text, $content->with)->render(),
        'html' => $mail->render(),
    ];
}

/**
 * The password link in a text part, as the page it opens and its query.
 *
 * @return array{page: string, token: string, email: string, next: ?string}|null
 */
function passwordLinkIn(string $text): ?array
{
    if (preg_match('#https://\S+/(set-password|reset-password)/\?\S+#', $text, $found) !== 1) {
        return null;
    }

    parse_str((string) parse_url($found[0], PHP_URL_QUERY), $query);

    return [
        'page' => $found[1],
        'token' => $query['token'] ?? '',
        'email' => $query['email'] ?? '',
        'next' => $query['next'] ?? null,
    ];
}

/*
| Two messages for a pass order, in this order.
*/

it('sends an unclaimed pass buyer the receipt and a welcome email whose set-password link works', function () {
    $buyer = Customer::factory()->create(['email' => 'first@example.com', 'password' => null]);

    settleForWelcome(aPassOrderFor($buyer));

    Mail::assertQueued(OrderPaid::class, 1);
    Mail::assertQueued(SetPasswordLink::class, 1);
    Mail::assertQueued(SetPasswordLink::class, fn (SetPasswordLink $mail): bool => $mail->hasTo('first@example.com'));

    $link = passwordLinkIn(welcomeParts(welcomesQueued()[0])['text']);

    expect($link)->not->toBeNull()
        ->and($link['page'])->toBe('set-password')
        ->and($link['email'])->toBe('first@example.com')
        ->and($link['next'])->toBe('/viewing-room/')
        ->and(app(ClaimAccount::class)('first@example.com', $link['token'], 'a-brand-new-password'))
        ->toBe(ClaimOutcome::Claimed);
});

it('sends a claimed pass buyer the receipt and the same welcome email, with a reset link that works', function () {
    $buyer = Customer::factory()->create(['email' => 'repeat@example.com', 'password' => 'a-long-enough-password']);

    settleForWelcome(aPassOrderFor($buyer));

    Mail::assertQueued(OrderPaid::class, 1);
    Mail::assertQueued(SetPasswordLink::class, 1);

    $link = passwordLinkIn(welcomeParts(welcomesQueued()[0])['text']);

    expect($link)->not->toBeNull()
        ->and($link['page'])->toBe('reset-password')
        ->and($link['email'])->toBe('repeat@example.com')
        ->and($link['next'])->toBe('/viewing-room/');

    $this->withHeader('Origin', 'http://localhost')->postJson('/api/v1/reset-password', [
        'token' => $link['token'],
        'email' => 'repeat@example.com',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ])->assertOk();

    expect(Hash::check('a-brand-new-password', $buyer->fresh()->password))->toBeTrue();
});

it('words the welcome email identically for a first and a repeat buyer, but for the link', function () {
    $first = Customer::factory()->create(['name' => 'Jodie', 'email' => 'same@example.com', 'password' => null]);

    settleForWelcome(aPassOrderFor($first));
    $first->forceFill(['password' => Hash::make('a-long-enough-password')])->save();
    settleForWelcome(aPassOrderFor($first->fresh()));

    [$set, $reset] = welcomesQueued();

    $setUrl = $set->content()->with['url'];
    $resetUrl = $reset->content()->with['url'];

    expect($setUrl)->toContain('/set-password/')
        ->and($resetUrl)->toContain('/reset-password/')
        ->and($set->envelope()->subject)->toBe($reset->envelope()->subject);

    // Each body with its own link taken out, in both parts. What is left has to
    // be the same document.
    $partsOf = fn (SetPasswordLink $mail, string $url): array => [
        str_replace($url, 'LINK', welcomeParts($mail)['text']),
        str_replace(e($url), 'LINK', welcomeParts($mail)['html']),
    ];

    expect($partsOf($set, $setUrl))->toBe($partsOf($reset, $resetUrl));
});

it('queues the welcome email after the receipt', function () {
    settleForWelcome(aPassOrderFor(Customer::factory()->create(['password' => null])));

    // The fake keeps every queued message in one list, in the order they were
    // queued. Read through a bound closure, because it offers no getter.
    $queued = (fn (): array => $this->queuedMailables)->call(Mail::getFacadeRoot());
    $order = array_map(fn (Mailable $mail): string => $mail::class, $queued);

    expect($order)->toBe([OrderPaid::class, SetPasswordLink::class]);
});

it('sends no welcome email when the settlement rolls back with the caller', function () {
    $order = aPassOrderFor(Customer::factory()->create(['password' => null]));

    expect(fn () => DB::transaction(function () use ($order): void {
        app(MarkOrderPaid::class)($order);

        throw new RuntimeException('the caller changed its mind');
    }))->toThrow(RuntimeException::class);

    Mail::assertNothingQueued();
});

/*
| The receipt carries no credential any more.
*/

it('sends a receipt with no set or reset link, and with the Viewing Room button, to every pass buyer', function (?string $password) {
    settleForWelcome(aPassOrderFor(Customer::factory()->create(['password' => $password])));

    $receipt = Mail::queued(OrderPaid::class)->first();
    ['text' => $text, 'html' => $html] = welcomeParts($receipt);

    expect(passwordLinkIn($text))->toBeNull()
        ->and($text)->not->toContain('/set-password/')
        ->and($text)->not->toContain('/reset-password/')
        ->and($html)->not->toContain('/set-password/')
        ->and($html)->not->toContain('/reset-password/')
        ->and($html)->not->toContain('Set your password')
        ->and($text)->toContain(FrontendUrl::viewingRoom())
        ->and($html)->toContain(e(FrontendUrl::viewingRoom()))
        ->and($html)->toContain('Enter the Viewing Room');
})->with([
    'unclaimed' => null,
    'claimed' => 'a-long-enough-password',
]);

/*
| Only the pass earns one (#51).
*/

it('sends a reading buyer no welcome email and mints no token', function (?string $password) {
    $order = Order::factory()
        ->for(Customer::factory()->create(['password' => $password]))
        ->forProduct(Product::factory()->create(['key' => ProductKey::MonthAhead]))
        ->create();

    settleForWelcome($order);

    Mail::assertQueued(OrderPaid::class, 1);
    Mail::assertNotQueued(SetPasswordLink::class);

    expect(DB::table(config('auth.passwords.customers.table'))->count())->toBe(0);
})->with([
    'unclaimed' => null,
    'claimed' => 'a-long-enough-password',
]);

/*
| One token per address, as before.
*/

it('kills the first welcome link when a second pass order settles, and the second one works', function () {
    $buyer = Customer::factory()->create(['email' => 'twice@example.com', 'password' => null]);

    settleForWelcome(aPassOrderFor($buyer));
    settleForWelcome(aPassOrderFor($buyer));

    Mail::assertQueued(SetPasswordLink::class, 2);

    [$first, $second] = array_map(
        fn (SetPasswordLink $mail): array => passwordLinkIn(welcomeParts($mail)['text']),
        welcomesQueued(),
    );

    expect($second['token'])->not->toBe($first['token'])
        ->and(Password::broker('customers')->tokenExists($buyer, $first['token']))->toBeFalse()
        ->and(app(ClaimAccount::class)('twice@example.com', $second['token'], 'a-brand-new-password'))
        ->toBe(ClaimOutcome::Claimed);
});

/*
| The kind of link is decided when the token is minted, never at send time.
*/

it('prints the kind of link it was minted as, whatever the account looks like by the time it sends', function () {
    $customer = Customer::factory()->create(['password' => 'a-long-enough-password']);

    $reset = new SetPasswordLink($customer, 'tok-reset', ProductKey::ViewingRoomPass, PasswordLink::Reset);
    $set = new SetPasswordLink($customer, 'tok-set', ProductKey::ViewingRoomPass, PasswordLink::Set);

    expect($reset->content()->with['url'])->toBe(FrontendUrl::resetPassword($customer->email, 'tok-reset', ProductKey::ViewingRoomPass))
        ->and($set->content()->with['url'])->toBe(FrontendUrl::setPassword($customer->email, 'tok-set', ProductKey::ViewingRoomPass));
});
