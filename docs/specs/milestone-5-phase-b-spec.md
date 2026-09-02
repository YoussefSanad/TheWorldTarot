# Spec: milestone 5 phase B, taking the money

Written 25 August 2026 from `docs/plans/milestone-5-phase-b-plan.md` as revised the same day. The plan is the reasoning and the record of what was decided and by whom. This is what gets built, in the vocabulary this codebase already uses.

Nothing here is built. Phase A shipped on 12 August and everything downstream of an order becoming paid is deployed and walked.

---

## Problem Statement

A customer can put a reading or the Viewing Room pass in a basket, and this system records exactly what they chose and what it costs. Then nothing happens.

- **The customer cannot pay.** `POST /api/v1/orders` answers 201 with `payment_url: null`, which means "your order is recorded" and not "you have paid". The confirmation screen has to say so, which is a strange thing for a shop to say
- **Nobody receives what they bought until a person intervenes.** The order sits at `pending` until Jennifer or a developer opens the admin panel and settles it by hand. Out of hours, that is however long it takes somebody to notice
- **Jennifer cannot sell anything.** The site can take an order at three in the morning and cannot take a pound for it
- **The design she paid for cannot be delivered.** Her reading page carries Apple Pay, Google Pay and a card button above the price, and no part of that exists
- **The frontend partner is blocked on a contract for paying**, which is why section 9 of `API_CONTRACT.md` describes the flow before it is built

Everything after the money arrives works, is tested, and has been walked on staging by a real purchase. What is missing is the money.

## Solution

A customer pays with a wallet or a card **without leaving the website**, and everything phase A already built happens by itself.

From the customer's side:

- The reading page shows Apple Pay and Google Pay buttons, and a "Pay with Card" button that reveals a card field
- They tap a wallet, authenticate, and the wallet hands over their email so they never type one
- Their order is placed, paid, and confirmed in one uninterrupted movement on one page. There is no redirect anywhere
- Their receipt arrives, and with it an invitation to set a password if the account was made for them
- The Viewing Room pass grants access in the same instant the money lands

From Jennifer's side:

- Orders arrive already paid, and the ones that need her to write a reading say so
- She still has "mark as paid" in the panel, for the customer who pays some other way or the order that goes wrong

From this system's side:

- `MarkOrderPaid` remains the one moment an order becomes paid. A webhook raises the transition a person in the panel raises today, and fulfillment cannot come to differ between them
- A payment that Stripe accepted but never told us about is picked up by a sweep, because webhooks are lost

## User Stories

**Buying, with a wallet**

1. As somebody reading the Month Ahead page, I want an Apple Pay button beside the price, so that I can buy without filling in a form
2. As an Apple Pay customer, I want the wallet to supply my email, so that I never type an address on a phone keyboard
3. As an Apple Pay customer, I want my order to exist only after I have authenticated, so that abandoning the sheet leaves nothing behind in my name
4. As a Google Pay customer, I want the same path as Apple Pay, so that my choice of phone does not change what I can do
5. As a wallet customer, I want to be told the payment succeeded on the page I was already on, so that I never wonder whether I have been charged
6. As a wallet customer whose payment is declined, I want to be told so and be able to try again, so that a declined card is a retry and not a dead end

**Buying, with a card**

7. As somebody who does not use a wallet, I want a "Pay with Card" button, so that I am not excluded from buying
8. As a card customer, I want an email field above the card field, so that my receipt and my access reach me
9. As a card customer whose bank asks for 3D Secure, I want that challenge handled in the page, so that authentication does not lose my order
10. As a card customer, I want a declined card to leave my order intact, so that I can retry rather than start again
11. As a card customer, I want my card details never to reach this system, so that a breach here cannot expose them

**Prices and currency**

12. As a customer, I want to be charged the price I was shown, so that the amount on my statement matches the page
13. As a customer, I want to be charged in the currency I was browsing in, so that no conversion happens between the page and my bank
14. As a customer buying the day Jennifer changes a price, I want the price captured when I ordered, so that a change between my order and my payment cannot move what I owe
15. As Jennifer, I want to edit a price at any time without affecting an order already placed, so that I am never caught between a customer and a number

**Getting what I paid for**

16. As a customer who bought a reading, I want my payment recorded and Jennifer told, so that my reading gets written
17. As a customer who bought the Viewing Room pass, I want access the moment my payment succeeds, so that I can watch immediately
18. As a customer with no account, I want an account created and an invitation to set a password, so that I can come back and sign in
19. As a customer who already has an account, I want no "set your password" message, so that nobody can use my address to send me one
20. As a customer, I want exactly one receipt for one payment, however many times the payment is reported to this system

**Coming back later**

21. As a customer who closed the tab mid-payment, I want my order still there tomorrow, so that abandoning a checkout does not destroy it
22. As a customer returning to an unpaid order, I want to pay for it, so that I do not have to build the basket again
23. As a customer retrying after a decline, I want a fresh payment against the same order, so that no duplicate order is created every time my card is refused
24. As a customer, I want a second successful payment to be impossible on an order already paid, so that I cannot be charged twice for one thing

**Not being able to reach somebody else's order**

25. As a customer, I want my order payable only by whoever placed it, so that a guessed number does not let a stranger interact with my purchase
26. As a customer, I want the amount and email on my order invisible to anybody guessing ids, so that an order number leaks nothing about me
27. As a signed-in customer, I want another customer's order refused to me, so that being logged in confers nothing over anybody else's purchase

**Jennifer**

28. As Jennifer, I want orders to arrive already paid, so that my involvement starts at writing the reading rather than at chasing the money
29. As Jennifer, I want to keep settling an order by hand, so that a customer who pays another way, or an order that goes wrong, can still be put right
30. As Jennifer, I want a hand-settled order to behave exactly as a card-paid one, so that support has one path and not two
31. As Jennifer, I want to be told when a customer disputes a payment with their bank, so that a chargeback is something I learn about rather than discover
32. As Jennifer, I want the amount actually collected recorded against the order, so that a support conversation and my bank statement agree

**Reliability, which is where the money is lost**

33. As Jennifer, I want a payment Stripe accepted to reach the customer even if the notification is lost, so that a network fault does not cost a customer their purchase
34. As Jennifer, I want a repeated notification to change nothing the second time, so that a redelivery cannot grant twice or send two receipts
35. As Jennifer, I want a payment for an amount that does not match the order to be refused and reported, so that a mismatch is caught rather than settled
36. As a developer, I want a forged notification to change no state at all, so that the endpoint cannot be used to grant access for free
37. As a developer, I want an old notification refused, so that a captured request cannot be replayed later
38. As a developer, I want a notification about an order this environment does not have to be answered normally and logged, so that staging traffic reaching production does not create an endless retry
39. As a developer, I want the notification answered quickly with the work queued, so that a slow fulfillment never turns into an infinite retry of a payment that already succeeded
40. As a developer, I want to know how often the platform actually runs scheduled work, so that the safety net's real delay is a measured number rather than an assumption

**Building and proving it**

41. As a developer, I want the payment method chosen per payment rather than per environment, so that four buttons on a page can mean four ways to pay
42. As a developer, I want an unrecognised payment method refused, so that a typo cannot start charging cards
43. As a developer, I want the answer from starting a payment to say which kind of answer it is, so that the frontend reads a field name rather than guessing from a shape
44. As a developer, I want a signature verified against a payload Stripe genuinely signed, so that our verification is proved by Stripe rather than by our own implementation of it
45. As a developer, I want proof that widening the tolerance for a pinned fixture did not widen it on the live endpoint, so that replay protection cannot be disabled invisibly
46. As a developer, I want a real test payment walked against a deployed environment, so that a green suite over a dead integration is not what closes this milestone
47. As a developer, I want each step deployed as it lands, so that nine steps are not batched before any of them is deployed
48. As the frontend partner, I want the response from starting a payment documented before it is built, so that I can object to the shape while objecting is still cheap
49. As the frontend partner, I want `POST /api/v1/orders` to be unchanged, so that what I have already built against it keeps working
50. As support, I want a manually settled order to carry no payment identifiers, so that an order nobody was charged for cannot look like one somebody was

## Implementation Decisions

Ordered as the six steps in the plan, and each is one coherent commit with its tests, deployed to staging as it lands.

### Seams, decided before anything was written

**Three, and no more.** The vocabulary is `.claude/external-services.md`.

1. **The HTTP endpoints.** Almost every test drives `POST /api/v1/orders`, `POST /api/v1/orders/{payToken}/pay` and `POST /api/v1/webhooks/stripe`, which is the highest seam available and the only one that exercises routing, throttle prefixes and raw body handling
2. **`App\Contracts\PaymentGateway`**, which already exists. A fake implementation proves the controllers and **proves nothing whatsoever about Stripe**
3. **One Stripe wire boundary.** `Stripe\StripeClient` is resolved from the container and configured from `config/services.php`, so tests substitute one object in one place. Nothing wraps it in an interface of ours: an interface naming a payment intent is a seam in the wrong place by the rule in `.claude/external-services.md`

Deliberately **not** seams: reconciliation, which is a console command reaching seams 2 and 3, and signature verification, which is a small verifier the webhook route uses directly and the fixture test calls directly.

### 1. A registry of payment methods

- `config/payments.php` stops naming one class and starts naming **the methods this environment offers**, keyed by method name. Local offers `manual`. Deployed environments offer `stripe`, and `gift_code` joins them in the gifting milestone
- A resolver turns a method name into a `PaymentGateway`. **An unrecognised name is refused**, never defaulted, which is the opposite of `config/newsletter.php` and deliberate: a typo must not quietly start charging cards
- `ManualSettlement` is one of the registered methods and is otherwise untouched
- Structure in code, values in config, per CLAUDE.md section 4. Adding a method is a class and a config line

### 2. Columns on `orders`

Three nullable columns, all added in one migration.

- **`charged_amount`**, integer minor units. What Stripe actually collected. Null on a hand-settled order, and that stays true after launch
- **`stripe_payment_intent_id`**, string, **indexed**, because the sweep in step 5 iterates on it. Written back at the moment an intent is created, so a payment is reconcilable even if no notification ever arrives
- **`pay_token`**, an unguessable opaque string, unique, generated when the order is created. It is how a guest proves an order is theirs, and it exists because order ids are sequential integers

Nullable at the database level rather than `NOT NULL DEFAULT 0`, since a default of zero silently claims a real charge of nothing. Production and staging both hold disposable data, confirmed 25 August, so this migration is free today.

A second `/pay` on the same order overwrites `stripe_payment_intent_id` with the newest intent. **The consequence, stated rather than discovered:** the sweep then only knows about the newest intent, and an older one that somehow succeeded is found through the notification's `metadata.order_id` instead. Both paths reach `MarkOrderPaid`, which refuses the second transition.

### 3. `PaymentInstruction`, `StripeCheckout`, and the pay endpoint

**`PaymentInstruction`**, a named value with three named constructors, replacing `?string`:

- `nothingToPay()`, which `ManualSettlement` returns and which gift code redemption will return
- `clientSecret(string $secret)`, which `StripeCheckout` returns
- `redirectTo(string $url)`, which nothing implements today and exists because a redirecting method is plausible and discovering the type cannot express it later is worse

`PaymentGateway::begin(Order $order): PaymentInstruction`. The interface's docblock loses the paragraph defending a nullable string.

**`StripeCheckout implements PaymentGateway`**, via `composer require stripe/stripe-php`:

- Creates a PaymentIntent from **the order's own captured lines**, in **the order's own currency**, with `metadata.order_id` set
- Amounts come from `order_lines.unit_amount` and the stored `total_amount`. **Never from the catalogue and never from Stripe's product catalogue**, so what Stripe collects is what the customer was shown
- Writes the intent id back to the order before returning the secret
- **No `receipt_email` is set.** `OrderPaid` is the receipt, and Stripe emailing a second one means two receipts for one payment
- **No idempotency key across calls**, because a second call is meant to mint a fresh intent. A duplicated intent from a network retry is harmless: both carry the same order id, only one can succeed, and `MarkOrderPaid` refuses the second transition

**`POST /api/v1/orders/{payToken}/pay`**, no locale segment, its own throttle key prefix per the rule in `routes/api.php`, `Cache-Control: no-store`:

- The token in the path is `pay_token`, not the id. An unknown token is a 404
- A **pending** order mints a fresh intent and answers `{"type": "client_secret", ...}`
- A **paid** order answers `{"type": "nothing_to_pay"}` and creates nothing
- A signed-in customer who is not the order's customer is refused, so holding a token and being logged in as somebody else does not combine into access

**API contract changes**, both to be told to the frontend partner rather than discovered by them:

- `POST /api/v1/orders` gains `pay_token` in its response and **drops `payment_url`**. Section 9 of `API_CONTRACT.md` already told them `payment_url` is being replaced and that nothing consuming it exists yet
- Section 9's `/orders/{id}/pay` becomes `/orders/{pay_token}/pay`. The response shapes it already documents are unchanged

The response shapes, as already published:

```json
{ "type": "client_secret", "client_secret": "pi_..._secret_..." }
{ "type": "nothing_to_pay" }
{ "type": "redirect", "url": "https://..." }
```

### 4. The webhook

**`POST /api/v1/webhooks/stripe`**, no locale segment, its own throttle prefix, **signature verified against the raw request body before anything is read**, per hard rule 6.

| Event | What happens |
|---|---|
| `payment_intent.succeeded` | Resolve the order from `metadata.order_id`, check amount and currency match the order, write `charged_amount`, call `MarkOrderPaid`. **The only one that changes state** |
| `payment_intent.payment_failed` | Log. The order stays pending and the customer retries |
| `charge.dispute.created` | Log, and tell Jennifer. Chargebacks arrive whether or not refunds are offered |
| Anything else | Ignored, answered 200 |

- **The raw body is what is verified.** Nothing may parse or re-encode it first
- **The signing secret is per endpoint, not per account**, and lives in config per environment. **A missing secret refuses everything**, since failing open on a webhook is failing open on granting access for free
- **Tolerance: the endpoint always uses `stripe-php`'s default.** There is deliberately **no config knob** for it, because a knob is a way to disable replay protection in production without anything going red. The verifier takes a tolerance argument that only the pinned fixture test passes
- **Amount and currency must match the order.** Stripe Tax is off, so `charged_amount` always equals `total_amount` and any difference is a fault to detect rather than a case to handle. A mismatch is logged at error level, the order is **not** marked paid, and the response is still 200
- **An event for an unknown order answers 200 and logs**, never 500. Stripe retries non-2xx indefinitely, and an endless redelivery about an order that does not exist is a self-inflicted outage
- **Answer fast and do the work on the queue.** An exception during fulfillment must not become an infinite redelivery of a payment that already succeeded. Managed queues deliver at least once, which `MarkOrderPaid` was written for
- No `charge.refunded` subscription, because there are no refunds

### 5. Reconciliation

- A scheduled command in the shape of `cards:reconcile-videos`, which is prior art in this repo down to the console output
- Takes **pending orders older than fifteen minutes carrying a `stripe_payment_intent_id`**, retrieves each intent by id, and calls `MarkOrderPaid` for any Stripe says succeeded, writing `charged_amount` as it goes
- **Driven by our own table**, never by listing or searching Stripe. Bounded by our own pending orders, which is a number in the low tens. An order with no intent id was never paid for and is correctly invisible
- A failed or cancelled intent is **left pending**, not cancelled, per the no-expiry decision. The customer can come back and retry against it
- Registered in `routes/console.php` beside the Bunny sweep, `withoutOverlapping()`
- **The scheduler tick is measured on staging as part of this step**, not assumed and not deferred. It has been carried as unknown since M4, and task frequency lives in code, so a daily platform tick makes this daily and a lost notification then costs a customer a day

### 6. The staging walk

- No application code. `tests/Staging` gains the card path behind its own switch beside `STAGING_INTERACTIVE`
- Stripe test cards including **a declined one** and **one requiring 3D Secure**
- Asserts that a real Stripe test payment moved a real order, observed from outside this application

### What does not change

- **`POST /api/v1/orders` is untouched.** The frontend calls it one step later than it would have, after the wallet answers, and that is the whole difference
- **`PlaceOrder` is untouched.** It already captures prices at purchase and already carries the guards, because phase B was known to be adding a caller that does not route through the form request
- **`MarkOrderPaid` is untouched.** It locks the row, transitions only from pending, grants access inside the transaction and queues mail after commit
- **No fulfillment code is added.** Delivery is driven by the product's `fulfillment` value, so nothing about how an order is delivered learns that Stripe exists
- **`ManualSettlement` and its tests are untouched**, which is what proves step 1 was a refactor

## Testing Decisions

### What makes a good test here

- **Assert external behavior, never implementation detail.** A test that breaks when a method is renamed while nothing observable changed is a cost with no benefit
- **The denial path gets the same care as the success path.** Access control proving only the happy case proves nothing
- **Never assert an external service's format against our own implementation of it.** This is the most expensive lesson in the project: `BunnyStreamTest` did exactly that, M4 closed on it, and playback returned 403 for every viewer for five days with a green suite
- **A fake proves a caller and may never be the only thing proving an implementation**
- **Read a skip as a skip, and count them.** On 23 August a staging test was found to have never executed since it was written, and its totals looked clean. A test that never runs looks exactly like one that passes

### Prior art to follow

| What | Where |
|---|---|
| A known-answer vector captured by hand from a live service | `tests/Feature/MailchimpVectorTest.php`, and `BunnyStreamTest` |
| A webhook answering 200 for a payload it cannot use, and logging | `tests/Feature/Api/BunnyWebhookTest.php` |
| A sweep that reconciles what a webhook may have missed | `tests/Feature/ReconcileCardVideosTest.php` |
| The transition and its idempotency | `tests/Feature/MarkOrderPaidTest.php` |
| Endpoint shape, guards and 422s | `tests/Feature/Api/PlaceOrderTest.php` |
| A walk against a deployed environment | `tests/Staging/`, and its README |

### Per step

**1. The registry.** Each configured method resolves to its class. An unknown method is refused rather than substituted. The existing `ManualSettlement` tests are not edited, and that is the evidence this was a refactor.

**2. The columns.** A manual settlement still works with all three null. `pay_token` is generated, unique, and not derivable from the id. The money columns are nullable at the database level.

**3. `StripeCheckout` and the pay endpoint.** Driven through HTTP against a **fake gateway**, which proves the controller and proves nothing about Stripe:

- A pending order answers `client_secret`
- A paid order answers `nothing_to_pay` and creates nothing
- A second call on a pending order mints a fresh intent
- An unknown token is 404, and a signed-in customer who does not own the order is refused
- The response carries `no-store`, and the route has its own throttle key prefix
- Against the substituted `StripeClient`: the intent carries `metadata.order_id`, the order's captured total, the order's currency, and no `receipt_email`; the intent id is written back to the order

**The fake is the danger in this step, not the safety.** Nothing here may later be read as evidence that Stripe accepts what we send. Step 4 and step 6 carry that.

**4. The webhook, where most of the risk in this milestone lives.**

A **known-answer vector captured from Stripe**, in the shape of `MailchimpVectorTest`. Run `stripe listen` and `stripe trigger payment_intent.succeeded` against test mode, capture the **exact raw body and the exact `Stripe-Signature` header Stripe sent**, commit both, verify offline against the CLI's `whsec_` forever after. Its header says what Mailchimp's says: **do not recompute the expected value from the code the file exists to check.**

**Three tests about tolerance, not one, and the reason is the trap.** `stripe-php` verifies with a 300 second tolerance by default, so a pinned fixture is permanently expired and its test must widen it. Widen it in the wrong place and replay protection is off on the live endpoint with nothing going red.

1. The fixture test, calling the verifier directly with the tolerance widened, proving the signature is genuinely Stripe's
2. A test proving the **endpoint** carries no tolerance override at all
3. A test proving a **genuinely old real signature** is refused by the endpoint

Plus, through the route:

- A forged signature is refused and no order moves
- A body altered after signing is refused
- **A redelivery of the same event changes nothing the second time.** This is the property phase A built for, and this is the first caller to have ever exercised it
- An event naming an unknown order answers 200 and logs
- An amount or currency that disagrees with the order does not mark it paid, and is logged
- A success marks the order paid, writes `charged_amount`, grants the pass where the product's fulfillment says so, and sends exactly one receipt
- An unhandled event type answers 200
- A missing signing secret refuses everything

**5. Reconciliation.** An order paid at Stripe but never notified is picked up. One genuinely pending is left alone. One with no intent id is ignored. One younger than fifteen minutes is left alone. A failed intent leaves the order pending rather than cancelling it. **The sweep is idempotent against `MarkOrderPaid`**, which it gets for free. Separately, and not as a unit test: the scheduler tick measured on staging, with the number written down.

**6. The walk.** A real Stripe test payment against the deployed environment, moving a real order, observed from outside this application, including a declined card and one requiring 3D Secure. Skips are counted and reported rather than read as passes.

## Out of Scope

| Out | Owner |
|---|---|
| Gift codes, issuance and redemption | The gifting milestone, immediately after this one. `docs/plans/gifting-plan.md`. Redemption is a fourth payment method and slots into the registry built in step 1 |
| The 24 hour rush | **Nobody. Dropped by the client, 25 August 2026.** `ProductKey::Rush24h` and `ProductType::Addon` go with it |
| Stripe Tax, and any tax column | Nobody, until Jennifer registers for VAT |
| Refunds, in any form | **Nobody, knowingly.** No refund action in the panel, no `charge.refunded`, no revocation of anything |
| Expiring unpaid orders | Nobody. An unpaid order waits indefinitely and a second `/pay` mints a fresh intent for it |
| Reading an order back, and order history | Not built. There is no `GET /orders` or `GET /orders/{id}`, and the frontend partner has been asked whether their confirmation screen needs one |
| Fulfillment through the panel, PDFs, delivery mail | Its own milestone. `docs/plans/cms-fulfillment.md` |
| AI fulfillment of a reading | M7 |
| Any customer-facing page, including the checkout page itself | The frontend partner, always. Hard rule 1 |
| Retrofitting `BunnyStream` behind an interface | Not this milestone. `.claude/external-services.md` says when |
| Cashier | Declined 21 August 2026, reversing CLAUDE.md section 2. `PaymentGateway` is already the abstraction Cashier would be |

## Further Notes

### Dependencies, and three of them have real-world lead time

- **A Stripe account in test mode**, Jennifer's, with the developer added as a **team member with the Developer role**. Never shared credentials. That role gives API keys, webhook endpoints and logs, and does not give her bank details or payouts
- **The webhook endpoint registered in the dashboard for each environment.** The signing secret is per endpoint, and the wrong one fails identically to a forged request
- **Payment method domain registration for `theworldtarot.com`**, in test and live mode. Apple Pay does not render on an unregistered domain, and it fails by simply not appearing
- **`emailRequired: true` on the Express Checkout Element**, which is a contract requirement on the frontend and not a suggestion. Wallets do not share the customer's email by default. Omit it and the payment still succeeds and we receive an order with no email, which under phase A's rules cannot resolve to a customer. The single easiest thing to omit and the hardest to notice
- **A new design without the 24 hour rush**, since the current screenshot shows it and the frontend partner is building from it
- **An email field on the card path**, which the current design does not have
- **Prices from Jennifer in all three currencies.** Her design shows $75

### Open questions, neither of them blocking the build

1. **Where is Jennifer registered for VAT, if anywhere?** Not blocking, because tax is off. It becomes blocking the day she registers, and the answer decides whether a receipt may show tax at all. **Ask now**, because the answer takes real-world time and nothing here waits for it
2. **What closes M5 commercially?** Open since the split on 8 August and not a technical question. Payment is tied to milestone completion, phase A completing is not M5 completing, and gifting was added as scope on 17 August after that was agreed. **Settle it before this is invoiced**

### The residual left by having no refunds

Recorded rather than hidden, because two of these are not policy questions:

- **Jennifer can refund from the Stripe dashboard whenever she likes**, and nothing here will learn about it. A refunded Viewing Room pass stays granted forever, because `grantViewingRoomAccess` keeps its date on purpose and nothing revokes. Closing this later costs about ten lines
- **Chargebacks arrive regardless.** `charge.dispute.created` is subscribed for that reason: not to change any state, but so somebody finds out
- **UK and EU consumer law gives a 14 day cancellation right on digital content**, waivable only by express consent acknowledged at purchase, and there is no such checkbox in the design. **A question for Jennifer's lawyer, recorded here rather than answered**

### What closes the milestone

Payment being live in production, **walked there rather than only on staging**. Production has never had a walk of any kind, and nothing about staging provides one.
