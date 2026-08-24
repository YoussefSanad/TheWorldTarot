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

Without `STAGING_URL` every test skips itself with the reason attached, so a run that says "skipped" is telling you it was never configured rather than that it passed.

## Settings

| Variable | What it does |
|---|---|
| `STAGING_URL` | **Required.** The API to walk. Refuses any host that does not look like staging or local unless `STAGING_I_KNOW_WHAT_THIS_IS=1` |
| `STAGING_ORIGIN` | The exact origin the website will be served from. Cookie authentication and CORS are both keyed on it, so without it those checks prove much less |
| `STAGING_EMAIL` | **Your own address.** Every walk account becomes `you+walk-...@yours`, so each is a separate customer and all the mail lands in one inbox |
| `STAGING_EMAIL_DOMAIN` | The blunter older form: a whole domain you can receive at. `STAGING_EMAIL` wins if both are set |
| `STAGING_INTERACTIVE=1` | Runs the purchase walk, which waits for you to mark an order paid in the panel |
| `STAGING_THROTTLE_TESTS=1` | Runs the forgotten-password rate limit test, which locks your address out for a minute |
| `STAGING_LOCALE`, `STAGING_CURRENCY`, `STAGING_READING_KEY`, `STAGING_PASS_KEY` | Defaults are `en`, `GBP`, `one-card`, `viewing-room-pass` |

## Before it can pass

- **Products need prices.** They seed at zero in every currency and an order refuses a line priced at zero. The order tests skip with an explanation rather than failing, so a skip here means the catalogue is not ready to sell, not that anything is broken
- **A card needs a finished film**, or the Viewing Room tests have nothing to walk
- **An admin account**, since settling an order is done in the panel. `php artisan app:create-admin` on the environment console

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
- Orders: recorded as pending with `payment_url` null, totals summed from lines, money as integers, questions refused where they do not belong, one bad line refusing the whole order, an unsupported currency refused
- **The whole purchase path**: no access, 403 on a film, buy the pass, settle it in the panel, access appears, and then the film plays through **manifest, rendition and segment** with a tampered token refused

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

What it asked for was wrong, and dangerously so. It used `Target::sharedAccount()`, which is the account the rest of this file relies on **not** holding a pass, and expected 404. The pass is asked about before the card is ever looked up, so that account gets 403 for every id. For the test to pass, the gate would have to run second — and then a card with no film would answer 404 while a card with one answered 403. That difference is an oracle: anybody with a free account could walk the ids and learn exactly which films exist, without paying for any of them.

**No application code changed.** The ordering has been in `CardVideoController` since `d23fe09` and is documented there and in `.claude/api.md`. The 403 case joined `it refuses a film to somebody who has not paid for one`, which now proves a card with a film and a card without one answer **identically**. The 404 case moved into the purchase walk, where an account that has actually paid exists to tell the two apart.

The general lesson is in `docs/CHECKPOINT.md` under traps: a skipped test reads as a passing one, and when a test and a documented security decision disagree, find out which is older before touching either.

## What it cannot prove, and who has to

**A mailbox.** No assertion here can see whether a message arrived, came from the right address, escaped the spam folder, or carried a link pointing at the website rather than at this API. Set `STAGING_EMAIL_DOMAIN` to something you can read, run the suite, and go and look. On the environment console, `php artisan mail:check you@example.com --now` and then again without `--now`: the first tests the transport, the second tests the transport and the queue worker together, and those two failures are indistinguishable from the outside.

**A browser.** curl believes whatever it is told about cookies. What is left for a real browser on the real origin is `SameSite` behaviour, third party cookie blocking, private-mode partitioning, and whether the frontend sends `credentials: "include"` on every request including the first. The server-side half is proved here; the client-side half waits for the frontend.

**A player.** A segment answering 200 means the bytes are reachable. Whether an HLS stream plays smoothly, renews before it expires, and behaves in Safari and in Chrome is the frontend partner's to confirm.

## What it leaves behind

Real customer rows and real orders, in whatever environment you point it at. That is acceptable on staging and is the reason for the production guard. Each run uses fresh addresses prefixed `walk-`, so they are easy to find and clear out:

```php
// php artisan tinker, on the environment console
App\Models\Order::whereHas('customer', fn ($q) => $q->where('email', 'like', 'walk-%'))->delete();
App\Models\Customer::where('email', 'like', 'walk-%')->delete();
```
