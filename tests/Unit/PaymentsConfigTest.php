<?php

use App\Enums\PaymentMethod;
use App\Services\Payments\ManualSettlement;
use App\Services\Payments\StripeCheckout;
use App\Services\Payments\StripeWallet;
use Illuminate\Support\Env;

/**
 * Which methods `config/payments.php` registers, given what is in the
 * environment.
 *
 * **This file evaluates the config file itself**, rather than asking the
 * container what config already loaded, because what is being tested is the
 * expression on the `stripe` line and that expression runs once at boot. A test
 * that set `payments.methods` by hand would prove nothing about it. It is a
 * unit test for the same reason: no application is booted, so the file is read
 * against the environment this test hands it rather than the one the suite
 * happens to have.
 *
 * The defect it pins, found 26 August 2026: `env('STRIPE_SECRET') === null` is
 * false for the empty string that `.env.example` ships, so Stripe was registered
 * on every laptop that had copied the example file, which is the opposite of
 * what the file's own comment promised.
 */
function paymentsConfiguredWith(?string $secret, ?string $webhookSecret): array
{
    $repository = Env::getRepository();

    foreach (['STRIPE_SECRET' => $secret, 'STRIPE_WEBHOOK_SECRET' => $webhookSecret] as $name => $value) {
        $value === null
            ? $repository->clear($name)
            : $repository->set($name, $value);
    }

    try {
        return require dirname(__DIR__, 2).'/config/payments.php';
    } finally {
        $repository->clear('STRIPE_SECRET');
        $repository->clear('STRIPE_WEBHOOK_SECRET');
    }
}

it('offers both stripe methods only where both keys are filled', function () {
    $config = paymentsConfiguredWith('sk_test_x', 'whsec_x');

    expect($config['methods'])->toBe([
        'manual' => ManualSettlement::class,
        'stripe' => StripeCheckout::class,
        'stripe_wallet' => StripeWallet::class,
    ]);
});

/**
 * **They are two methods and one configuration**, which is the point of testing
 * them together. A wallet with no card behind it, or a card with no wallet, is
 * a panel drawing one button where the frontend expects to decide. Both roads
 * reach the same Stripe account with the same secret, so nothing could
 * legitimately configure one and not the other.
 */
it('offers the wallet on exactly the same test as the hosted page', function () {
    foreach ([['sk_test_x', 'whsec_x'], ['', ''], [null, null], ['sk_test_x', null], [null, 'whsec_x']] as [$secret, $webhook]) {
        $methods = paymentsConfiguredWith($secret, $webhook)['methods'];

        expect(array_key_exists('stripe_wallet', $methods))->toBe(array_key_exists('stripe', $methods));
    }
});

/**
 * **The case the old check got wrong.** `.env.example` ships both of these
 * present and empty, so this is what a laptop actually looks like, not an
 * invented edge.
 */
it('does not offer stripe for keys that are present and empty', function () {
    $config = paymentsConfiguredWith('', '');

    expect($config['methods'])->toBe(['manual' => ManualSettlement::class]);
});

it('does not offer stripe for keys that are absent', function () {
    $config = paymentsConfiguredWith(null, null);

    expect($config['methods'])->toBe(['manual' => ManualSettlement::class]);
});

/**
 * **Half a configuration is not a configuration.**
 *
 * The secret alone is enough to charge a card and not enough to hear that the
 * charge succeeded, so an environment holding it without a webhook secret takes
 * money it can never verify. Hard rule 6 says a webhook may not change order
 * state without a verified signature. Refusing to offer the method is that rule
 * applied one step earlier, where it costs nobody anything.
 */
it('does not offer stripe with a secret but no webhook secret', function () {
    $config = paymentsConfiguredWith('sk_test_x', null);

    expect($config['methods'])->toBe(['manual' => ManualSettlement::class]);
});

it('does not offer stripe with a webhook secret but no secret', function () {
    $config = paymentsConfiguredWith(null, 'whsec_x');

    expect($config['methods'])->toBe(['manual' => ManualSettlement::class]);
});

/**
 * **The registry and `App\Enums\PaymentMethod` cannot drift apart.**
 *
 * The file is keyed by that enum's values from 29 August 2026, which is the
 * third place its own comment now says a new way to pay has to be added. A key
 * with no case behind it is a name the frontend would be handed by
 * `GET /api/v1/payment-methods` and could not switch on, and it would not fail
 * anywhere else: the registry refuses unknown names arriving from outside, and
 * says nothing about the ones it declares itself.
 */
it('registers no method name that is not a PaymentMethod case', function () {
    foreach ([['sk_test_x', 'whsec_x'], ['', ''], [null, null]] as [$secret, $webhook]) {
        foreach (array_keys(paymentsConfiguredWith($secret, $webhook)['methods']) as $name) {
            expect(PaymentMethod::tryFrom($name))->toBeInstanceOf(PaymentMethod::class);
        }
    }
});

/**
 * **And the other direction**, so a case nobody registered is caught too. A
 * fully configured environment offers all of them, which is what staging and
 * production are.
 */
it('registers every PaymentMethod case where the environment allows it', function () {
    $names = array_keys(paymentsConfiguredWith('sk_test_x', 'whsec_x')['methods']);

    expect($names)->toEqualCanonicalizing(array_column(PaymentMethod::cases(), 'value'));
});

/**
 * Hand settlement is never removed, per the file's own comment. Support needs it
 * after launch for the customer who pays some other way.
 */
it('offers hand settlement in every environment', function () {
    expect(paymentsConfiguredWith('sk_test_x', 'whsec_x')['methods'])->toHaveKey('manual')
        ->and(paymentsConfiguredWith(null, null)['methods'])->toHaveKey('manual');
});
