# The staging walk

Integration tests that run against a **deployed** environment over the network. They are step 9 of `docs/plans/milestone-5-plan.md`, written as code instead of as a person clicking, because the frontend does not exist yet and waiting for it would leave the milestone unproven.

They are not part of `composer test` and never should be. The ordinary suite proves our code does what we meant. This one proves an environment answers the way a customer needs it to, and those are different questions.

> **Run it from your own machine, pointed at a deployed environment. Never on the environment's console.**
>
> Deploys run `composer install --no-dev` and Pest is a dev dependency, so `vendor/bin/pest` does not exist on staging or production and the run dies with `Could not open input file: vendor/bin/pest`.
>
> That is not a missing step, it is the design. This suite is an HTTP client and needs nothing installed on the far end. It walks in through the front door exactly as a customer does, which is the only reason its results mean anything.

```bash
STAGING_URL=https://staging-api.theworldtarot.com \
STAGING_ORIGIN=https://staging.theworldtarot.com \
composer test:staging
```

The card walk is off unless you ask for it, because it moves money at Stripe:

```bash
STAGING_URL=https://staging-api.theworldtarot.com \
STAGING_ORIGIN=https://staging.theworldtarot.com \
STAGING_CARD=1 \
STAGING_STRIPE_PUBLISHABLE=pk_test_... \
composer test:staging
```

The Viewing Room walk buys its pass through Stripe too, since 22 October 2026, and shares the wallet walk's switch and key. ~~It used to stop and wait for you to mark an order paid in the panel, under `STAGING_INTERACTIVE=1`.~~

```bash
STAGING_URL=https://staging-api.theworldtarot.com \
STAGING_WALLET=1 \
STAGING_STRIPE_PUBLISHABLE=pk_test_... \
composer test:staging --filter=ViewingRoomWalk
```

The gift walk is off for the same reason and one more: **it stops and waits for
you.** Set `STAGING_EMAIL` to an address you can read, because the code is
mailed to the recipient and never to the buyer, and reading it out of that inbox
is the only way this walk can continue.

```bash
STAGING_URL=https://staging-api.theworldtarot.com \
STAGING_ORIGIN=https://staging.theworldtarot.com \
STAGING_EMAIL=you@example.com \
STAGING_GIFT=1 \
STAGING_STRIPE_PUBLISHABLE=pk_test_... \
composer test:staging
```

Without `STAGING_URL` every test skips itself with the reason attached, so a run that says "skipped" is telling you it was never configured rather than that it passed.

## Settings

| Variable | What it does |
|---|---|
| `STAGING_URL` | **Required.** The API to walk. Refuses any host that does not look like staging or local unless `STAGING_I_KNOW_WHAT_THIS_IS=1` |
| `STAGING_ORIGIN` | The exact origin the website will be served from. Cookie authentication and CORS are both keyed on it, so without it those checks prove much less |
| `STAGING_EMAIL` | **Your own address.** Every walk account becomes `you+walk-...@yours`, so each is a separate customer and all the mail lands in one inbox |
| `STAGING_EMAIL_DOMAIN` | The blunter older form: a whole domain you can receive at. `STAGING_EMAIL` wins if both are set |
| ~~`STAGING_INTERACTIVE=1`~~ | ~~Runs the purchase walk, which waits for you to mark an order paid in the panel.~~ No longer gates anything as of 22 October 2026: the Viewing Room purchase walk runs under `STAGING_WALLET=1` |
| `STAGING_CARD=1` | Runs the **card walk**, which takes real Stripe test payments against the environment. Needs the key below |
| `STAGING_WALLET=1` | Runs the **wallet walk**, `tests/Staging/WalletPaymentWalkTest.php`, which confirms a real test-mode PaymentIntent minted by `stripe_wallet`. Needs the same key. **It proves nothing about identity**: no headless client opens a wallet sheet, so the order it places is signed in and settlement never has to ask who paid. Whether a real wallet fills `latest_charge.billing_details.email` is issue #44, and it needs a device |
| `STAGING_STRIPE_PUBLISHABLE` | The **test mode** publishable key for the Stripe account this environment is configured with. A `pk_live_` key is refused outright |
| `STAGING_THROTTLE_TESTS=1` | Runs the forgotten-password rate limit test, which locks your address out for a minute |
| `STAGING_GIFT=1` | Runs the **gift walk**, `tests/Staging/GiftWalkTest.php`, B10. Three of its four tests move no money and need no Stripe key, so this alone will tell you whether an environment carries gifting at all. **The fourth needs a person**: the buyer never receives the code, so there is no response anywhere to read one out of, and the walk waits while you fetch it from the recipient's inbox |
| `STAGING_GIFT_KEY` | Which reading is given away. Default `month-ahead`, because it is the giftable reading with a page on the website, so a code this walk mints is one somebody can follow through to a rendered `/redeem/`. Deliberately not `STAGING_READING_KEY`, which defaults to `one-card` and cannot be gifted |
| `STAGING_UNGIFTABLE_KEY` | Which reading proves the refusal. Default `one-card`, which takes a question and cannot be gifted, and is the product `allows_question` and `is_giftable` are independent on |
| `STAGING_GIFT_CODE` | A code you already hold, which skips the wait. **For one case only:** a run that failed after the payment settled and before the code was spent. Re-running the whole walk otherwise costs another real payment. A code that has already been redeemed is no use, because redeeming it again is a 409 by design |
| `STAGING_LOCALE`, `STAGING_CURRENCY`, `STAGING_READING_KEY`, `STAGING_PASS_KEY` | Defaults are `en`, `GBP`, `one-card`, `viewing-room-pass` |

## Before it can pass

- **Products need prices.** They seed at zero in every currency and an order refuses a line priced at zero. The order tests skip with an explanation rather than failing, so a skip here means the catalogue is not ready to sell, not that anything is broken
- **A card needs a finished film**, or the Viewing Room tests have nothing to walk
- **An admin account**, since settling an order is done in the panel. `php artisan app:create-admin` on the environment console
- **For the card walk and the wallet walk alike**: **both** `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET` set on the environment, or neither `stripe` nor `stripe_wallet` is offered at all and the pay endpoint refuses both. They stand or fall together, on one test, because both roads reach the same Stripe account through the same secret. Both, since 26 August 2026: a secret without a webhook secret would take money it could never confirm, so the registry refuses to offer the method at all. A webhook endpoint registered at Stripe pointing at `/api/v1/webhooks/stripe` on that environment supplies the `whsec_`, and it is issued per endpoint rather than per account

## What it proves

- The environment is up, debug is off, the API documentation is not public, and errors come back as JSON
- The website's origin is allowed with credentials, and an unknown origin is not
- **The session cookie is scoped to the shared root domain**, is `Secure` and `HttpOnly`, and a session survives from one request to the next
- **A request carrying the website's origin is treated as stateful**, which is the `SANCTUM_STATEFUL_DOMAINS` setting nothing had ever exercised. If it is wrong, the test says so in those words
- A write without the CSRF header is refused with 419
- Accounts: registering, signing in, `me`, signing out one device without signing out the others
- **A wrong password and an unknown email are byte for byte identical**, so the endpoint cannot be asked who has an account
- Signing in locks out after five wrong answers, as a 429 with `Retry-After` rather than a 422
- **A customer token cannot reach the admin panel**, which is the guard isolation that had never been walked from the customer side
- A forgotten password request answers identically whether or not the address exists
- A claim link cannot be used on an account that already has a password
- Orders: recorded as pending with a `pay_token` and nothing collected, totals summed from lines, money as integers, questions refused where they do not belong, one bad line refusing the whole order, an unsupported currency refused
- **The whole purchase path**: no access, 403 on a film, buy the pass, ~~settle it in the panel, access appears~~ (struck through 22 October 2026: it now pays through Stripe's wallet road with a test card under `STAGING_WALLET=1`, and nobody opens the panel) the pass appears by itself, and then the film plays through **manifest, rendition and segment** with a tampered token refused

- **A real card payment, end to end, with nobody in the panel**: an order placed, a client secret issued, a Stripe test card confirmed against it the way a browser confirms it, and the pass appearing by itself. A declined card leaves the order payable and a second card settles it. A card requiring 3D Secure is genuinely challenged rather than quietly charged

- **A present, end to end, and it is the only walk where the money and the asking are two different people**: `is_giftable` answered for every product, a gift refused on a reading that cannot be given away rather than silently dropped, a gift line accepted carrying no code, a code minted by settlement, the same code looked up twice without being spent, and then redeemed in a second session by somebody holding no account, no cookie and no token, at an address that is deliberately not the one the present was posted to. A second submit of the same code is a 409, so the reading is asked for once

The card walk is the only thing in this repository that proves payment works. The feature suite drives a substituted `StripeClient`, which agrees with our code whatever our code does, and the captured signature vector proves the format of one body and nothing about whether an endpoint is reachable. **A pass appearing within two minutes can only be the webhook**, because the reconcile sweep leaves an order alone for its first fifteen, so the wait itself proves the endpoint is registered, reachable, and carrying a matching signing secret.

The gift walk is the only one that stops and asks for something. **The buyer never receives the code**, so nothing on the wire can hand one to a test, and a walk that could fetch it would be describing a different system. Reading it out of the recipient's inbox is a person's job and always will be. What the wait buys is that a code failing to arrive is itself the finding: either the payment did not settle on this environment, or it did and the gift mail went nowhere.

That last one is deliberate. A 200 from the video endpoint is not evidence of anything: a token scoped to the manifest rather than the directory answers 200 for `playlist.m3u8` and 403 for everything it points at. That is what M4 shipped, and following the manifest down is the only way to tell the difference.

## First run against staging, 13 August 2026

`staging-api.theworldtarot.com`, carrying M5 phase A as merged on 12 August: **24 passed, 6 failed, and all six failures were the one defect below.**

The result worth writing down: **cookie authentication across `.theworldtarot.com` works**, which had never been exercised by anybody since it was configured in M1. The CSRF cookie is issued, the session cookie is scoped to the root domain and is `Secure` and `HttpOnly`, the site origin is treated as stateful, a session survives between requests, and a write without the CSRF header is refused. The guard isolation holds from the customer side too.

## The defect it found, fixed 13 August 2026

Fixed in `routes/api.php` by giving every throttle its own prefix, and pinned in `PasswordResetTest`. **Until that ships, staging still carries the bug**, so these six tests go on failing there and the run is not a clean one yet.



**Every inline throttle in `routes/api.php` shares one counter per address.** Laravel keys a guest's rate limiter on the domain and the IP, and never on the route, so `register`, `login`, `forgot-password`, `reset-password`, `set-password`, `orders`, `cards/draw` and the Bunny webhook all increment the same number. The strictest limit among them then applies to the sum of all of them.

Measured against a running instance, from a clean minute:

```
six requests to /api/v1/orders          422 422 422 422 422 422
one request to /api/v1/forgot-password  429     <- first call of the minute
```

What it means for a customer: somebody who places an order, or gets a form wrong a few times, is then refused a password reset for the rest of the minute with no way to tell why. It is worst on exactly the flow this milestone exists to serve.

Invisible to the feature suite, because each test there makes one or two requests against a fresh array cache. It is only visible from outside, against one process, which is the argument for this suite in one paragraph.

**The fix is one word per route**, the third argument to the throttle middleware, which is a prefix for the limiter key:

```php
->middleware('throttle:6,1,forgot-password')
```

`it does not spend the password reset budget on unrelated routes` in `PasswordsTest.php` is the test for it, and it fails until the prefixes are added. Five other tests fail with a pointed message from the same cause.

## Run of 23 August 2026, and the defect it found in itself

`staging-api.theworldtarot.com`, carrying the `FilmSource` refactor that moved the Viewing Room onto the seam the homepage reveal already crossed. **Passed, including the interactive purchase**: an order settled by hand granted the pass, and the film played down through master manifest, rendition and segment, with a tampered token refused.

It took two runs, and the first one failed for a reason worth keeping.

**`it answers 404 for a card with nothing to watch` had never executed before.** It was written on 13 August and skipped every run since, because no card on staging had a finished film, so its totals looked clean and nobody noticed what it was asking for. This was the first environment to carry both a card with a film and a card without one.

What it asked for was wrong, and dangerously so. It used `Target::sharedAccount()`, which is the account the rest of this file relies on **not** holding a pass, and expected 404. The pass is asked about before the card is ever looked up, so that account gets 403 for every id. For the test to pass, the gate would have to run second, and then a card with no film would answer 404 while a card with one answered 403. That difference is an oracle: anybody with a free account could walk the ids and learn exactly which films exist, without paying for any of them.

**No application code changed.** The ordering has been in `CardVideoController` since `d23fe09` and is documented there and in `.claude/api.md`. The 403 case joined `it refuses a film to somebody who has not paid for one`, which now proves a card with a film and a card without one answer **identically**. The 404 case moved into the purchase walk, where an account that has actually paid exists to tell the two apart.

The general lesson is in `docs/CHECKPOINT.md` under traps: a skipped test reads as a passing one, and when a test and a documented security decision disagree, find out which is older before touching either.

## The card walk, first run 26 August 2026

**It passed.** Written 26 August, executed the same day, against
`staging-api.theworldtarot.com` carrying phase B as merged that morning.

The result worth writing down: **a customer can hand over a card and receive
what they bought, with nobody in the panel.** An order placed, a client secret
issued, `pm_card_visa` confirmed against Stripe the way a browser confirms it,
and the pass appearing by itself well inside two minutes. The sweep does not
touch an order for fifteen, so **that can only have been the webhook**, which
makes it the first evidence anywhere that the endpoint is registered for this
environment, reachable from outside, and carrying a matching signing secret.

That retires the sentence this section used to carry. **Evidence now exists
that this system can take a payment.** On staging. Production is still unwalked
by anybody.

It took four runs, and the three failures are worth keeping, because none was in
the application and all three were in configuration or in this suite:

1. **The pay endpoint answered 422**, reporting Stripe as unconfigured while the
   keys were plainly set. The variables were named `STRIPE_SECRET_KEY` and
   `STRIPE_PUBLISHABLE_KEY` on the environment, and `config/payments.php` reads
   `STRIPE_SECRET`. `blank()` cannot tell a misspelled variable from an absent
   one, so a name that is nearly right looks exactly like a key that is missing.
   **The backend reads no publishable key at all**: it is a browser credential
   and reaches this suite as `STAGING_STRIPE_PUBLISHABLE`
2. **Stripe answered 401, `Invalid API Key provided: pk_test_...`.** That is the
   placeholder out of this file, passed through verbatim. The guard above checks
   the `pk_test_` prefix, which the placeholder satisfies, so the one wrong
   value a person is most likely to supply is the one it waves through
3. **`amount_received` came back null on a payment that had succeeded**, which
   was a defect in this suite and is fixed. **A publishable key is given a
   limited PaymentIntent**: amount, currency and status, with the settlement
   fields withheld, because `amount_received` and `latest_charge` need a secret
   key. Confirming the way Stripe.js does was always going to get the limited
   object, so the assertion asked that call for something it can never answer.
   It reads as a failed payment on a payment that worked

The general lesson is the one already recorded above from 23 August, arriving
for the third time: **a test that has never executed asks for whatever its
author imagined**, and its totals look clean until the first environment able to
run it. Two of these three were written the day before and skipped every run.

## What it cannot prove, and who has to

**A mailbox.** No assertion here can see whether a message arrived, came from the right address, escaped the spam folder, or carried a link pointing at the website rather than at this API. Set `STAGING_EMAIL_DOMAIN` to something you can read, run the suite, and go and look. On the environment console, `php artisan mail:check you@example.com --now` and then again without `--now`: the first tests the transport, the second tests the transport and the queue worker together, and those two failures are indistinguishable from the outside.

**And on the gift walk, one message in particular.** `A reading is waiting to be written` is what tells Jennifer somebody is waiting, and gifting is the case where that must happen on the day the code is redeemed rather than on the day it was bought. The walk prints both instants when it finishes, so checking it is comparing two timestamps rather than making a judgement: **the message must carry the later one and there must be exactly one of it.** A copy at the earlier time is a gift that reached her working list at settlement with nothing to write and nobody having asked anything, which is the defect the whole `readings` table exists to prevent.

**A browser.** curl believes whatever it is told about cookies. What is left for a real browser on the real origin is `SameSite` behaviour, third party cookie blocking, private-mode partitioning, and whether the frontend sends `credentials: "include"` on every request including the first. The server-side half is proved here; the client-side half waits for the frontend.

**The 3D Secure challenge, and the wallets.** The card walk gets as far as Stripe demanding authentication, which is the assertion worth making from out here. The challenge after it is an iframe a person completes. Apple Pay and Google Pay confirm the same PaymentIntent the card does, so the intent is proved, but whether the buttons appear at all needs a registered payment method domain and a real device. **Apple Pay fails by simply not appearing on an unregistered domain**, which looks like nothing rather than like an error.

**A player.** A segment answering 200 means the bytes are reachable. Whether an HLS stream plays smoothly, renews before it expires, and behaves in Safari and in Chrome is the frontend partner's to confirm.

## What it leaves behind

Real customer rows and real orders, in whatever environment you point it at. With `STAGING_CARD=1`, real Stripe test mode payments too, and `STAGING_WALLET=1` now creates them from **two** walks, the wallet walk and the Viewing Room purchase walk, which show up in the Stripe dashboard for that account. That is acceptable on staging and is the reason for the production guard. Each run uses fresh addresses prefixed `walk-`, so they are easy to find and clear out:

```php
// php artisan tinker, on the environment console
App\Models\Order::whereHas('customer', fn ($q) => $q->where('email', 'like', 'walk-%'))->delete();
App\Models\Customer::where('email', 'like', 'walk-%')->delete();
```
