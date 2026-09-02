# Phase B, what needs a person

Findings 2, 3 and 4 of `milestone-5-phase-b-review.md`. None is fixable from a
checkout. Ordered by what unblocks what.

Decision of 26 August 2026: **phase B merges and deploys to staging first**,
because every item below needs a deployed environment with Stripe configured.
Production is the gate, not the merge.

---

## 1. Access you need before anything else

| What | Where to get it | Where it goes |
|---|---|---|
| Jennifer's Stripe account, **test mode**, Developer role | She invites you from Stripe dashboard, Settings, Team | Nothing to store. It is your login |
| `sk_test_...` secret key | Stripe dashboard, Developers, API keys, **test mode toggle on** | `STRIPE_SECRET` in the **staging** environment on Laravel Cloud. Never in the repo, never on your laptop |
| `pk_test_...` publishable key, same account | Same page, same toggle | Passed on the command line as `STAGING_STRIPE_PUBLISHABLE`. Not stored anywhere |
| Stripe CLI | `brew install stripe/stripe-cli/stripe`, then `stripe login` | Your machine |

Check the test mode toggle every time. A `pk_live_` is refused outright by the
walk, and a `sk_live_` in staging charges real cards.

---

## 2. Register the webhook endpoint at Stripe

Stripe dashboard, Developers, Webhooks, Add endpoint, **test mode**.

- URL: `https://staging-api.theworldtarot.com/api/v1/webhooks/stripe`
- Events: `payment_intent.succeeded` and `payment_intent.payment_failed`
- Stripe then shows a signing secret, `whsec_...`, **issued per endpoint**

That `whsec_` goes in `STRIPE_WEBHOOK_SECRET` on the staging environment. It is
not the same value as the one the CLI prints in step 3. Getting these two
crossed produces a signature refusal with nothing to tell it apart from a
tampered payload.

Redeploy staging after setting both variables.

---

## 3. Capture the Stripe webhook vector

Full procedure is in `tests/Fixtures/stripe/README.md`. Do not deviate from it,
and **do not compute the signature from our own code**. That is the M4
`BunnyStreamTest` failure that cost five days.

Short form, run locally:

1. `stripe listen --print-secret`, keep the `whsec_` it prints
2. `stripe listen --forward-to localhost:8000/api/v1/webhooks/stripe`
3. Place an order locally and pay with `4242 4242 4242 4242`, so the payload
   carries a real `metadata.order_id`
4. Capture the **raw body byte for byte** and the **`Stripe-Signature` header
   verbatim**

Three files, into `tests/Fixtures/stripe/`:

| File | Contents |
|---|---|
| `payment-intent-succeeded.json` | The raw body, no trailing newline added |
| `payment-intent-succeeded.sig` | The `Stripe-Signature` header, verbatim |
| `payment-intent-succeeded.secret` | The `whsec_` from `stripe listen --print-secret` |

Then record the capture date in the header of `StripeWebhookVectorTest`.

These are test mode values and are committed deliberately. A `whsec_` from live
mode must never be.

---

## 4. Run the card walk against staging

Prerequisites on the staging environment, from `tests/Staging/README.md`:

- `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET` set, per steps 1 and 2
- Products carry non-zero prices in the currency being walked, otherwise the
  order tests skip rather than fail
- An admin account, `php artisan app:create-admin` on the environment console
- A card with a finished film, for the Viewing Room half

Then, **from your own machine**, never from the environment console:

```bash
STAGING_URL=https://staging-api.theworldtarot.com \
STAGING_ORIGIN=https://staging.theworldtarot.com \
STAGING_EMAIL=you@example.com \
STAGING_CARD=1 \
STAGING_STRIPE_PUBLISHABLE=pk_test_... \
composer test:staging
```

A pass appearing within two minutes is the proof, because the reconcile sweep
leaves an order alone for its first fifteen. That wait is what proves the
endpoint is registered, reachable, and carrying a matching secret.

Then **replace the "The card walk has never been run" section** of
`tests/Staging/README.md` with what actually happened, pass or fail.

Cleanup afterwards, on the environment console:

```php
App\Models\Order::whereHas('customer', fn ($q) => $q->where('email', 'like', 'walk-%'))->delete();
App\Models\Customer::where('email', 'like', 'walk-%')->delete();
```

---

## 5. Measure the scheduler tick

`routes/console.php` runs `orders:reconcile` at `everyFiveMinutes()`, and says in
its own comment that the platform tick has never been measured. If Laravel Cloud
runs `schedule:run` hourly, that line is hourly whatever it says.

On staging, either read the platform's scheduler configuration in the Laravel
Cloud dashboard, or log a timestamp from a one-line scheduled command and read
the interval off the log over an hour.

Then correct `routes/console.php` to the truth, and correct the comment's
"a lost notification costs about twenty minutes" claim to whatever it actually
costs. This also unblocks scheduled gift delivery, which was deferred on it.

---

## 6. What still nobody can prove from here

Listed so they are not mistaken for done.

- **Apple Pay and Google Pay buttons appearing.** Needs a registered payment
  method domain at Stripe and a real device. Apple Pay fails by simply not
  appearing, which looks like nothing rather than like an error
- **The 3D Secure challenge itself.** The walk proves Stripe demands it. A
  person completes the iframe
- **A browser on the real frontend origin.** Waits on the frontend having pages
- **A mailbox.** `php artisan mail:check you@example.com --now` and then again
  without `--now`, then go and look
