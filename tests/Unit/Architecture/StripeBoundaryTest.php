<?php

/**
 * Stripe stays behind the payment seam. CLAUDE.md section 4: "a vendor's own
 * concepts appearing in [the interface] means the seam is in the wrong place",
 * and section 2: Stripe through `stripe/stripe-php` behind `PaymentGateway`.
 *
 * **Every allowlist below records today's shape (10 October 2026) so it cannot
 * widen, and none of it is a recommendation.** Adding a legitimate entry is a
 * one-line edit that shows up in review, which is the point.
 *
 * docs/plans/arch-tests.md, step 1. Each test name contains "Stripe" so that
 * `./vendor/bin/pest --filter="Stripe"` selects them.
 */

// Only two controllers may name Stripe at all. Every other controller reaches
// payment through App\Contracts\PaymentGateway or an Action.
//
// **`ignoring()` matches by PREFIX**, class names included, so this also
// ignores any class whose name starts with one of these two. No such class
// exists today. It is why the per-class rules below are a source scan and not
// `arch()`: the same prefix matching made `ignoring('Stripe\Event')` let
// `Stripe\Events\...` through (seen in a probe, 10 October 2026).
arch('Stripe is named by no controller except the webhook and the payment status one')
    ->expect('App\Http\Controllers')
    ->not->toUse('Stripe')
    ->ignoring([
        'App\Http\Controllers\Api\V1\StripeWebhookController',
        'App\Http\Controllers\Api\V1\OrderPaymentStatusController',
    ]);

/**
 * Every `Stripe\...` name written in a source file, as a sorted unique list.
 *
 * Reads `use` imports and fully qualified `\Stripe\X` references alike, so
 * `\Stripe\Webhook::class` is seen as well as `use Stripe\Webhook;`. It is a
 * text scan, so a Stripe name in a comment counts too, which is deliberately
 * strict. A group import such as `use Stripe\Exception\{A, B}` yields a name
 * ending in a backslash and so fails the equality, forcing one import per line.
 *
 * @return list<string>
 */
function stripeNamesIn(string $relativePath): array
{
    $source = file_get_contents(dirname(__DIR__, 3).'/'.$relativePath);

    expect($source)->toBeString();

    preg_match_all('/(?<![\w\\\\])\\\\?(Stripe\\\\[\w\\\\]+)/i', $source, $matches);

    $names = array_values(array_unique($matches[1]));
    sort($names);

    return $names;
}

// The webhook controller uses these as type checks and for the exceptions
// `StripeSignature::verify` throws. Verification itself lives in
// `App\Payments\StripeSignature`, which is why `Stripe\Webhook` is NOT listed:
// the controller must never verify (or construct events) by itself.
//
// Set equality, not `arch()`, so no sibling such as `Stripe\Events\...` or
// `Stripe\EventData\...` can ride in on an allowed name. Equality also fails
// when an entry stops being used, so the list cannot go stale.
test('Stripe classes named by the webhook controller are exactly the five it already uses', function (): void {
    expect(stripeNamesIn('app/Http/Controllers/Api/V1/StripeWebhookController.php'))->toBe([
        'Stripe\Dispute',
        'Stripe\Event',
        'Stripe\Exception\SignatureVerificationException',
        'Stripe\Exception\UnexpectedValueException',
        'Stripe\PaymentIntent',
    ]);
});

// The status controller only catches Stripe's API failure. It must not hold a
// client or any Stripe object; reading status goes through ReadPaymentStatus.
test('Stripe classes named by the payment status controller are exactly ApiErrorException', function (): void {
    expect(stripeNamesIn('app/Http/Controllers/Api/V1/OrderPaymentStatusController.php'))->toBe([
        'Stripe\Exception\ApiErrorException',
    ]);
});

/**
 * Every file under `app/` that writes a Stripe name, mapped to the Stripe
 * names it writes. Paths are relative to the repo root, sorted.
 *
 * Exact-match, like the rules above, because `ignoring()` and `not->toUse()`
 * match by prefix (a probe on 10 October 2026 showed it), so an exact class
 * allowlist cannot be written with `arch()`.
 *
 * @return array<string, list<string>>
 */
function stripeNamesByFileInApp(): array
{
    $root = dirname(__DIR__, 3);
    $found = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($root) + 1);
        $names = stripeNamesIn($relative);

        if ($names !== []) {
            $found[$relative] = $names;
        }
    }

    ksort($found);

    return $found;
}

// Outside the two controllers, these ten classes are the only ones in `App`
// that may name a Stripe class (twelve files with the controllers). Exact
// files, not namespaces, so a new `App\Services\Payments\Anything` or an
// `App\Models\Order` gaining a `Stripe\PaymentIntent` goes red (decision 5).
// This also covers `App\Contracts`: CLAUDE.md section 4, a vendor's concepts in
// the interface mean the seam is in the wrong place.
//
//  - AppServiceProvider: binds the one StripeClient (AdminPanelProvider does not
//    need Stripe, so the whole `App\Providers` namespace is not allowed)
//  - StripeCheckout, StripeWallet, BuyerFromCheckoutSession, BuyerFromLatestCharge:
//    the adapters (not PaymentMethods, UnknownPaymentMethod; ~~ManualSettlement~~
//    was retired on 22 October 2026)
//  - StripeSignature, StripePayment, PaymentStatus: Stripe types only
//  - ReadPaymentStatus, ReconcileOrders: read from Stripe through the client
//
// Set equality, so an entry that stops using Stripe must be removed too.
test('Stripe is named by exactly the twelve classes in App that already name it', function (): void {
    expect(array_keys(stripeNamesByFileInApp()))->toBe([
        'app/Actions/ReadPaymentStatus.php',
        'app/Console/Commands/ReconcileOrders.php',
        'app/Http/Controllers/Api/V1/OrderPaymentStatusController.php',
        'app/Http/Controllers/Api/V1/StripeWebhookController.php',
        'app/Payments/PaymentStatus.php',
        'app/Payments/StripePayment.php',
        'app/Payments/StripeSignature.php',
        'app/Providers/AppServiceProvider.php',
        'app/Services/Payments/BuyerFromCheckoutSession.php',
        'app/Services/Payments/BuyerFromLatestCharge.php',
        'app/Services/Payments/StripeCheckout.php',
        'app/Services/Payments/StripeWallet.php',
    ]);
});

// Tighter than the rule above: only these seven may hold the client.
// StripeSignature, StripePayment and PaymentStatus may name Stripe types but
// not `Stripe\StripeClient`. "Only constructed in the provider" is what is
// true, and a text scan cannot tell construction from a type hint.
test('Stripe StripeClient is named by exactly the seven classes that already take it', function (): void {
    $withClient = array_keys(array_filter(
        stripeNamesByFileInApp(),
        fn (array $names): bool => in_array('stripe\stripeclient', array_map('strtolower', $names), true),
    ));

    expect($withClient)->toBe([
        'app/Actions/ReadPaymentStatus.php',
        'app/Console/Commands/ReconcileOrders.php',
        'app/Providers/AppServiceProvider.php',
        'app/Services/Payments/BuyerFromCheckoutSession.php',
        'app/Services/Payments/BuyerFromLatestCharge.php',
        'app/Services/Payments/StripeCheckout.php',
        'app/Services/Payments/StripeWallet.php',
    ]);
});

// **Known limits.** The scan reads text, so a name assembled in a string
// (`'Stripe'.'\\Webhook'`) or an alias of the Stripe namespace itself
// (`use Stripe as S;` then `S\Webhook`) is not seen, and neither is a Stripe
// class reached only through a method's return type from another class. An
// alias of a single class, `use Stripe\Webhook as W`, is still caught, since
// the import line names it. Those blind spots are deliberate evasion rather
// than drift, and review is what catches them.
