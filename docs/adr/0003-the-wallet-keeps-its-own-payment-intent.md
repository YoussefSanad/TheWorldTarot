# The wallet keeps its own PaymentIntent, and `/pay` answers two ways

> **Decided 29 August 2026**, at the client's request, the same day as
> `0002-the-redirect-case-comes-back.md`. It does not reverse that ADR. It
> amends it: the hosted page stays and stops being the only road.
>
> The client wants the express wallet buttons — Apple Pay and Google Pay — on
> our own reading page, drawn by the Express Checkout Element, alongside the
> hosted page that 0002 introduced.

## Why 0002 stands and is not enough

ADR 0002 chose one button of a known size over a wallet sheet whose height the
panel cannot lay out around. That reasoning was the frontend's and it was about
**the card button**. It was never an argument that a wallet button is
unwelcome; it was an argument that a wallet *sheet* cannot be the only control
on the panel. Those are different claims and 0002 only made the second one.

So the panel gets both: a wallet button that collapses to nothing when the
visitor's device has no wallet, and a card button of a fixed size beneath it
that is always there. That is the arrangement `ExpressCheckout.tsx` was already
built for, and the reason its row holds `h-0 overflow-hidden` rather than
reserving space.

## The two cannot be one thing

**A hosted Checkout Session is an address you send a browser to. The Express
Checkout Element is an iframe you mount.** No parameter reconciles them. A
Session created with `ui_mode: hosted_page` has a `url` and nothing an element
can be initialised from, and the element has no way to be handed one.

So the wallet needs `/pay` to answer something that is not a redirect. It needs
a client secret it can confirm against — which is a case this contract already
publishes and this codebase already has a class for.

## What changes

**`/pay` gains a second Stripe method**, chosen per payment the way
`config/payments.php` has always said the method is chosen:

- **`stripe`** stays exactly as it is. A hosted Checkout Session, answering
  `PaymentInstruction::redirect($session->url)`. The card button
- **`stripe_wallet`** is new. It creates a **PaymentIntent** and answers
  `PaymentInstruction::clientSecret($intent->client_secret)`. The wallet button

This is what `StripeCheckout` was at `94bf99f`, before `1fd46f6` replaced it.
The code is recoverable from history and its reasoning is worth reading before
it is rewritten from scratch.

**`PaymentInstruction::clientSecret()` is un-withdrawn a second time.** It was
removed on 26 August as a case with no caller, restored in shape on 29 August,
and now has a caller again. The tagged union has absorbed a published case being
withdrawn and returned twice inside five days without a breaking change, which
is the entire argument for it not being a nullable string.

## What does not change, which is the larger half

- **Settlement.** `payment_intent.succeeded`, keyed by `metadata.order_id`. A
  wallet payment emits exactly that event, so the webhook, `SettleStripePayment`,
  `RecordStripePayment` and `MarkOrderPaid` are untouched. **We still do not
  subscribe to `checkout.session.completed`**
- **`ReconcileOrders`.** It sweeps pending orders holding an intent id. A wallet
  order holds one from the moment the payment starts, so it is swept identically
- **`POST /orders/status`.** It retrieves the intent named on the order. It does
  not know or care which road minted it
- **The amount.** `$order->total_amount`, and the total-vs-lines guard that
  shouts. Both gateways run the same guard, which is why it stops living inside
  `StripeCheckout` and becomes something both call
- **`stripe_payment_intent_id` is still written before the instruction goes
  out.** On this road it is easier rather than harder: a PaymentIntent has its
  id at creation, with no Session in between

## The trap, and it is the whole of the work

**`RecordStripePayment::identify()` will refuse every wallet payment**, and it
will do it quietly enough to look like nothing happened.

It calls `BuyerFromCheckoutSession::forPaymentIntent()`, which lists Checkout
Sessions by payment intent. **A wallet PaymentIntent has no Session.** The list
comes back empty, the resolver answers null — correctly, and its docblock
already says as much — `identify()` returns false, and the order is left
pending. The customer has been charged. `ReconcileOrders` comes back to it and
hits the same empty list every time, forever.

The log line for this already exists: *"A paid order could not be attached to a
buyer and was left pending."* It would fire on every wallet payment ever taken.

**Identity therefore needs a second source, and shipping the gateway without it
is shipping a way to take money and deliver nothing.** The source is the
intent's own charge: `latest_charge.billing_details`, which carries the email
and the name the wallet collected when the element is mounted with
`emailRequired: true`. The frontend already sets that flag, and `#31` committed
to it before the hosted page briefly made it moot.

`BuyerIdentity::from(?string, ?string)` needs no change. Its docblock already
allows for "a payment made ... by any route that is not a hosted Checkout
Session". The value object was written for this; the resolver was not.

**What must be proved rather than assumed**, per `.claude/external-services.md`:
that Apple Pay's email actually arrives at `latest_charge.billing_details.email`
with `emailRequired: true` set. A substituted `StripeClient` will agree with
whatever we write. Only a real wallet on a real device settles it, and the whole
identity fix rests on that one fact.

## What was considered and not taken

**A Checkout Session with `ui_mode: 'elements'`.** This is a genuine mode that
does support the Express Checkout Element, and it is attractive because a Session
exists, which means `BuyerFromCheckoutSession` would keep working untouched and
the trap above would not exist. It is also the direction Stripe's own
documentation now pushes.

It was declined on three counts, in order of weight:

1. **The Session's `payment_intent` is not populated until the customer
   confirms** in that mode, because the intent is created at confirmation rather
   than at creation. That breaks "written back before the instruction goes out",
   which is the invariant `ReconcileOrders` and `POST /orders/status` both stand
   on. Recovering it means storing a session id, adding a second lookup to both,
   and a migration — or subscribing to `checkout.session.completed`, which 0002
   refused for reasons that have not changed. **This needs confirming against a
   sandbox before anybody reconsiders**, but the risk alone is enough: the
   alternative preserves the invariant with certainty
2. **The frontend is already built for the intent.** `ExpressCheckout.tsx`
   mounts `Elements` with `mode: 'payment'`, an `amount` and a `currency` — the
   deferred-intent shape, exactly. Only `onConfirm` is stubbed
3. **`ui_mode: 'elements'` needs a newer Stripe.js surface** than the pinned
   `@stripe/stripe-js@9.14.0` and `@stripe/react-stripe-js@6.8.2`:
   `CheckoutElementsProvider` and `initCheckoutElementsSdk` rather than
   `Elements`. That is a frontend upgrade bought for a backend convenience

**Server-side confirmation through a ConfirmationToken** was also considered, and
is the better shape on one axis: the buyer's email would reach us in a request we
control, before any webhook, rather than being read back off a charge. The
frontend's `paymentMethodCreation: 'manual'` is already set for it. It was not
taken because it needs a new endpoint and a new confirmation path beside the most
safety-critical code here, to solve a problem that one expanded retrieve also
solves. **Revisit it if the charge's billing details turn out not to carry the
email.**

## Naming, because the registry's names are the frontend's switch

`stripe_wallet` rather than `stripe_express`. The element is Stripe's "Express
Checkout Element", but what the customer picks is a wallet, and the frontend's
own component docblock is emphatic that the thing draws wallet buttons plural
rather than an Apple Pay button. The name says how the money arrives, which is
what `config/payments.php` says a method is.

**It is a published value the moment the frontend switches on it**, so it is
agreed with them before it ships and not after.

**The existing comment in `config/payments.php` stops being true.** It says
Apple Pay, Google Pay and card "are one method here because they are one
PaymentIntent at Stripe". They are now two methods, because they are a
PaymentIntent and a hosted Session. The comment goes with the change rather than
after it.

## What the frontend now needs

**`emailRequired: true` is a requirement again**, not a leftover. Section 9 of
`API_CONTRACT.md` currently says it "was a requirement here and is gone with the
element". That sentence is wrong from today, and it is the sentence the buyer's
identity depends on.

**`googlePay` is `'never'` in `ExpressCheckout.tsx` today**, and the client has
asked for both wallets. The `buttonTheme` and `buttonType` maps carry only
`applePay` keys for the same reason.

**Which methods this environment offers is finally a question worth an
endpoint.** `PaymentMethods::offered()` has existed with no production caller
since `051c6a1`, kept over finding 10 of the phase B review on the argument that
the frontend cannot infer which buttons to draw. The argument was right and
abstract; it is now concrete, because whether the wallet button exists at all
depends on whether this environment configured Stripe, and the panel now draws
one button or two on that answer.

**`return_url` for `stripe.confirmPayment` is the frontend's own page**, and it
builds it. This is not the open redirect 0002 guarded against: nothing
caller-supplied reaches this application, and with `payment_method_types:
['card']` the only redirect in play is 3DS returning to where it started. The
rule 0002 wrote — *return addresses are ours, never the caller's* — is about
addresses **we** send a browser to, and there are none on this road.

## Registering the domain stops being belt-and-braces

`theworldtarot.com` must be a registered Stripe payment method domain, in test
and in live. This was already item-shaped in section 10 of `API_CONTRACT.md` for
the hosted page. **For an element mounted on our own origin it is mandatory**,
and it fails by the button silently not appearing rather than by erroring —
which is indistinguishable from a device with no wallet, and therefore
indistinguishable from working correctly.
