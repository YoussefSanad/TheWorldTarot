<?php

use App\Services\Payments\ManualSettlement;
use App\Services\Payments\StripeCheckout;
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

it('offers stripe only where both keys are filled', function () {
    $config = paymentsConfiguredWith('sk_test_x', 'whsec_x');

    expect($config['methods'])->toBe([
        'manual' => ManualSettlement::class,
        'stripe' => StripeCheckout::class,
    ]);
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
 * Hand settlement is never removed, per the file's own comment. Support needs it
 * after launch for the customer who pays some other way.
 */
it('offers hand settlement in every environment', function () {
    expect(paymentsConfiguredWith('sk_test_x', 'whsec_x')['methods'])->toHaveKey('manual')
        ->and(paymentsConfiguredWith(null, null)['methods'])->toHaveKey('manual');
});
