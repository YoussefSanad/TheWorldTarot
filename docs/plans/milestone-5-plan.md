# Milestone 5, phase A: identity, access and orders

Drafted 8 August 2026. Approved and built 9 August 2026, on the `milestone-5` branch.

---

## Close-out, written 12 August 2026

**Steps 1 to 8 are built, with tests, and deployed to staging on 12 August at `98351d3`. Step 9 is partly done.**

569 tests, 1387 assertions, green, `migrate:fresh --seed` clean.

`tests/Staging/` was added on 13 August to walk step 9 against the deployed environment, because the frontend does not exist yet and waiting for it would leave the milestone unproven indefinitely. It loads none of this application and only speaks HTTP to a URL. First run against staging: **24 passed, 6 failed, and all six failures were a single defect**, described below.

What that settles, and it is the risk this plan called the largest:

- **Cookie authentication across `.theworldtarot.com` works.** Configured in M1, never once exercised, and named here as the riskiest unproven thing in the milestone. On staging the CSRF cookie is issued, the session cookie is scoped to the shared root domain and is `Secure` and `HttpOnly`, a request carrying the site origin is treated as stateful, registering without a device name returns a session and no token, that session survives into the next request, and a write without the CSRF header is refused with 419. **The half a browser decides is still open**: `SameSite`, third party cookie blocking, and whether the frontend sends `credentials: "include"` everywhere
- **The guard isolation holds from the customer side**, which had also never been walked

What is still owed:

- **No real message is known to have left staging.** Step 1 is not complete until one has arrived, and nothing outside the environment can see it
- **The Viewing Room path is still unwalked**, the item carried since M4. It needs somebody to settle an order in the panel, which is what `STAGING_INTERACTIVE=1` waits for

**The defect the walk found, and fixed.** Every inline `throttle:n,1` in `routes/api.php` shared one counter per address, because Laravel keys a guest's limiter on the domain and the IP and never on the route. Six requests to `/api/v1/orders` then refused `/api/v1/forgot-password` on its first call, so a customer who bought something could not reset their password for the rest of the minute. Fixed on 13 August by giving each throttle its own prefix, `throttle:6,1,forgot-password`, and pinned by a feature test that was confirmed to fail against the unfixed routes first. **Written and not yet deployed**, so the staging suite goes on reporting it until it ships.

Building all nine steps before deploying any of them is still the deviation worth naming, since `CLAUDE.md` section 6 asks for a deploy per step. The defect above is the argument for that rule in miniature: it cannot be seen from a test suite, only from one process answering real requests.

**Deviations from the plan as written**, all small and all deliberate:

- **`paid_at` was added to `orders`**, which the schema in step 5 did not list. It is the date the money arrived, and it belongs beside `fulfilled_at` for the same reason that one is a date: support gets asked when, not only whether. `placed_at` was dropped in favour of the ordinary `created_at`, since they would always have held the same value
- **An order fulfils itself when nobody has anything to do.** A pass is delivered by the access grant in the instant it is paid, so leaving it awaiting fulfillment would put work on Jennifer's list that does not exist and cannot be done. An order holding a reading as well waits for the reading, because half delivered is not delivered
- **Sign in carries two rate limits, not one.** The route limit is per address, and a second inside `LoginRequest` is per address and email pair, which is what stops a slow guess at one account and stops somebody locking a real customer out by failing their sign in on purpose. It answers 429 rather than the framework's usual 422, because the contract promises the partner that being rate limited is a 429 with `Retry-After`
- **The Viewing Room check became a Gate rather than a `CardPolicy`.** `Gate::define('enter-viewing-room')` in `AppServiceProvider`, because the question is about the customer rather than about a card, and a policy method taking a card it never reads would have been a worse home for it. The step's real test held: no test in `CardVideoTest` was edited, which is what proves it was a refactor
- **No customer-facing order endpoint reads an order back.** The plan never promised one and the frontend may want one, so it is written down in `API_CONTRACT.md` as a question for them rather than assumed either way

**What is left before this phase is finished**, in order: fix the rate limiter buckets, confirm Resend is really configured on staging and that a message arrives, then run the interactive walk with somebody in the panel. `API_CONTRACT.md` and `QA_CHECKLIST.md` were brought up to date on 12 August, so the writing part of step 9 is done and most of the walking part is now automated.

**Open question 3 is still open**, and it is now the commercially important one: phase A completing is not M5 completing, and what closes M5 needs agreeing in both directions before it becomes an invoicing conversation.

---

M5 as written in `CLAUDE.md` section 5 is "checkout and fulfillment: Cashier, webhooks, member auth, Resend, gift codes, 24 hour rush, order notifications". This plan splits it in two and builds the second half first.

- **Phase A, this document.** Who a customer is, what they are allowed to reach, and what an order is. Payment sits behind an interface with a manual settlement driver
- **Phase B, a later plan.** Stripe via Cashier, the webhook, gift codes, the rush add-on, receipts, VAT

The split was decided on 8 August 2026. The reasoning is below and it is not only sequencing.

---

## Goal

A person can make an account, sign in from the website and from a future native app, prove who they are, and be told what they hold. An order exists as a record with the money state of a purchase on it, and the moment an order becomes paid is an event this system raises rather than something Stripe tells it. Everything downstream of that moment, granting the Viewing Room pass and notifying Jennifer that a reading needs writing, is built and proved in this phase. Everything upstream of it, collecting the money, is a single named interface with one implementation that a person in the admin panel drives by hand.

## Why this order, beyond sequencing

**It unblocks the walk that is already overdue.** `docs/CHECKPOINT.md` has carried "the Viewing Room path is unwalked on staging" since M4, because walking it needs a pass holder and there has never been a way to make one. Phase A produces exactly that, an order settled by hand in the panel that stamps a real customer, with no Stripe account involved.

**It puts the riskiest unproven thing first.** Sanctum cookie auth across `.theworldtarot.com` was configured in M1 and **no customer has ever signed in**, because until this phase there are no endpoints to sign in to. Cross-subdomain cookie behaviour is the class of problem `CLAUDE.md` section 6 says must surface against an empty app rather than a full one, and it is currently sitting undiscovered behind the payment work.

**It keeps the two open money questions open honestly.** VAT ownership and whether gift codes are ever percentage discounts both decide whether integer cents stay sufficient, per `CLAUDE.md` section 8. Neither is answerable today. Phase A does not need either answered, and building it first means the answers arrive before anything depends on them rather than during.

## Out of scope, and who owns it

| Out | Owner |
|---|---|
| Stripe, Cashier, the payment webhook | Phase B |
| Gift code issuance and redemption | Phase B |
| The 24 hour rush add-on, and seeding `ProductKey::Rush24h` | Phase B for the row and what it does to a reading's turnaround. The multi line order it needs is built here, so nothing structural is owed |
| VAT, tax lines, receipts | Phase B, and blocked on the section 8 question |
| Refunds and cancellations after payment | Phase B |
| Newsletter and Mailchimp | M6 |
| AI fulfillment of the One Card Reading | M7 |
| Any customer-facing rendered page | The frontend partner, always. Hard rule 1 |

**Explicitly still owed by M5 overall.** This document completing is not M5 completing. See the open question about what closes the milestone commercially.

---

## What phase A builds on

Read from the codebase on 8 August 2026, not from memory.

- **`Customer`** exists, with `hasViewingRoomAccess()` and `grantViewingRoomAccess()` written, tested, and called by nothing. The stamp keeps its first date on purpose so a repeat grant cannot rewrite history
- **The `web` guard's provider is already `customers`**, and the Filament panel authenticates against a separate `admin` guard. The isolation exists and has never been tested from the customer side, because there was no customer side
- **`products`** carries `type`, `fulfillment` and three `NOT NULL` price columns. `Fulfillment::AccessGrant` and `Fulfillment::ManualPdf` already distinguish the two delivery paths this phase has to implement
- **`ProductKey::ViewingRoomPass`** is seeded. `Rush24h` is declared and deliberately not seeded
- **`CurrencyResolver`** owns what we sell in. `Money` exists in `app/Support`
- **Nothing else.** No Cashier, no `orders` table, no policies directory, no mail transport. `MAIL_MAILER=log` and there is no Resend configuration in `.env.example`
- **`GET /api/v1/user`** is framework scaffolding. `API_CONTRACT.md` section 9 already warns the partner it is not part of the contract and that its shape changes when member accounts ship, so replacing it costs no coordination

---

## The shape of the payment boundary

The interface goes where the honest seam is: **an order becomes paid.**

Upstream of that moment is collecting money, which is Stripe's business and phase B's. Downstream is fulfillment, which is entirely ours and has nothing to do with how the money arrived. Putting the seam anywhere else, at "create a Stripe session" for instance, would leak Stripe's model into phase A and defeat the point.

This mirrors `App\Contracts\RevealVideoSource` named in `config/reveal.php`, and as of 8 August 2026 it is the standing convention for every third party rather than a one off. Phase A adds `App\Contracts\PaymentGateway` named in `config/payments.php`. Rules and the full register are in `.claude/external-services.md`, including the one that binds this step: a fake gateway proves a caller and never proves an implementation.

```
config/payments.php   'gateway' => ManualSettlement::class
                                   StripeCheckout::class      // phase B
```

The phase A implementation, `ManualSettlement`, does not collect anything. It leaves the order pending and returns nothing for the frontend to redirect to. A person in the admin panel settles it, which raises exactly the same event a Stripe webhook will raise in phase B and runs exactly the same fulfillment.

**What this buys.** Fulfillment, the access grant, order notifications and the whole Viewing Room path get built, tested and walked on staging against a driver that cannot fail for reasons outside this repo. When Stripe arrives it has one job, moving an order from pending to paid, and everything it triggers is already proved.

**What it costs, stated plainly.** `ManualSettlement` is not a throwaway. Support will need it after launch, for the order that fails in a way Stripe cannot put right, so it is worth building as something that stays.

**What it does not pretend.** Phase A proves fulfillment. It proves nothing about Stripe, and no test written here should be read later as evidence about payment. This is the trap that cost this project five days in M4 and the whole of `.claude/testing.md` exists because of it.

---

## How an account gets made by buying something

Decided 8 August 2026. An order carries an email, and that email always resolves to a customer row before the order is written. Three rules follow, and two of them are the difference between a convenience and an attack surface.

**An email that already has an account attaches to it, and is never sent a link to set a password.** The order joins the existing customer. Sending a set password link here would mean a stranger who knows your email address can cause a password link to land in your inbox, which is a phishing shaped tool pointed at our own customers even though it never hands the stranger anything. An existing customer is told their order is confirmed and to sign in, nothing more.

**The claim link is sent when the order is paid, never when it is placed.** Anybody can type any email into a checkout. If placing an order sent mail, the endpoint is an email bomb aimed at whatever address the sender names, and it costs nothing to fire. Paying first makes that cost real. The customer row is still created at order time, because the foreign key needs it, and it simply sits unclaimed until somebody pays.

**A customer row with no password cannot be signed in to, by any route.** This is the invariant the whole flow rests on, and it needs its own test rather than an argument. `customers.password` is `NOT NULL` today, so this decision carries a migration making it nullable, and nullable passwords are exactly the kind of thing that quietly makes an authentication check pass. The claim link and the password reset link are the same kind of credential: single use, expiring, and worth nothing once used.

## Steps

Nine steps, each one coherent commit, each deployed to staging before the next begins per `CLAUDE.md` section 6.

### 1. Transactional email through Resend, queued

**Builds.** Resend as the mail transport: the `resend/resend-laravel` package, which is not installed today, `MAIL_MAILER=resend`, and the key set in Laravel Cloud for both deployed environments rather than locally alone. Every outbound mail queued, never sent inline, per `.claude/api.md`. A single trivial mailable to exercise the path.

**Three senders, declared in config and named by each mailable**, rather than a single global from address or a string at each call site. Decided 8 August 2026. `theworldtarot.com` is verified in Resend as of the same day.

| Sender | Carries |
|---|---|
| `support@theworldtarot.com` | Password reset, and the set your password link after a purchase |
| `orders@theworldtarot.com` | Order confirmation to the customer |
| `fulfillment@theworldtarot.com` | The notification to Jennifer that a reading is waiting, and the reading itself once M7 delivers one |

**~~`orders@theworldtarot.com` sends the order confirmation.~~ Changed 30 August 2026, issue #47.** It sends nothing now, and it is not a case of `App\Enums\Sender` any more. The confirmation comes from `noreply@theworldtarot.com`, and orders@ became the address the internal new order notification is delivered *to*. Still three senders: support, noreply, fulfillment.

**Sending is not receiving.** Resend's verification is SPF and DKIM, which authorise sending and create no mailbox. A reply to `support@` goes nowhere unless an MX record routes it somewhere real, and nobody is told. That is the open GoDaddy item in `CLAUDE.md` section 8 and it should be settled before customer mail goes out, since a support address that silently discards replies is worse than having none.

**Tests.** That mail is queued rather than sent during a request, and that a failure to send cannot fail the request it rode in on.

**The proof that actually matters, and it is not a test.** `Mail::fake()` asserting our own mailable proves that we built a mailable. It proves nothing about Resend accepting it, which is the M4 lesson exactly: an assertion against our own implementation of somebody else's contract stays green through total failure. **This step is not complete until a real message has left staging and arrived**, and the sending domain's DNS is what decides that.

**What could go wrong.** The sending domain needs DNS records, which touches the open GoDaddy item in `CLAUDE.md` section 8. If her MX records live there, nothing may move until they are mirrored or her email breaks silently. This step may surface that problem, which is a reason to do it first and not a reason to defer it.

### 2. Register, sign in, sign out, and `GET /api/v1/me`

**Builds.** Four flat routes, no locale segment, since auth is not content per `.claude/api.md`. Form Requests for all of them, backend validation authoritative. `me` returns a Resource, never the model. Both auth paths work on every route: cookie session for the website, bearer token for a future native app. Signing out invalidates the session on the cookie path and deletes the current token on the token path, not every token the customer holds. Throttled, because an unthrottled login endpoint is a credential stuffing target and this is an access control system.

Removes `GET /api/v1/user`.

**Tests.** Registration validation including the duplicate email case. Sign in with good and bad credentials, and that a bad one is indistinguishable from an unknown email in both status and body. Every protected route under both auth paths. Throttle limits. **That an admin session cannot authenticate the API and a customer token cannot reach the Filament panel**, which is the guard isolation that has existed since M1 and has never been tested from this side.

**What could go wrong.** This is the first real exercise of cookie auth across subdomains, and a test suite cannot prove it: the session cookie, the CSRF cookie, `credentials: "include"` and the stateful domain list only interact for real in a browser on the actual origin. **This step needs a staging proof from a browser on the frontend origin**, not a passing suite. It is the same lesson as the `curl` IPv4 proof in M4, wearing different clothes.

### 3. Password reset

**Builds.** Request a reset link, and complete it. Uses step 1. Tokens are single use and expire. Throttled harder than sign in, because an unthrottled reset endpoint is an email bombing tool pointed at whatever address the attacker names.

**Tests.** The full round trip. A used token is refused. An expired token is refused. **Requesting a reset for an unknown email answers exactly as it does for a known one**, so the endpoint is not an account enumeration oracle.

**What could go wrong.** The reset link points at the frontend, not at this API, since hard rule 1 forbids rendering a page here. That URL is a coordination point with the frontend partner and belongs in the contract.

### 4. The Viewing Room policy, and telling the frontend what a customer holds

**Builds.** A `CardPolicy` holding the pass check that is currently inline in `CardVideoController`, so authorization is answered in one place and the panel and support tooling can ask the same question the API asks. `GET /api/v1/me` gains whether the customer holds the pass.

Behaviour does not change. The 403 for a signed in customer without a pass, and the 404 for a card with no watchable film, both stay exactly as they are, including the reasoning already written into that controller about why it is 403 and not 404.

**Tests.** The existing Viewing Room tests must pass untouched, which is what proves this is a refactor. `me` reports the pass truthfully before and after a grant.

**What could go wrong.** `CardVideoController` is described in its own docblock as the most important handful of lines in the milestone, and it is the audit finding this project exists to fix. Moving the check is worth doing for the single place it creates, and it is also the one refactor here that could silently open the paywall. The existing tests passing unchanged is the whole safety argument, so **no test in that file may be edited during this step.** If one needs editing, the refactor is wrong.

### 5. Orders and order lines

**Builds.** Two tables and their models, plus one change to an existing one: **`customers.password` becomes nullable**, since a row created by an order has no password until it is claimed. There is no customer data anywhere yet, so that is an edit to the original create table migration rather than a new one. See the note on fresh migrations below.

```
orders
  customer_id       // never null. Decided 8 August 2026
  status            // pending | paid | cancelled, string column + enum cast
  currency          // the currency at the moment of purchase
  total_amount      // integer minor units
  fulfilled_at      // nullable timestamp, not a status
  placed_at
order_lines
  order_id
  product_id        // one products table, referenced by key, which is the
                    // argument that settled the schema on 25 July 2026
  product_key       // captured, stable, never translated
  unit_amount       // integer minor units, captured at purchase
  quantity
  question          // nullable. What the customer wants the reading to answer
```

**Three rules the schema enforces rather than remembers.**

**An order has many lines, and the question belongs to the line rather than the order.** `ProductType::Addon` is defined as something "bought alongside something else and has no page of its own", which is the 24 hour rush, and `CLAUDE.md` section 4 describes a currency carrying "product page to cart to Stripe". Both mean a basket, so a single line order is a special case of this and not the shape to build. The question sits on the line because `allows_question` is a property of a product, and an order holding two readings holds two questions. Putting it on the order would make the second one unanswerable.

**Prices are captured, never re-read.** Jennifer edits prices in the panel. An order that reads its price from `products` at display time reports a different total after she changes one, which is wrong for support, wrong for accounting and unnoticeable until it matters.

**Money state is an enum, completion is a timestamp.** `fulfilled_at` follows `viewing_room_access_at`: a date answers when as well as whether, which is the question support actually gets asked. A `fulfilled` status would answer only whether, and would also make "paid but not yet written" inexpressible, which is the normal state of every manual reading.

**Tests.** Every money value is an integer in storage and in transit. An order keeps its captured price after the product's price changes, which is the rule above proved rather than asserted. An order total equals the sum of its lines. **A customer with a null password cannot authenticate**, by password, by any credential, or by any other route, which is the invariant the account claim flow rests on and the one this migration puts at risk.

**What could go wrong.** A tax column is not here, because VAT is unanswered and building an unused column guesses at the answer. Adding one later is a free migration while no real orders exist and an expensive one afterwards, which is an argument for settling the section 8 question before phase B rather than for building speculatively now.

**The fresh migration note.** Editing an applied migration is free in the database sense, because there is no customer, order or session data anywhere. It is not free operationally: staging and production have both run migrations, so both need `migrate:fresh --seed` when this deploys. Products and locales are seeded and come back by themselves. Cards do not, so **this step must not deploy until Jennifer has confirmed she has not entered any card content yet.** Today she has not, which is why this is cheap now and stops being cheap the moment she starts.

### 6. The payment interface, and creating an order

**Builds.** `App\Contracts\PaymentGateway`, `config/payments.php`, and `ManualSettlement` as the only implementation. `POST /api/v1/orders` creates a pending order from an email, a currency and a list of lines, each naming a product key and optionally a question, then hands the order to the gateway for whatever comes next, which today is nothing.

The email resolves to a customer before the order is written, finding the existing row or creating an unclaimed one. A signed in customer's own account is used and the email is ignored, so nobody can attach an order to somebody else while authenticated as themselves.

The currency comes from the request and is not re-detected, per the standing rule in `CLAUDE.md` section 4 that the backend must not re-detect when an explicit currency is supplied. It is one currency for the whole order, since a basket priced in two currencies has no total.

**Tests.** An order is created pending, at each product's current price in the requested currency, with the total summed from the lines. An unpublished, unpriced or unavailable product cannot be ordered, and one bad line refuses the whole order rather than silently dropping a line. An unsupported currency is refused rather than falling back, matching `priceIn()` which returns null rather than showing a number from the wrong currency. **A question is refused on a product whose `allows_question` is false** and accepted on one where it is true, neither being required. The gateway is resolved from config, so naming a different one changes what happens. A known email attaches to the existing customer and creates no second row. **Placing an order sends no mail at all**, which is the email bombing rule proved. Two orders placed at once with the same new email produce one customer, not a unique constraint violation.

**What could go wrong.** The find or create is a race. Two simultaneous first orders from the same address both see no customer and both insert. The unique index on `email` is what makes that safe, so the insert has to be written expecting to lose that race sometimes rather than assuming it cannot happen.

The other risk is design rather than code: the temptation to shape the interface around what Stripe will need. It should be shaped around what this application needs, or phase B has been designed in advance on assumptions nobody has tested.

### 7. Paid, and what follows

**Builds.** The single transition from pending to paid, and the three things it raises.

- **Fulfillment.** `AccessGrant` stamps the customer with `grantViewingRoomAccess()`. `ManualPdf` queues a notification to Jennifer carrying the customer's question, since `docs/client-context.md` records that she writes and emails every reading herself and that the system notifies and records rather than fulfils
- **The claim link**, to a customer who has no password yet. Single use and expiring, and it sets a password rather than resetting one
- **Nothing of the sort to a customer who already has an account.** They are told the order is confirmed and to sign in, per the rule above

Also the endpoint that completes a claim, which is close enough to step 3's reset to share its machinery and is deliberately not the same entry point.

**Idempotent by construction, not by discipline.** The transition takes a row lock and only fires when the status actually changes, so a second delivery of the same event finds the work done and does nothing. `CLAUDE.md` section 2 makes this binding on every job, and `docs/CHECKPOINT.md` records it as what M4 hands M5: anything that moves money needs its own answer to running twice. **Phase B's webhook will arrive more than once. This step is where that is survived, not that one.**

**Tests.** Paying twice grants access once, notifies once, and sends one claim link rather than two. An already granted customer keeps their original access date. Fulfillment runs for the right product and not the wrong one. A failed notification does not roll back the payment or the access grant, because a customer who paid must not be locked out by an email problem. **An order paid against an email that already has an account sends no claim link**, which is the phishing rule proved. A claimed account is refused a second claim, so the link cannot be replayed into a password change later.

**What could go wrong.** Ordering. If the notification is sent before the transition commits, a redelivery sends it again. If access is granted outside the transaction, a failure part way leaves a customer with a pass and no order.

### 8. Orders in the admin panel

**Builds.** A Filament resource for orders, read mostly, with an action to settle a pending one by hand. Settling raises the step 7 transition, so a hand settled order and a paid one are the same thing to everything downstream.

Jennifer sees orders, what was bought, by whom, in what currency, and the customer's question. She marks a reading fulfilled when she has emailed the PDF. She never edits money.

**Tests.** Settling grants a pass. Settling twice does not grant twice. The action is unavailable on an order that is not pending.

**What could go wrong.** This is a real destructive capability reachable from a browser session, so it belongs to the `admin` guard and to nothing else. The panel's language is Jennifer's, not ours, per section 7: she sees "paid" and "sent", never "settled" or "fulfillment".

### 9. The staging walk, and the contract

**Builds.** No new behaviour. `API_CONTRACT.md` gains the auth endpoints, `me`, and order creation, and its section 9 shrinks. `QA_CHECKLIST.md` gains an M5 phase A section.

**The walk, which is the actual deliverable of this step.** On staging, in a browser, on the real frontend origin: make an account, sign in, be refused the Viewing Room with a 403, have an order settled in the panel, and then watch a film. Plus the denials, 401 unauthenticated, 403 without a pass, 404 for a card with no film.

**This closes the item `docs/CHECKPOINT.md` has carried since M4**, that the Viewing Room path has never been walked in a deployed environment. It has been blocked on there being no way to make a pass holder, and step 8 is that way.

**What could go wrong.** Nothing here is provable from a test suite. That is the point of the step.

---

## Open questions, needed before work starts

**1. Is a customer row required for every order? Decided 8 August 2026: yes, always.**

A buyer gives an email at order time. If no customer holds that email, one is created from it and the order attaches to it. If one already does, the order attaches to the existing customer and no new row appears. Once the order is paid, an unclaimed account is sent a link to set a password, after which they sign in like anybody else.

One code path for both product kinds, no orphan orders, the pass needs no special case, and support can always find an order by person. What it costs is a customer row per buyer and the claim flow described below.

**2. Email verification, now or never?** Recommended: **not in phase A.** Password reset already proves the customer owns the address, nothing in phase A would gate on verification, and building machinery nothing reads is against the grain of the rest of this codebase. Revisit if phase B wants it before sending receipts.

**3. What closes M5 commercially?** Payment is tied to milestone completion per `docs/client-context.md`, and this splits M5 in two. Phase A completing is not M5 completing, and that needs to be true in both directions before it becomes an invoicing conversation.

**4. Deferred, and confirmed as deferred.** VAT ownership and gift code semantics, both in `CLAUDE.md` section 8, stay open. Phase A does not depend on either. **Phase B cannot start until both are answered**, because between them they decide whether integer cents remain sufficient across the whole system.

---

## Dependencies

| On | What | Blocks |
|---|---|---|
| You | The remaining questions above. Question 1 is decided and blocks nothing now | Not blocking before step 5 |
| You | A Resend account, and DNS for a verified sending domain | Step 1, and therefore steps 3 and 7 |
| Jennifer | Where order notifications go, and the wording of transactional emails, now including the one that invites a buyer to set a password | Steps 1, 7 and 8 |
| Frontend partner | The password reset URL and the set password URL on their side, which are two pages rather than one | Steps 3 and 7 |
| Frontend partner | Contract acknowledgement, still outstanding since it was written | Step 9 |

The frontend partner dependency is worth naming twice. `API_CONTRACT.md` is written, current and unsent, and phase A adds the first endpoints they must build against rather than merely read from.

---

## Not in this plan, on purpose

Nothing here touches the Bunny playback path, the signing, the TTL or the zone configuration. That work is finished and proved as of 7 August 2026, and the only thing phase A changes near it is where the pass check is written, which step 4 proves by leaving its tests alone.
