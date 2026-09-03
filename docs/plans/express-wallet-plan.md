# The express wallet, beside the hosted page

Drafted 29 August 2026. **Not approved, not started.**

The client wants Apple Pay and Google Pay as express wallet buttons on our own
reading page, drawn by Stripe's Express Checkout Element, while the card path
keeps the hosted Checkout Session that landed the same morning.

**The decision and its reasoning are `docs/adr/0003-the-wallet-keeps-its-own-payment-intent.md`.**
This is the work.

---

## Goal

`POST /api/v1/orders/{pay_token}/pay` answers a **client secret** when the
caller names the wallet method and a **redirect** when it names the hosted one,
from one order, one amount and one settlement path. A customer paying with a
wallet on the reading page and a customer paying with a card on Stripe's page
arrive at the same paid order, through the same webhook, having been charged the
same number, and neither of them can end up charged and unserved.

## Out of scope, and who owns it

| Out | Owner |
|---|---|
| The element, the panel, the collapse, the button styling | The frontend partner, always. Hard rule 1 |
| `stripe.confirmPayment`, the `return_url`, the wallet sheet | The frontend. This API answers a client secret and hears about the result from Stripe |
| Turning `googlePay: 'never'` back on, and the `buttonTheme`/`buttonType` maps | The frontend |
| Registering the payment method domain | A person, in the Stripe dashboard. See "What a person has to do" |
| Gift codes as a third method | Gifting milestone, unchanged. `docs/plans/gifting-plan.md` |
| Anything about `checkout.session.completed` | Nobody. We still do not subscribe to it |

## What this builds on, read from the codebase on 29 August 2026

- **`PaymentInstruction` is a tagged union** with `client_secret` already a case,
  already rendered by `toArray()`, already pinned by `PaymentInstructionTest`.
  Nothing about the response shape needs inventing
- **`PaymentMethods` is already a per-payment registry**, and `PayOrderRequest`
  already validates `method` against `array_keys(config('payments.methods'))`.
  A new method name is accepted the moment config declares it, with no request
  change
- **Settlement is keyed on the intent, not the Session.** `StripePayment::from(PaymentIntent)`,
  `metadata.order_id`, `MarkOrderPaid`. A wallet payment travels it unchanged
- **`StripeCheckout` holds two guards that are not about Checkout Sessions**:
  `refuseAnythingButAPendingOrder()` and the total-vs-lines check that logs and
  throws. Both belong to every road money can arrive down
- **`StripeCheckout` at `94bf99f` is the PaymentIntent gateway**, before `1fd46f6` replaced it. Read
  it before writing a new one; its comments were paid for
- **`BuyerFromCheckoutSession` cannot answer for a wallet payment**, and answers
  null correctly rather than wrongly. `RecordStripePayment::identify()` treats
  null as a refusal to settle. That is the trap
- **`ExpressCheckout.tsx` on the frontend is already the deferred-intent shape**:
  `mode: 'payment'`, `amount`, `currency`, `emailRequired: true`. Only
  `onConfirm` is stubbed, and it deliberately calls `paymentFailed`

---

## The work, in the order it should be done

### 1. Pull the guards out of `StripeCheckout` first

Before anything new exists. `refuseAnythingButAPendingOrder()`, the total-vs-lines
check, and `whatTheyAreBuying()` become something both gateways call — a small
collaborator taking an `Order`, not a trait, so it can be named and tested on its
own.

**Doing this first is the point.** Written second, the two roads get two copies
of a guard whose entire job is to shout when an order's total disagrees with its
lines, and copies drift. `StripeCheckoutTest` should stay green across this step
with no changes to its assertions; if it does not, the extraction changed
behaviour and that is the finding.

### 2. Fix buyer identity, before the gateway exists

**This is the blocker and it is deliberately step 2 rather than step 5.** A
gateway shipped ahead of it is a way to take money and deliver nothing.

`RecordStripePayment::identify()` needs a resolver that can answer for both
roads:

- **The hosted road**: list Checkout Sessions by payment intent, read
  `customer_details`. This is exactly what `BuyerFromCheckoutSession` does now
  and it does not change
- **The wallet road**: retrieve the intent with `expand: ['latest_charge']` and
  read `latest_charge.billing_details` — the email and the name the wallet
  collected

Shape it as one collaborator the action asks, with the two lookups behind it,
rather than teaching `RecordStripePayment` which road an order came down. It has
no way to know and should not learn one: the order carries an intent id and
nothing else, which is the whole reason settlement was kept single.

**Order of attempts matters and should be argued in the code.** The charge is
one retrieve and the Session is one list; the charge is present for both roads
while the Session is present for one, which argues for the charge first and the
Session as the fallback. That is a change in behaviour for hosted payments and
needs to be either taken deliberately or explicitly declined in favour of
branching on what the order knows.

**Nothing here may throw.** The existing class says why: settlement is reached
from a webhook Stripe redelivers forever on a non-2xx. A second lookup is a
second thing that can fail and must fail the same way — null, logged, and the
order left pending for the sweep.

`BuyerIdentity` needs no change.

**`SettleAnUnownedOrderTest` is the test that already covers this ground** and
is where the wallet case joins it.

### 3. The gateway

`app/Services/Payments/StripeWallet.php`, implementing `PaymentGateway`:

- Runs the extracted guard from step 1
- `paymentIntents->create` with `amount: $order->total_amount`, the order's
  currency lowercased, `metadata: ['order_id' => (string) $order->id]`, a
  description from `whatTheyAreBuying()`, and **`payment_method_types: ['card']`**
- Writes `stripe_payment_intent_id` before returning, for the same reason
  `StripeCheckout` does and with none of the Session indirection
- Answers `PaymentInstruction::clientSecret($intent->client_secret)`
- Ignores `$returnTo`. There is nowhere to return from, which the
  `PaymentGateway` docblock already allows for

**`payment_method_types: ['card']` rather than `automatic_payment_methods`.**
Apple Pay and Google Pay are both the `card` type, so naming it explicitly gets
both and nothing else. It mirrors the frontend's decision to set every other
wallet to `'never'`, and it keeps redirect-based methods off this road entirely,
which is what makes the `return_url` question small.

**No idempotency key**, for the reason already written in `StripeCheckout`: a
second call is meant to mint a fresh intent, because a declined card is a retry.

### 4. Config

`config/payments.php` gains `stripe_wallet`, gated on the same
`blank(env('STRIPE_SECRET')) || blank(env('STRIPE_WEBHOOK_SECRET'))` check as
`stripe`. **Both are offered or neither is**, and the gate should be computed
once rather than written twice, or the two can come to disagree about what
"configured" means — which is the defect `de1e28c` already fixed once.

**The existing comment about Apple Pay, Google Pay and card being one method
because they are one PaymentIntent stops being true** and is rewritten in the
same commit.

`PaymentsConfigTest` and `PaymentMethodsTest` are where this is pinned.

### 5. The offered-methods endpoint

`PaymentMethods::offered()` has had no production caller since `051c6a1` and now
has a reason for one: the frontend draws one button or two depending on whether
this environment configured Stripe, and it cannot infer that.

**Small, unauthenticated, cacheable, and it names methods and nothing else.** It
must not grow into anything that describes an order.

Agree the shape with the frontend before building it — it is a new published
endpoint, and section 9 of `API_CONTRACT.md` exists precisely so these are
agreed before rather than after.

### 6. The contract

`API_CONTRACT.md` section 9 needs a third pass on this topic in five days, and
should say so rather than reading as though the design were settled:

- **`client_secret` is back**, and both cases are live simultaneously. The
  sequence branches at step 4: `redirect` sends the browser away, `client_secret`
  is confirmed in the page, `nothing_to_pay` goes straight to the confirmation
- **The `emailRequired: true` note is wrong from today.** It currently says the
  requirement "is gone with the element". It is a requirement again, and the
  buyer's identity depends on it
- **The method names are the frontend's switch**: `stripe`, `stripe_wallet`,
  `manual`
- **`POST /orders/status` covers both roads unchanged**, which is worth stating
  because it is the question they would otherwise ask

---

## What must be proved, and what cannot be

Per `.claude/external-services.md`: **a fake proves a caller and never proves an
implementation.** A substituted `StripeClient` will agree with this gateway
whatever it does.

**The one fact everything rests on, and it needs a real device:** that Apple
Pay's email arrives at `latest_charge.billing_details.email` when the element is
mounted with `emailRequired: true`. If it does not, step 2's resolver answers
null, every wallet order is left pending, and the fallback is the
ConfirmationToken route the ADR describes and declined. **Prove this before
building step 3**, not after — it is one sandbox payment and it decides the
shape of the work.

**The staging walk is the authority**, and `tests/Staging/CardPaymentWalkTest.php`
is the pattern. A wallet walk cannot be automated the way the card walk was —
there is no headless browser with a wallet, which is the same reason
`check:panel` on the frontend can only assert the collapse. It is a person, on a
real iPhone, on a registered domain.

**What CI can pin**: that the registry offers what config declares, that the
instruction renders as `client_secret`, that the guards refuse a non-pending
order and a total that disagrees with its lines on both roads, and that
`RecordStripePayment` settles a wallet payment whose identity came from a charge.
None of that is evidence Stripe accepts what we send.

---

## What a person has to do, and it blocks the walk

1. **Register `theworldtarot.com` as a Stripe payment method domain, in test and
   in live.** Apple Pay does not render on an unregistered domain. **It fails by
   not appearing**, which is indistinguishable from a device with no wallet, and
   therefore indistinguishable from the integration working correctly. Nothing
   goes red anywhere
2. **A real device with a real card in its wallet**, for the walk. Stripe returns
   a test token against test keys, so no money moves, but the device and the card
   have to be real
3. Both belong in `docs/reviews/phase-b-human-tasks.md` alongside the items
   already there

---

## Open questions

- **`stripe_wallet` or `stripe_express`.** The ADR argues for the first. It is
  a published value the moment the frontend switches on it, so it is agreed
  before it ships
- **Whether the charge or the Session is tried first** in the identity resolver,
  and whether hosted payments change behaviour as a result. Step 2
- **Whether `POST /orders/status` should say which road an order is on.** It
  currently answers an intent status and nothing else, which is right. Raised
  only because the confirmation screen now has two ways to have arrived, and the
  answer is probably still no
- **Whether the offered-methods endpoint ships with this or waits.** It is not
  strictly required — the frontend could hardcode two buttons — but hardcoding
  means a laptop with no Stripe keys draws a wallet button that fails on the
  first call, which is the thing `config/payments.php` refuses to do on purpose
