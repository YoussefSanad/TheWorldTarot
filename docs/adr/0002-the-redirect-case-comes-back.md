# The redirect case comes back, and `StripeCheckout` creates a Session rather than an intent

> **Decided 29 August 2026**, at the frontend's request. It reverses finding 10
> of the phase B review, which removed `PaymentInstruction::redirect()` on
> 26 August 2026 as a case with no caller that could never acquire one.
>
> **It has a caller now.** The frontend's ADR 0002 has the reasoning; this
> records what it means here.

## Why the removal was right and is now wrong

The removal said: checkout happens in our own page precisely because no Stripe
parameter can hand a wallet choice to a hosted page, so sending the customer
away is the design this one was chosen over.

Every clause of that is still true about **Stripe**. It is no longer true about
**the design**. The frontend cannot lay out a panel around a control whose
height depends on whether the visitor's device has a card in its wallet, and has
chosen one button of a known size over a wallet sheet that saves a tap.

## What changes here

**`StripeCheckout` creates a Checkout Session instead of a PaymentIntent**, and
answers `PaymentInstruction::redirect($session->url)`.

What does **not** change is more important than what does:

- **The amount is still `$order->total_amount`**, passed as `price_data` built
  from the stored order. **No Stripe Price catalogue.** Jennifer edits prices;
  a price held at Stripe is a second place they can be edited and a second
  number the payment can be made against
- **`refuseAnythingButAPendingOrder` is unchanged**, including the
  total-vs-lines guard that shouts
- **Settlement is unchanged.** `payment_intent.succeeded` still settles, keyed
  by `metadata.order_id`, which is now set through
  `payment_intent_data.metadata`. A Session in `payment` mode emits that event
  as well as `checkout.session.completed`, and the intent handler is the most
  safety-critical code here — it is proved against a captured signature vector
  and there is no reason to disturb it because the front of the funnel moved.
  **We do not subscribe to `checkout.session.completed`**
- **`stripe_payment_intent_id` is still written before the instruction goes
  out**, from `$session->payment_intent`
- **Calling `/pay` twice still mints a fresh Session.** A Session expires after
  24 hours and an order never does, so the retry shape this class already had is
  what keeps an abandoned checkout resumable. **The URL is never persisted**

`adaptive_pricing` is set to disabled explicitly. If Stripe converts, the
customer is charged an amount that is not the order total, and `ReconcileOrders`
and the total-vs-lines guard are then comparing numbers in two currencies. It may
be off for this account by default; setting it means it cannot be switched on
from a Dashboard nobody is watching.

## Two things the frontend now needs that it did not

**1. A guest may place an order without a name or an email.** Stripe's page
collects the buyer's email, and it collects it *after* the order exists. The
webhook fills identity from `session.customer_details` at settlement.

An order that is abandoned therefore stays `pending` holding no identity, which
is safe: placing an order sends no mail, so there is nothing that could be
addressed to nobody.

**2. A way to read a payment's status back.** `API_CONTRACT.md` has said since
25 August that there is no `GET /orders` and to say if the checkout confirmation
needs one. It does: `success_url` hands the browser a Checkout Session id, which
is opaque and cannot be resolved with a publishable key.

**`POST /api/v1/orders/status`, taking the pay token in the body.** Not a path
segment, and not a `GET`: the pay token is a credential and a path segment lands
in every access log between the browser and this application. It answers the
**PaymentIntent's status string**, retrieved server-side — not the order's
status. The frontend maps seven Stripe statuses onto four outcomes and that
mapping is already written and tested; returning `pending`/`paid` instead would
make every customer who arrives before the webhook read a screen hedging about a
payment that plainly succeeded.

## Return addresses are ours, never the caller's

`success_url` and `cancel_url` are built here, from a configured frontend
origin. **The frontend does not send URLs.** A caller-supplied `success_url` is
an open redirect available to anybody holding a pay token, and allowlisting the
origin to fix it leaves us building the address anyway.

It may send a **return path fragment** — a product key or a path — so a
cancelled checkout lands on the reading page it started from. Validated here,
and never concatenated raw.
