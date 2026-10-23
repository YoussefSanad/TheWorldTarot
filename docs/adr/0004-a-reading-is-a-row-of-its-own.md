# A reading is a row of its own

> **Decided 1 September 2026**, planning the gifting epic (#54). It supersedes
> the gift design of 25 August 2026 recorded in `API_CONTRACT.md` section
> "Gift codes", and it withdraws `gift_code` as a payment method before that
> method ever acquires a caller.
>
> **Amended 23 October 2026.** Where this record says `nothing_to_pay` keeps "a
> hand-settled order" as a caller, hand settlement was removed in
> `design-review-fixes` step 3. The remaining caller is an order that is already
> paid. The text below is left as the record.
>
> The client's proposed flow (`PROPOSED_GIFT_FLOW_08_31_26.pdf`) is a walkthrough
> of what a purchaser and a recipient should experience. It is taken as that.
> Where it describes storage — a second order carrying a payment status of
> "Gift, Paid" — this decides otherwise, for reasons that are ours and not
> visible from a flow diagram.

## What gifting breaks

**"The money arrived" and "somebody asked for a reading" have only ever happened
at once here.** A buyer types a question, pays, and `MarkOrderPaid` does all of
it in one transition: it settles the order, decides whether a person is needed,
and mails Jennifer the question she cannot work without.

A gift bought in September and redeemed in December pulls those apart by three
months. Every part of the current shape says the wrong thing in that gap:

- `Order::scopeAwaitingFulfillment` is `paid` and not `fulfilled_at`, so the
  gift lands on Jennifer's working list the moment the card clears — **a reading
  nobody has asked a question of, which she cannot write and cannot clear**
- `MarkOrderPaid::needsAPerson` is true, because a `three-card` line is
  `ManualPdf`, so `ReadingToWrite` goes out at purchase carrying whatever is in
  `lines[].question`. Today that is `giftNote`'s composed prose — the stopgap
  `lib/order-note.ts` was always documented as having an end date
- `orders.fulfilled_at` cannot distinguish "delivered" from "never claimed"

None of that is fixable inside one row, because one row cannot hold "paid, and
not yet asked".

## The decision

**A reading is a row. It exists from the moment somebody asks, and never
before.**

```
readings
  order_line_id   what was bought, at the price it was that day. Restricted
  gift_id         where it came from. Null on a self-purchase
  question        NOT NULL. A reading nobody asked is not a reading
  querent_email   where it goes. Captured, not joined
  querent_name    nullable, the way a customer's name is since #52
  asked_at        NOT NULL. The clock the 24-hour rush would run from
  delivered_at    nullable

gifts
  order_line_id   the line that was gifted. Unique, restricted
  code            unique, indexed. Not derived from anything about the gift
  recipient_email  message  signature
  redeemed_at     nullable. Null is an outstanding obligation
```

> **`question` is written NOT NULL above. ~~NOT NULL~~ struck 2 September 2026**,
> building B3 (#69), and the column landed nullable. The argument for it, "a
> reading nobody asked is not a reading", is about **when the row exists** and
> survives untouched: a reading is still created only by somebody asking. It was
> never an argument about the text, and read as one it refuses a purchase this
> system already accepts. `StoreOrderRequest` takes `lines.*.question` as
> `sometimes, nullable`, `Purchasability\Rules` only refuses a question on a
> product that does not allow one, and `mail.reading-to-write` has always
> carried a branch reading "No question was asked." NOT NULL would therefore
> have thrown at settlement, after the money was taken, on an order the API had
> already accepted. **Whether a reading should be buyable without a question is
> a separate question and is still open**, on #69: answering it means changing
> the contract and the website, not the column.

> **When a gift row comes into existence, added 3 September 2026** building B5
> (#71). This ADR sketches the `gifts` table and never says when a row appears,
> and B5 could not land the columns without answering it. **The row is written
> at placement and its `code` is minted at settlement**, so `code` is nullable
> and null means "the money has not arrived yet".
>
> The buyer types four things at checkout, before the money exists, and they
> have to survive until it does. That is the same problem `order_lines.question`
> has and it is answered the same way: the values live on a row written when
> they were typed. The code cannot travel with them, because a code minted at
> placement is a live bearer credential backed by a payment that may never
> arrive, one per abandoned checkout. A codeless row is inert: nothing can be
> looked up with it and nothing redeemed.
>
> The alternative was four more columns on `order_lines` and the whole row
> written at settlement, which is the same four values in two places for the
> life of the schema.
>
> **`gifts.locale` joined the table in the same unit** and is not in the sketch
> above. The recipient's mail is rendered from it and there is nothing else to
> render it from, since an order carries no language of its own. It is a
> property of the gift rather than of the buyer, because the person it is sent
> to never chose it.
>
> **`is_giftable` is a column on `products`, seeded from `ProductKey`**, rather
> than derived from the key at read time. It sits beside `allows_question`,
> which is structure in a column for the same reasons: code owns it, the seeder
> re-applies it every run, and neither is editable in the panel. The two are
> independent and `one-card` is what proves it, taking a question and refusing
> to be gifted.

Two moments create a reading and they are the same moment in different clothes:
settlement, for a line nobody gifted, and redemption, for a line somebody did.

**`querent_email` is captured rather than joined**, the discipline
`order_lines.product_key` and `unit_amount` already follow. A gift's querent may
have no `customers` row at all, and deliberately does not get one — see
"What this refuses" below.

## Why not a second order

The issue and the client's document both create an order at redemption, carrying
a payment status of "Gift, Paid". Three things are wrong with it, and the third
is the one that decides it.

**"Gift, Paid" is `Paid`.** `OrderStatus` is money-only by explicit design and
its own docblock argues why fulfillment is not a case in it. *How* an order was
paid is a different question from *whether* it was, and folding them together
means `->paid()`, `isPaid()`, the badge colours and every count of revenue learn
a fourth case whose real meaning is "paid, but do not count this money twice".

**A redemption order has no money in it.** `PlaceOrder` captures a price at the
moment of buying and never reads the catalogue again, because "an order that
recalculates itself reports a total nobody was ever charged". An order minted at
redemption is exactly that: priced from December's catalogue for a payment taken
in September, or priced at zero, and both are a purchase record for a thing
nobody bought.

**And it would not have fixed the problem.** The purchaser's order still holds a
`ManualPdf` line, so it still reaches Jennifer's list at settlement with nothing
to write. A second order does not stop the first one lying. Whatever shape was
chosen, something had to learn that a paid reading can be unasked — and once
something has, the second order has no remaining job.

## Why not the question on the gift row

The smaller version of this — no `readings` table, just `gifts`, with redemption
writing the question back onto `order_lines.question` — was seriously considered
and is close to right. It fails on one thing: **a gift that is never redeemed
still leaves a `ManualPdf` line sitting in `awaitingFulfillment`**, so the scope
Jennifer's whole day runs on grows a clause about gifts, and so does
`needsAPerson`, and the two must agree forever by hand. `MarkOrderPaid` already
has two such pairs and says both times why answering once is what keeps them
true.

With a `readings` row there is no clause: her queue is readings that have not
been delivered, and an unredeemed gift has no row in it. The condition stops
being a rule anybody has to remember and becomes a thing that does not exist.

## What moves, and what does not

**`order_lines.question` stays, and is not the question of record.** It is what
the buyer typed at checkout, and it has to live somewhere between placement and
settlement, because a reading may not exist before the money does — otherwise
every abandoned checkout puts unpaid work in Jennifer's world. Settlement copies
it onto the reading. That is capture, the same as `product_key`, not
duplication. It is `null` on a gift line: nothing composes prose into it any
more.

**The moment Jennifer is told comes out of `MarkOrderPaid`.** It lives in
`tell()` today, private to payment, and gifting proves it is not payment's. A new
action owns it — `AskForReading`, the one moment a reading starts existing —
and both settlement and redemption reach it, exactly as both hand-settlement and
the webhook reach `MarkOrderPaid` so that "fulfillment cannot come to differ
between them".

**`ReadingToWrite` becomes one mail per asking, not one per reading.** Its
docblock's reason survives the move and would be quietly lost without this
sentence: one message for everything opened in the same instant, "so Jennifer
gets a single thing to work from instead of three that arrive together and have
to be reassembled". A three-reading order still sends one mail. A redemption
sends its own, because it happened on its own day.

**`MarkOrderFulfilled` stays exactly as it is.** An order is delivered when
everything it promised has been, which is what its docblock already says about
an order holding a reading and a pass — "half delivered is not delivered". It
now generalises for free: an order holding an unredeemed gift is not delivered,
because it plainly is not.

## What is withdrawn

**`gift_code` never becomes a payment method.** Redemption collects nothing —
the order was paid at purchase — so there is no second order for a third method
to settle, and a method naming a payment that does not happen is worse than no
method. Four places promise it today, and each is corrected when this is built —
listed here so none is missed, because a contract still advertising a method
nobody will implement is how a partner builds a button for it:
`API_CONTRACT.md` sections 2, 9 and "Gift codes",
`PaymentMethodIndexResource`'s docblock, `PaymentInstructionType::NothingToPay`,
and `PaymentStatus::settled()`. `GET /payment-methods` keeps the two it has.

**A redemption never reaches `POST /orders/status`.** That endpoint reports a
payment, and there is no payment here. The recipient's confirmation is rendered
from the redemption's own answer.

`nothing_to_pay` keeps its other caller — a hand-settled order — so the case
stays. Only the sentence in each docblock predicting a gift is struck.

## What this refuses

**A recipient gets no account.** `Customer` exists for people who transact, and
an "unclaimed" one is defined as what a *purchase* creates. Minting one for
somebody who was given a present and has bought nothing would put rows in that
table that the word does not cover, and would put a claim link in front of
somebody who never asked for a password.

**A recipient gets no order.** Nothing was bought by them. The cost, taken
knowingly: the day there is an account area, "readings I have been given" is
answered by matching `readings.querent_email`, and not by a foreign key.

## Consequences

- A migration, and ~~a backfill: every paid order line holding a question
  becomes a reading, `asked_at` from `paid_at`, `delivered_at` from the order's
  `fulfilled_at`~~ **no backfill, struck 1 September 2026**. Nothing has
  launched: there is no live website and there are no customers, so the rows a
  backfill would have carried do not exist. The migration creates tables and
  nothing else, and every order in a deployed environment is test data
- **Jennifer's working list changes from orders to readings.** This is the real
  cost of this decision and it is not a schema cost. Her `OrdersTable` prints
  the question off `lines`; that column moves to a `ReadingsResource` and the
  orders table stops pretending to be a work queue
- `POST /api/v1/orders` grows a gift object on a line — recipient, message,
  signature — which is the column `lib/order-note.ts` has been waiting for
- M7's AI fulfillment gets a place to land it did not have: a writer is a
  property of a reading, and `Fulfillment::ManualPdf` becoming something else
  for `one-card` stops being a change to how orders work
