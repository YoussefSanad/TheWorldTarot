# The purchase journeys, end to end

Written 22 August 2026, while planning M5 phase B. **The one place the whole path is described**, from a person deciding to buy to the thing being in their hands.

It does not restate what is already written. `docs/plans/milestone-7-journeys.md`, written 12 August, is the authority on how the readings are fulfilled and on the three states a customer can be in when they buy. **Read that one first.** This document adds the two halves it does not cover:

- **The payment leg**, which that document compresses into the single word "Pays". Phase B is what turns that word into a system
- **The Viewing Room pass**, which it does not describe at all, and which turns out to be the journey with the sharpest problem in it

## The three products, in one table

| Product | Delivered by | Where it lands | Needs an account |
|---|---|---|---|
| One Card Experience | The system, automatically | **A page on the website**, a film and a written reading | **No.** Reachable by token |
| Three Card, Month Ahead, In Depth | Jennifer, by hand | **Her own email**, outside this system | No |
| Viewing Room Pass | The system, instantly | **The Viewing Room**, every film in the library | **Yes, unavoidably.** See journey 3 |

---

## The payment leg, shared by all three

Everything above the line "an order becomes paid" is identical whatever was bought. `MarkOrderPaid` is that line, and nothing below it knows how the money arrived.

### What the customer does

1. Chooses a product, priced in their own currency, and types a question if it is a reading
2. Gives an email address. **No account is required at any point**, per the contract: demanding one before somebody can buy costs sales at the worst possible moment
3. Is sent to Stripe's hosted checkout and pays there
4. Comes back to the website

### What the system does

| Moment | What happens |
|---|---|
| Order placed | `PlaceOrder` writes the order and its lines in one transaction, prices captured as they are now. **Nothing is emailed.** The order is `pending` |
| The gateway begins | `StripeCheckout::begin()` creates a session from the order's own captured amounts and returns its URL. The customer goes there |
| They pay | Stripe collects. Nothing in this system knows yet |
| The webhook | `checkout.session.completed` arrives, its signature is verified, and `MarkOrderPaid` runs. **This is the moment**, and it is the same moment a person clicking "Mark as paid" in the panel raises |
| Fulfillment | Driven by the product's `fulfillment` value, so each journey below diverges here and only here |

### The gap that every journey has to survive

**The customer arrives back before the webhook does.** They are returned by their own browser the instant Stripe is done, and the webhook travels separately over the internet to a server that may be asleep. Sometimes it is the other way round. Neither order is guaranteed and both are normal.

So **the page they land on cannot assume the order is paid.** It has to be able to say "your payment went through and we are just catching up", and then become the real thing without a reload. `milestone-7-journeys.md` already anticipated exactly this with an `awaiting_payment` state, for the One Card Reading.

**What this plan adds is that the problem is not the One Card Reading's.** It belongs to every product, including the two that document treats as needing nothing new, so the endpoint that answers it belongs to phase B rather than M7.

### The order that costs nothing

A gift code covering the whole order means there is nothing to collect. **That order never goes to Stripe at all**: it becomes paid immediately through the same action, and the customer lands on a finished thing rather than returning from anywhere. Every journey below has this as a shortcut through its middle, and it is the only path where a person becomes a customer without a payment existing.

---

## Journey 3: the Viewing Room Pass

The journey the earlier document does not cover, and the one with a real problem in it.

### What the customer does

1. Sees the Library, twenty two cards, locked, with an invitation to buy the pass
2. Buys it. No question to type, since a pass has nothing to ask about
3. Pays at Stripe and comes back
4. **Signs in, or sets a password if they have never had an account**
5. Watches any film in the library, whenever they like, for as long as the pass lasts

### What the system does

| Moment | What happens |
|---|---|
| Order placed | As above. `viewing-room-pass` is `type: pass` and `fulfillment: access_grant` |
| Payment lands | `MarkOrderPaid` calls `grantViewingRoomAccess()` **inside the transaction**, so a half failure cannot leave somebody holding a pass for an order that never went paid. Granting twice keeps the first date, so a redelivered webhook cannot rewrite when they got in |
| Fulfilled | **Immediately, and by itself.** The pass is delivered in the same instant the money lands, so the order never appears on Jennifer's list. `needsAPerson` already gets this right |
| Emails | ~~`OrderPaid` to the customer. A set-password link too, if the account has never been claimed~~ **Reversed 27 October 2026 by #108:** every buyer receives the `OrderPaid` receipt; an unclaimed pass buyer with no live claim link gets the 60-minute set-password link embedded in it. No separate invitation is sent |
| Watching | Every film, every time, is checked at the moment of asking. `has_viewing_room_access` on `me` only decides which door to draw |

### The problem, and it is structural rather than a bug

**A pass is a property of an account. A reading is not.**

The One Card Reading is reachable by a token in a link, so a guest who never sets a password still gets everything they paid for. The pass cannot work that way. Access is checked against an authenticated customer, and it has to be, because a token in a URL that opened the whole library forever would be a pass anybody could forward.

So **the guest who buys a pass has bought something they cannot use until they set a password.** They pay, they come back, and the only thing standing between them and the library is an email they have not opened yet. That is the worst possible moment to introduce a step, and it is the one journey of the three where it happens.

Three ways to answer it, and this needs a decision:

- **Lean on the link that already exists.** ~~The set-password mail is already sent by `MarkOrderPaid` for an unclaimed account.~~ **Since #108 (27 October 2026), the link is embedded in the pass receipt** for an unclaimed buyer with no live claim link. The return page detects a pass in the order, says plainly that the library is waiting behind a password, and offers to resend. **Cheapest, and it is the current behaviour plus wording**
- **Ask for a password during checkout when the basket holds a pass.** Removes the gap entirely and puts friction before the money instead of after, which is the trade this whole design has avoided everywhere else
- **Sign them in automatically on return**, by having the payment path mint a session for the customer the order belongs to. **Smoothest and the most dangerous**, since it means a completed checkout is a way to become signed in as whoever owns that email address. It would need the session bound to the checkout session and short lived, and it is still a login without a credential

**Recommendation: the first.** It is nearly free, it changes no security property, and the friction it leaves is one email rather than a wall. The second is worth revisiting only if real customers get stuck.

---

## The mixed order

Somebody buys a One Card Reading, a Three Card Reading and a pass in one basket. One order, one payment, three fulfillments:

- The pass is granted at the instant of payment and needs nobody
- The One Card draws and writes itself, and appears on their page
- The Three Card waits for Jennifer, and **the order is not fulfilled until she has done her half**, because half delivered is not delivered

`MarkOrderPaid` already handles all three correctly. The thing that does not handle it is **the confirmation email**, which says one thing about the whole order. `milestone-7-journeys.md` flags this for the One Card Reading and the pass makes it worse: an email telling somebody to allow a little time, about an order containing a pass they could be using right now, is wrong in the direction that loses the sale.

---

## What these journeys need that does not exist

1. **`GET /api/v1/orders/{token}`, and a token on `orders`.** Planned in M7 for the reading page. **It is needed by phase B for all three products**, because every journey has the return-before-webhook gap and a guest has no other way to see their own order. There is no such endpoint today and no token column
2. **A success URL and a cancel URL from the frontend partner.** Already listed as a phase B dependency. The recommendation is one path carrying the order token, `/{locale}/order/{token}`, with the frontend deciding what to render from the order's contents. That keeps the API from having to know their page structure, which is hard rule 1
3. **The confirmation email has to speak per line**, not per order
4. **A decision on the pass and the unclaimed account**, above
5. **`MAIL_FULFILLMENT_RECIPIENT` still has nowhere to land.** Carried since 12 August. Jennifer's notification for every hand written reading goes to an address that can send and cannot receive, and the send reports success

## Open questions

- ~~**How long does the pass last?**~~ **Answered 22 August 2026: forever.** What the code already did is now what it is meant to do. It also means the pass is the one thing sold here that can never be sold to the same person twice, so buying it while already holding it is a case worth refusing rather than charging for
- **Does a refund revoke it?** Question 6 of the phase B plan. The recommendation there is yes
- **What happens to the pass when a film is removed from the library?** They paid for access to a library that got smaller. Probably nothing to build, worth knowing the answer before somebody asks
