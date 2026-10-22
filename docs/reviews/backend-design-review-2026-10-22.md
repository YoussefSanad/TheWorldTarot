> **Status: findings only, written 22 October 2026. Nothing here is approved or scheduled.** Each item needs a decision from the user before it becomes a plan step. Strike through and date an item when it is fixed or rejected, never delete it.

# Backend design review, 22 October 2026

A read-only evaluation of `theworldtarot-backend` at `a5f2a81`, plus the uncommitted working tree at that point (the arch-tests work in `docs/plans/arch-tests.md`, which changes nothing reviewed here). Five areas were reviewed separately: payments and orders, video and async, HTTP and access control, domain and data, and cross-cutting concerns (tests, seams, admin, observability, docs).

**How to use this file.** Findings are ranked and have IDs (`R1`, `R2`, ...) so a plan or an issue can cite them. Each finding says whether its evidence was **checked** (the lead read the code) or is **unverified** (one scout's reading only, confirm before acting). AGENTS.md section 4 decisions were taken as settled. Where a finding touches one, it says so.

---

## Verdict

The design is sound and better than most at this size. Its strongest feature is how it handles failure:

- One row-locked transition settles an order (`MarkOrderPaid`)
- Webhooks are verified before they are read, and a webhook payload is only a hint
- Jobs carry strings rather than models and are dispatched after commit
- Gift redemption is row-locked, and a real concurrent-process test proves it
- Every external service sits behind a seam, and arch tests machine-check the rules that have already caused failures

The weak points sit **at the boundary between "this request" and "the previous attempt"**. A retried payment, a redelivered job, a replaced upload: each assumes the earlier attempt is either finished or harmless, and none of them proves it. Below that are some schema gaps (no FK indexes, invariants enforced only in PHP), and observability that depends on someone happening to read a log or the panel.

Nothing found is an emergency. **R1 to R4 should be settled before launch traffic.** The rest can be fitted in as convenient.

---

## Notably well designed (keep these)

- `app/Actions/MarkOrderPaid.php:68-105`: one transition, row-locked, from pending only. Access is granted inside the transaction and mail goes out `afterCommit`
- `app/Http/Controllers/Api/V1/StripeWebhookController.php:54-73`: verifies the raw body first, refuses everything when the secret is missing, and answers 400 so that Stripe retries
- `app/Actions/RecordStripePayment.php`: amount and currency are matched against the order, a mismatch is refused rather than settled, and the outcome is a named `Recording` enum rather than a bool
- `app/Jobs/ForgetCardVideo.php`, `app/Models/Card.php:104-115`: jobs take captured strings and dispatch after commit, so neither a deleted row nor a rolled-back transaction can mislead a worker
- `app/Actions/RedeemGift.php:61-71`, `app/Models/Gift.php:85-122`: a locked re-read before redemption, and codes minted by rejection-sampled `random_int` with no modulo bias. Codes are minted at settlement, so abandoned checkouts leave nothing redeemable behind
- Gift lookup and redemption are separate endpoints, so a link prefetcher cannot spend a gift. Pay tokens and codes travel in POST bodies, which keeps them out of access logs
- `app/Support/TrustedProxies.php`: honest about what it does not fix (see R6)
- `tests/Unit/Architecture/*`: past failures turned into machine checks

---

## Findings

### High

**R1. A retried payment can collect twice, and the record of the first payment is overwritten.** Checked.
- Evidence: `StripeCheckout::begin` (`app/Services/Payments/StripeCheckout.php:52-104`) and `StripeWallet::begin` each create a new Session or PaymentIntent on every call. `RefuseAnythingButAPendingOrder` allows that on purpose while the order is pending. `RecordStripePayment.php:100-103` writes `charged_amount` and `stripe_payment_intent_id` **before** the transition and with no pending check. It also never checks that the intent is the order's current one
- Scenario: the customer opens checkout twice, or tries the wallet and then the hosted page, and both payments succeed. The first settles the order. The second reaches `AlreadySettled`, but line 100 has already overwritten the stored intent id and amount with its own. The money is collected twice and the first payment's id is lost, which leaves a dispute (`StripeWebhookController.php:144` looks orders up by intent id) unable to find its order. CHECKPOINT records that no refund path exists
- Suggestion: make the line 100 write conditional on the order still being pending, ideally inside the `MarkOrderPaid` lock. Record a succeeded intent that arrives for an already-paid order as its own `Recording` case (for example `PaidTwice`), and make it loud (see R9). Optionally expire the previous Session or cancel the previous intent on `begin()`
- Touches payments: milestone lane, security review

**R2. `stripe_payment_intent_id` is probably always null for hosted Checkout, so the reconcile sweep never sees those orders.** Checked in code. Stripe behaviour still needs confirming on staging.
- Evidence: `StripeCheckout.php:104` stores `$session->payment_intent` right after the Session is created. The client pins no API version (`AppServiceProvider.php:104`), so it uses the library default, `2026-07-29.dahlia` (stripe-php v21.2.1). Since API `2022-08-01`, a payment-mode Checkout Session creates no PaymentIntent until the customer confirms, so the field is null at creation. `ReconcileOrders.php:53` filters `whereNotNull('stripe_payment_intent_id')`
- Consequence: for the redirect road, the webhook is the only path to settlement, and the "reconcilable even if no notification ever arrives" comment at line 101 does not hold. It also overwrites any intent id that an earlier wallet attempt stored
- Suggestion: store the Session id in its own column, and have the sweep retrieve the Session and then its `payment_intent`. Alternatively, search PaymentIntents by `metadata.order_id`. Confirm with one staging walk and a captured vector (see R5)

**R3. Video upload jobs outlive the queue's visibility timeout, and the create-once guard is a check-then-act race.** Code checked. The deployed timeout is unverified.
- Evidence: `SendCardVideoToBunny` has `$timeout = 600`, with a 300 second upload timeout in `config/services.php:49`. `docs/deploy/laravel-cloud-setup.md:161,174` sets the managed queue visibility timeout to 60 seconds, with a note to raise it "when the job exists". Nothing records that it was raised. Locally, `config/queue.php:43` has `retry_after` at 90. The guard at `SendCardVideoToBunny.php:87-92` is `if (! hasIncomingVideo()) createVideo()`. Two concurrent deliveries can both pass it, and the loser's Bunny video is orphaned
- The AGENTS.md section 4 rule ("created at Bunny before its bytes are sent") covers sequential redelivery but not concurrent redelivery. Concurrent redelivery is exactly what a timeout under 600 seconds produces, on top of two uploads of the same bytes at once
- Suggestion: raise the visibility timeout above the job's timeout on the managed queue, and `retry_after` locally, then record the value in CHECKPOINT. Claim the slot atomically with `UPDATE cards SET bunny_incoming_video_id = ? WHERE id = ? AND bunny_incoming_video_id IS NULL`. The run that loses deletes its own Bunny video and exits. Consider `ShouldBeUnique` or `WithoutOverlapping` keyed by card id

**R4. Stripe is proven only by fakes, against the project's own rule.** Unverified (reported by a scout).
- Evidence: `tests/Fixtures/stripe/README.md:3` says nothing has been captured, and `StripeWebhookVectorTest` skips. AGENTS.md section 4 and `.claude/testing.md` forbid exactly this: it is the M4 Bunny failure shape
- Suggestion: capture a real signed `payment_intent.succeeded` payload and its header from staging, plus a Checkout Session create response, which answers R2 at the same time

### Medium

**R5. Replacing a video while one is transcoding can promote the old bytes.** Unverified.
- Evidence: `Card.php:145-154` reuses the in-flight `bunny_incoming_video_id` when a new master is uploaded. A Ready callback or sweep for the earlier bytes can call `promoteIncomingVideo` (`Card.php:398-413`) while the new bytes are still uploading. `SendCardVideoToBunny::failed()` (`:116-124`) can also mark a newer upload as Failed
- Suggestion: mint a new incoming video on replacement, or carry an upload generation (for example the master path) in the job and check it before promote and before marking Failed

**R6. Every IP-keyed rate limit can be bypassed through the origin hostname.** Checked (documented in code, unfixed).
- Evidence: `TrustedProxies.php:63-79`. The app is reachable directly at `*.laravel.cloud`, where `$request->ip()` is again whatever the caller says. That affects the login, password reset, gift lookup and pay limiters
- Suggestion: have the origin refuse traffic that did not come through Cloudflare, either with a shared secret header added by a Cloudflare Transform Rule and checked in middleware, or with a platform-level restriction if Laravel Cloud offers one

**R7. Foreign key columns have no indexes.** Checked.
- Evidence: Postgres does not index FKs automatically. There is no index on `orders.customer_id`, `order_lines.order_id`, `order_lines.product_id` (`2026_08_09_000000_create_orders_tables.php:30,58,64`), `readings.order_line_id` (`2026_09_02_000000_a_reading_is_a_row.php:36`) or `readings.gift_id`. `gifts.order_line_id` is covered by its unique constraint. Every customer's orders, every order's lines, and every Readings and Orders panel screen scans
- Suggestion: one additive migration with the five indexes. Cheap and safe

**R8. Invariants the code relies on are enforced only in PHP.** Unverified.
- Evidence: `orders.status` and other enum columns are plain strings (`2026_08_09_000000:33`) with no CHECK constraint. Money columns are unsigned integers, but `total_amount = sum(lines)` is checked only in `RefuseAnythingButAPendingOrder.php:57`. Customer email uniqueness is case-sensitive on Postgres, and `firstOrCreate` on email (`RecordStripePayment.php:220`) compares case-sensitively. Whether every write lowercases the email is unverified
- Suggestion: CHECK constraints for the enum columns, and a unique index on `lower(email)` (or `citext`). Decide whether reading idempotency gets database backing (see R12)

**R9. A failure only shows up if someone reads the log or the panel.** Unverified (no reporter found in `composer.json`).
- Evidence: there is no Sentry, Flare or Bugsnag in the repo. `SendCardVideoToBunny::failed`, `ForgetCardVideo` and the amount-mismatch branch all end at `Log::error`. There are no failed-job alerts and no health endpoint, only the `CheckMediaStorage` and `CheckMail` commands. AGENTS.md section 8 already notes that the queue reports success when mail goes nowhere
- Suggestion: one error reporter (Laravel Cloud may provide one, so confirm first). Route `Log::error` and `failed()` there, add a `/up` health route if the platform does not supply one, and add a daily check on `failed_jobs`. The R1 `PaidTwice` case belongs here as well

**R10. The payment seam has Stripe's shape.** Checked in part.
- Evidence: `PaymentInstruction.php:56,75-77` has a `clientSecret` variant. The inbound side (`StripePayment::from(PaymentIntent)`, the webhook controller) is Stripe only, and the interface covers only `begin()`. Under AGENTS.md section 4, "a vendor's own concepts appearing in it means the seam is in the wrong place"
- Suggestion: no urgent change, because Stripe is the only realistic gateway. Rename the variant to something like `confirmOnClient(token)` and record in `.claude/external-services.md` that only the outbound half is abstracted. Do this only if the user wants the seam to be honest rather than complete

**R11. Authorization has no single place to audit.** Checked.
- Evidence: there are no Policy classes, only two `Gate::define` calls (`AppServiceProvider.php:129,142`). Ownership of orders, readings and gifts comes from holding a bearer credential (pay token or gift code), checked in each controller. That fits the guest-checkout design, but every new endpoint repeats the reasoning
- Suggestion: before native apps or M7, add `OrderPolicy` and `ReadingPolicy` that accept either an authenticated owner or a valid bearer credential, and route the existing checks through them. Confirm that the M7 AI endpoint is payment-gated and rate-limited when it lands (not present in `routes/api.php` today)

**R12. Docs that agents read have drifted from the code.** Checked in part.
- AGENTS.md section 4, "Idempotency": says readings are unique on order id. ADR 0004 and the migration (`a_reading_is_a_row.php:34-36`, "Not unique") replaced that with one or more readings per order line. Fix the AGENTS wording (strike through and date it) and state which constraint, if any, now guarantees "refresh returns the same reading"
- `.claude/external-services.md:45` still lists `GiftCodeRedemption implements PaymentGateway`, which was withdrawn on 1 September per CHECKPOINT
- `docs/backend-components.md:336-337` leaves out `StripeWallet` and `LoggedNewsletterList`
- `docs/plans/arch-tests.md` is built but not closed out, and CHECKPOINT does not mention it
- `.claude/` is gitignored, so the rule sources the arch tests cite are not versioned or visible in CI
- `docs/plans/` has 26 files with superseded and live plans side by side, and only the first-line banners tell them apart. Consider moving superseded plans to `docs/plans/archive/`

### Low

**R13. Translation completeness is recomputed in full on every Locales table render** (`app/Support/TranslationCoverage.php:71-78`, `LocalesTable.php:46-53`). Fine at about 26 records. Revisit if the card count grows a lot. Do not cache it as a stored flag (AGENTS.md section 4 forbids that). A per-request memo is enough.

**R14. `TranslatableContentObserver` runs a coverage check on every save of every translatable model** (`AppServiceProvider.php:151`), and can demote a live locale in the middle of a seeder or bulk import. Unverified. Worth a test with the Spanish seeder against a locale that is already live.

**R15. Every row in `admins` gets the whole panel** (`app/Models/Admin.php:25-27`, `canAccessPanel` returns `true`). The dedicated guard makes the table itself the access list, which is fine for one admin. If anyone else ever gets a login, such as a reader fulfilling readings through the panel (`docs/plans/cms-fulfillment.md`), add a role or an `is_active` flag first. Decision for the user.

**R16. The video sweep runs daily only, and the scheduler cadence on the platform is unverified** (`routes/console.php:20-35`). The sweep makes no Bunny call unless a card is in flight, so `everyFifteenMinutes()` costs almost nothing and turns a lost callback from a day's outage into minutes. Also confirm that the cache store behind `withoutOverlapping` is shared across containers.

**R17. Smaller duplication and dead ends.** `StripeCheckout` and `StripeWallet` repeat the currency lowercasing, metadata casting and the refuse call. `BuyerFromCheckoutSession` and `BuyerFromLatestCharge` share one shape. `payment_intent.payment_failed` only logs. Gift lookup returns 404 for an unknown code and 409 for a spent one, which tells a guesser whether a code exists. That was a deliberate contract choice (`API_CONTRACT.md:714`), and 60 bits of entropy makes it harmless.

---

## Suggested order

1. **R2 and R4 together**: one staging walk that captures real Stripe vectors. This settles whether R2 is real and closes a rule breach
2. **R1**: guard the pre-transition write and name the paid-twice case. Payments, so milestone lane with security review
3. **R3**: a platform setting plus a small atomic-claim change. Its tests need a real concurrency test in the style of `RedeemAGiftRaceTest`
4. **R7**: one additive migration
5. **R9**: choose an error reporter (an options decision for the user, AGENTS.md 0.1)
6. **R12**: documentation pass (documenter)
7. The rest as convenient. R5 before Jennifer starts replacing films at volume. R6 before any marketing push

## Not covered, or only partly covered

- FormRequest validation coverage, and the contract-versus-response shape for every endpoint (spot-checked only)
- Seeder rerun safety and factory realism
- N+1 queries in Filament resources and API resources (not swept)
- `BunnyStream` signing internals beyond what CHECKPOINT records
- Sanctum token expiry, the session `secure` flag and the CORS origin list values
- Nothing was executed: no test run, no `route:list`, no queries against a database
