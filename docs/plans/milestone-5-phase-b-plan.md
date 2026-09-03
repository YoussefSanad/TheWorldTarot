# Milestone 5, phase B: taking the money

> **Built and merged 26 August 2026, PR #37, deployed to staging, and the card
> walk passed there the same day.** All six steps. Kept as written so the
> deviations mean something.
>
> **One part of it was reversed on 29 August**, three days after it merged.
> `StripeCheckout` creates a hosted Checkout Session and answers a redirect,
> rather than creating a PaymentIntent for a form on our own page. Everything
> below arguing for checkout on our own page is the record of a decision that
> no longer holds. `docs/adr/0002-the-redirect-case-comes-back.md` says why,
> and says what did not change with it. **Settlement is untouched.**
>
> `docs/CHECKPOINT.md` is the current state.

**Revised 25 August 2026, and this replaces the 21 August draft entirely.** Section 0.2 asks for a plan first, revised at least once, and this is that revision rather than the end of it.

Phase A is the other half of this milestone and shipped on 12 August: identity, access, orders, fulfillment, and a payment boundary with nothing behind it. Its plan and close-out are `docs/plans/milestone-5-plan.md`.

---

## What changed from the 21 August draft, and why

The draft was ten steps. This is six. **Every removal was a decision made by somebody rather than a simplification made here**, and the one addition came from reading Stripe's documentation rather than from an opinion.

| 21 August | 25 August | Why |
|---|---|---|
| Hosted Stripe Checkout, `begin()` returns a URL | **Express Checkout Element and Payment Element, on our own page** | Jennifer's design puts Apple Pay and Google Pay buttons on the reading page itself. **There is no way to hand a wallet choice to the hosted page**, so the redirect cannot deliver that design. Below |
| `checkout.session.completed`, order id in `client_reference_id` | **`payment_intent.succeeded`, order id in `metadata`** | Falls out of the above. A PaymentIntent has no `client_reference_id`. The draft's event does not exist in this flow |
| `automatic_tax` on, `tax_behavior: inclusive` | **Stripe Tax off, and no `tax_amount` column** | Decided 25 August. Prices stay tax inclusive, which is trivially true while no tax is computed. Revisit only if Jennifer registers for VAT |
| Steps 7 and 8, gift codes | **Their own milestone**, `docs/plans/gifting-plan.md` | Gifting is required at launch, so it ships before launch, but after payment is proved. Its redemption path bypasses money entirely and should not be written alongside the thing it bypasses |
| Step 9, the 24 hour rush | **Gone.** Dropped by the client, 25 August | `ProductKey::Rush24h` and `ProductType::Addon` come out with it |
| Step 10, receipts and refunds | **No refunds at all**, and `OrderPaid` is already the receipt | Decided 25 August. The residual is recorded below rather than closed |
| `config/payments.php` names one gateway for the environment | **A registry of the methods this environment offers** | The design has four payment buttons and the customer picks one, so the gateway is chosen per payment rather than per environment |
| `begin(Order): ?string` | **`PaymentInstruction`, a named value** | Three drivers now answer three genuinely different things. A nullable string that means a URL, or a client secret, or nothing to pay, is folklore |

## Goal

A person puts something in a basket, pays for it with a card or a wallet **without leaving the website**, and everything phase A already built happens by itself. Nothing downstream changes: `MarkOrderPaid` remains the one moment an order becomes paid, and a webhook raises exactly the transition a person in the panel raises today.

**The shape of the work is unusually favourable, and that was the point of splitting the milestone.** Everything that happens after money arrives is built, tested and walked in a deployed environment. What phase B adds is a new caller of it.

## What the assessment found

Read on 21 August 2026, and **re-verified in the code on 25 August**. Every line of it still holds.

- **`App\Contracts\PaymentGateway` has one method**, `begin(Order $order): ?string`. It fits Stripe, but its return type stops carrying its meaning under this flow. Step 3
- **`MarkOrderPaid` already survives redelivery by structure**, not by discipline: the row is locked, the transition only fires from pending, access is granted inside the transaction and mail is queued after commit. A payment webhook arriving twice is the case it was written for
- **`PlaceOrder` captures prices at purchase** and never reads the catalogue again. It carries a guard for an unknown or unpriced product, and a second guard added 24 August refusing the pass to somebody who already holds it. Both exist because **phase B adds a caller that does not route through the form request**
- **`config/payments.php` names a class**, `ManualSettlement`
- **Nothing Stripe is installed.** No Cashier, no `stripe/stripe-php`
- **There is no tax column on `orders`**, and after 25 August there is not going to be one
- **Fulfillment is driven by the product's `fulfillment` value**, so nothing about how an order is delivered has to learn that Stripe exists. All four readings are `ManualPdf` and the pass is `AccessGrant`, which means **phase B adds no fulfillment code at all**
- **738 tests green**, `migrate:fresh --seed` clean, staging carrying every phase A path

## Out of scope, and who owns it

| Out | Owner |
|---|---|
| Gift codes, issuance and redemption | The gifting milestone, immediately after this one. `docs/plans/gifting-plan.md` |
| The 24 hour rush | **Nobody. Dropped by the client, 25 August 2026** |
| Stripe Tax, and any tax column | Nobody, until Jennifer registers for VAT |
| Refunds, in any form | **Nobody, knowingly.** See the residual below |
| Expiring unpaid orders | Nobody. Decided 25 August, reasoning below |
| Fulfillment through the panel, PDFs, delivery mail | Its own milestone. `docs/plans/cms-fulfillment.md` |
| AI fulfillment of a reading | M7 |
| Any customer-facing page, including the checkout page itself | The frontend partner, always. Hard rule 1 |
| Retrofitting `BunnyStream` behind an interface | Not this milestone. `.claude/external-services.md` says when |

---

## Decided

### Stripe through `stripe/stripe-php`, not Cashier

Decided 21 August 2026, reversing `CLAUDE.md` section 2 which named Cashier before phase A had built the interface that makes it redundant. Cashier is built for subscriptions and this shop sells one-off products with no subscription anywhere in the roadmap. It would bring a `Billable` trait, a `stripe_id` column and subscription tables nothing reads, in order to supply a checkout session and a webhook route. `PaymentGateway` is already the abstraction Cashier would otherwise be, so taking Cashier means having two.

**The cost accepted is that signature verification is ours**, and hard rule 6 now rests entirely on that check. Step 4 and its tests exist because of this sentence.

### Checkout happens on our own page, not on Stripe's

**Decided 25 August 2026, reversing the draft.** This is the largest change and it was forced by a fact rather than a preference.

Jennifer's reading page carries an Apple Pay button, a Google Pay button and a "Pay with Card" button, above the price and under the question field. **Stripe has no parameter, on the session or in the URL, that pre-selects or auto-launches a wallet on its hosted page.** The most a redirect can do is land somebody on `checkout.stripe.com` where Apple Pay is one option among several. The design cannot be delivered by a redirect.

It is also not a naive design. Stripe's own Apple Pay best practices tell you to *"display the Apple Pay button on product detail pages"* and to *"ask the customer for all mandatory information about their order before showing the Apple Pay button"*, which is exactly her question field followed by her buttons. They cite a 250% conversion increase for express checkout on product pages.

So:

- **Apple Pay and Google Pay** come from the **Express Checkout Element**, mounted on the reading page
- **Pay with Card** reveals a **Payment Element** on the same page, with an email field above it
- **Nobody is redirected anywhere**, on any path

**The email is not typed by wallet payers, and this is the part that surprised everybody.** Stripe: *"These wallets don't share the customer's email by default. Pass `emailRequired: true` so that wallets, such as Apple Pay and Google Pay, expose an email field during checkout. This option ensures you receive the email in the `onConfirm` callback."* The Apple Pay sheet shows an email row the customer confirms with Face ID along with everything else.

**`emailRequired: true` is therefore a contract requirement on the frontend, not a suggestion.** Omit it and the payment still succeeds, and we receive an order with no email, which under phase A's rules cannot resolve to a customer.

### The order is created after the wallet answers, and phase A survives untouched

Stripe's recommended pattern is *"collect payment details before creating an Intent"*. The real sequence:

1. Element mounts with an amount and a currency. **No order and no PaymentIntent exist**
2. Customer taps Apple Pay. Sheet opens. They authenticate
3. `onConfirm` fires **carrying their email**
4. Frontend calls `POST /api/v1/orders` with that email, the exact contract it has today
5. Frontend calls `POST /api/v1/orders/{id}/pay` and receives a client secret
6. Payment confirms

**`PlaceOrder` still captures prices before any money moves, and `POST /api/v1/orders` does not change.** The frontend calls it one step later than it would have, and that is the whole difference.

Two calls rather than one, deliberately, and not because anything already consumes the endpoint. **If the payment call fails, the order exists and is pending and the customer retries the payment.** Fold them into one and a failed payment means POSTing the order again, which produces a duplicate pending order every time somebody's card is declined.

### A payment method is chosen per payment, not per environment

`config/payments.php` currently binds one gateway for the whole application. The design has four buttons and the customer picks one, so that was only ever right while there was one.

Config names **which methods this environment offers**. Locally that is `manual` alone. In deployed environments it is `stripe`, and after the gifting milestone `gift_code` as well. An unrecognised method is refused rather than falling through to Stripe, **which is the opposite of the newsletter's default and deliberate: a typo must never start charging cards.**

### `PaymentInstruction`, because three drivers give three different answers

`ManualSettlement` collects nothing. `StripeCheckout` returns a client secret. `GiftCodeRedemption`, when it arrives, has already settled the order. Those are three answers, not three meanings of one nullable string, and the frontend should read a field name rather than infer from a shape.

Three named constructors: nothing to pay, a client secret, a redirect. The redirect case has no implementation today and exists because a payment method that redirects is a plausible thing to add and the alternative is discovering the type cannot express it later.

### Prices are tax inclusive, and no tax is computed

Decided 21 August, unchanged. The price Jennifer sets is what the customer pays. **What changed on 25 August is that Stripe Tax is off**, so `charged_amount` always equals `total_amount` and there is no arithmetic anywhere. Any order where they differ is a fault worth detecting rather than a case to handle, and step 4 is where it gets detected.

**No `tax_amount` column.** Machinery nothing reads is worse than none, which is the same argument that kept email verification out of phase A. If Jennifer registers for VAT, the column arrives with the step that turns tax on, and Postgres adds a nullable column without rewriting the table.

### An unpaid order never expires

Decided 25 August. Nothing writes `OrderStatus::Cancelled` today and nothing will. A customer who abandons the wallet sheet and comes back on Tuesday finds their order still pending, and **a second call to `/pay` mints a fresh intent for it**.

This is smaller than the alternative and it is correct. Expiry only becomes necessary alongside gift codes, where a pending order holds a code somebody else wants, and that lives in the gifting milestone. It also means the webhook resolving an order from `metadata.order_id` settles it whichever intent paid for it, and a late second intent is the redelivery case `MarkOrderPaid` already survives.

### There are no refunds, and here is exactly what that leaves open

**Decided 25 August 2026 by the client.** No refund action in the panel, no `charge.refunded` subscription, no revocation of anything.

**Written down rather than hidden, because two of these are not policy questions:**

- **Jennifer can refund from the Stripe dashboard whenever she likes**, and nothing in this system will learn about it. A refunded Viewing Room pass stays granted forever, because `grantViewingRoomAccess` keeps its date on purpose and nothing revokes
- **Chargebacks arrive regardless.** A customer disputing with their bank takes the money back whether or not refunds are offered. `charge.dispute.created` is subscribed for that reason: not to change any state, but so somebody finds out
- **UK and EU consumer law gives a 14 day cancellation right on digital content**, waivable only by express consent acknowledged at purchase. There is no such checkbox in the current design. **This is a question for Jennifer's lawyer and it is recorded here rather than answered**

Closing the first of these later costs about ten lines. It is not being built now because it was not asked for.

---

## Open questions

**1. Where is Jennifer registered for VAT, if anywhere?** Not blocking, because tax is off. It becomes blocking the day she registers, and the answer determines whether a receipt may show tax at all. **Ask her now anyway**, because the answer takes real-world time and nothing about this plan waits for it.

**2. What closes M5 commercially?** Open since the split on 8 August and it is not a technical question. Payment is tied to milestone completion, phase A completing is not M5 completing, and gifting was added as scope on 17 August after that was agreed. **Settle it before this starts rather than after.**

## Dependencies

- **A Stripe account, in test mode.** It must be **Jennifer's**, with the developer added as a **team member with the Developer role**. Never shared credentials: that role gives API keys, webhook endpoints and logs, and does not give access to her bank details or payouts. Test mode is enough for steps 1 to 6
- **The webhook endpoint registered** in the Stripe dashboard for each environment. **The signing secret is per endpoint, not per account**, and the wrong one fails identically to a forged request
- **Payment method domain registration** for `theworldtarot.com`, in both test and live mode. Apple Pay does not render on an unregistered domain
- **A new design without the 24 hour rush.** The current screenshot shows it as a radio under the price and as a bullet in the feature list, **and the frontend partner is building from it**
- **An email field on the card path**, which the current design does not have
- **`emailRequired: true`** on the Express Checkout Element
- **Prices from Jennifer in all three currencies.** Her design shows $75, and everything seeds at zero

---

## The steps

Each is one coherent commit with its tests written in the same step, per section 0.4. **Each is deployed to staging as it lands**, per section 6, because the phase A close-out named batching nine steps before deploying any of them as its worst deviation.

### 1. A registry of payment methods

**Build.** `config/payments.php` stops naming a class and starts naming the methods this environment offers. A small resolver turns a method name into a `PaymentGateway`. `ManualSettlement` is one of them and is unchanged.

An unrecognised method is **refused**, not defaulted. `config/newsletter.php` falls through to Mailchimp on purpose, so a typo cannot quietly stop collecting subscribers. The opposite is true here: a typo must not quietly start charging cards, and refusing is the safe direction.

**Tests.** Each configured method resolves to its class. An unknown method is refused rather than silently substituted. **The existing `ManualSettlement` tests are not touched, which is what proves this was a refactor.**

**Could go wrong.** Almost nothing, which is why it is first. It exists so that every later step can sit on staging without being live in production on the same push.

### 2. `charged_amount` and `stripe_payment_intent_id`

**Build.** Two nullable columns on `orders`. `charged_amount` is integer minor units. `stripe_payment_intent_id` is indexed, because step 5 iterates on it.

Nullable because a manually settled order has neither, and that stays true after launch.

**Tests.** A manual settlement still works with both null. The columns are nullable at the database level, since a `NOT NULL DEFAULT 0` here would silently claim a real charge of nothing.

**Could go wrong.** The draft called this the free-now expensive-later migration. **It is neither now**: production and staging both hold no real orders and their data is disposable, confirmed 25 August.

### 3. `StripeCheckout`, `PaymentInstruction`, and the pay endpoint

**Build.** `composer require stripe/stripe-php`. `StripeCheckout implements PaymentGateway` creates a PaymentIntent from the order's own captured lines, in the order's own currency, with `metadata.order_id` set and the intent id written back to the order.

Amounts come from `order_lines.unit_amount`. **Never from the catalogue and never from Stripe's product catalogue**, so what Stripe collects is what the customer was shown, even if Jennifer edits a price between the order and the payment.

`PaymentGateway::begin()` returns a `PaymentInstruction` rather than `?string`. `POST /api/v1/orders/{id}/pay` is the route, with its own throttle prefix.

**A second call for an order already pending mints a fresh intent**, per the no-expiry decision. A call for an order already paid answers without creating anything.

**Tests.** The endpoint's behaviour against a fake gateway, which proves the controller and **proves nothing whatsoever about Stripe**. That a paid order cannot be paid again. That the intent carries the order id and the captured amount. That an order belonging to somebody else is refused.

**Could go wrong.** The fake is the danger, not the safety. `.claude/external-services.md`: an interface makes a fake trivial, a fake makes a suite green, and that is precisely how M4 shipped playback that had never worked. **Nothing in this step may later be read as evidence that Stripe accepts what we send.** Step 4 carries that burden.

### 4. The webhook, and the vector that proves it

**Build.** One route, no locale segment, its own throttle prefix, **signature verified against the raw request body before anything is read**, per hard rule 6.

| Event | What happens |
|---|---|
| `payment_intent.succeeded` | Resolve the order from `metadata.order_id`, check the amount and currency match, call `MarkOrderPaid`. **The only one that changes state** |
| `payment_intent.payment_failed` | Log. The order stays pending and the customer retries |
| `charge.dispute.created` | Log, and tell Jennifer. Chargebacks arrive whether or not refunds are offered |

An event for an unknown order answers **200 and logs**, never 500, because Stripe retries non-2xx indefinitely and an infinite redelivery about an order that does not exist is a self-inflicted outage. Answer fast and do the work on the queue.

**Tests, and this is where most of the risk in the milestone lives.**

A **known-answer vector captured from Stripe**, in the shape of `MailchimpVectorTest`. Run `stripe listen` and `stripe trigger payment_intent.succeeded` against test mode, capture the **exact raw body and the exact `Stripe-Signature` header Stripe sent**, commit both, verify offline against the CLI's `whsec_` forever after. Its header says what Mailchimp's says: **do not recompute the expected value from the code the file exists to check.**

**Three tests, not one, and the reason is the trap.** `stripe-php` verifies with a **300 second timestamp tolerance** by default, so a pinned fixture is permanently expired and its test must widen the tolerance. Widen it in the wrong place and **replay protection is disabled on the live endpoint and nothing goes red.** So:

1. The fixture test, with tolerance widened, proving the signature is genuinely Stripe's
2. An independent test proving the **real endpoint** constructs its verifier with the default tolerance
3. A test proving an old timestamp is **refused** there

Plus: a forged signature is refused without the order moving. **A redelivery of the same event changes nothing the second time**, which is the property phase A built for and this is the first caller that has ever exercised it.

**Could go wrong.** This is the step where a mistake takes money without delivering, or delivers without taking money. Laravel must not have parsed or re-encoded the body before verification. The signing secret is per endpoint. An exception thrown during fulfillment becomes an infinite redelivery of a payment that already succeeded, which is why the work goes on the queue after a fast 200.

### 5. Reconciliation, because webhooks are lost

**Build.** A scheduled sweep, in the shape of the existing card video reconcile. It takes **pending orders older than fifteen minutes that have a `stripe_payment_intent_id`**, retrieves each intent by id, and calls `MarkOrderPaid` for any Stripe says succeeded.

Driven by our own table rather than by listing or searching Stripe. It is bounded by our own pending orders, which is a number in the low tens, and an order with a null intent id was never paid for so it is correctly invisible.

A failed or cancelled intent is **left pending**, not cancelled, per the no-expiry decision. The customer can come back and retry against it.

**Tests.** An order paid at Stripe but never notified is picked up. One genuinely pending is left alone. One with no intent id is ignored. **The sweep is idempotent against `MarkOrderPaid`**, which it gets for free, because that action has locked the row and refused a second transition since phase A.

**Could go wrong, and this step is where it stops being theoretical.** **Nobody has ever verified that the platform ticks `schedule:run` every minute.** It has been carried as unknown since M4. Task frequency lives in code, so a daily tick makes this daily and a lost webhook then costs a customer a day. **This step measures it on staging rather than assuming it**, and the measurement is part of the step rather than a follow-up.

### 6. The staging walk

**Build.** Nothing. Extend `tests/Staging` with the card path, behind its own switch beside `STAGING_INTERACTIVE`, using Stripe's test cards including a declined one and one requiring 3D Secure.

**Tests.** The walk itself, asserting that a real Stripe test payment moved a real order, observed from outside this application.

**Could go wrong.** The M4 lesson in its usual clothes. A green suite over a dead integration is how playback shipped broken for five days. **And its companion**, from 23 August: read a skip as a skip, and count them. A test that never executes looks exactly like one that passes.

---

## What closes the milestone

Payment being live in production, **walked there rather than only on staging**. Production has never had a walk of any kind, and nothing about staging provides one.

**The commercial question of what closes M5 is still open and is not a technical one.** M5 was split in two after payment was tied to milestone completion, and gifting was added as scope on 17 August after that. Settle it before this is invoiced rather than after.
