# Gifting a reading

> ## Superseded, 2 September 2026. Do not build from this document.
>
> **Drafted 25 August 2026 and overtaken by the planning of #54.** It is kept
> rather than deleted because it is the only record of several decisions nothing
> else carries, and because the shape it argues for is one somebody will
> otherwise re-derive.
>
> **What replaces it**, and these are the documents to build from:
>
> | Document | Carries |
> |---|---|
> | `docs/adr/0004-a-reading-is-a-row-of-its-own.md` | The `readings`/`gifts` tables, why not a second order, what leaves `MarkOrderPaid` |
> | `docs/adr/0005-gift-codes-do-not-expire.md` | No expiry, and why no speculative `expires_at` |
> | `docs/adr/0006-one-screen-per-question.md` | Orders/Readings/Gifts, and the panel design |
> | frontend `docs/adr/0003-redemption-is-a-page-of-its-own.md` | `/redeem/`, presentation-minus-commerce, lookup vs redeem |
>
> **What in here is now wrong**, named so no sentence below is read as current:
>
> - **Redemption is not a payment method.** `GiftCodeRedemption implements
>   PaymentGateway` is withdrawn, and so is `gift_code` as a method, before it
>   ever acquired a caller. Redemption collects nothing, so there is no payment
>   for a gateway to make. ADR 0004 lists the four places that promised it
> - **There is no `gift_codes` table**, and no `redeemed_order_id` or
>   `redeemed_by_customer_id`. Redemption mints a **reading**, not a second
>   order, and a recipient gets no account
> - **Redemption does not happen on the reading's page.** It is `/redeem/`, one
>   page for every reading, reached from a link in the recipient's mail
> - **Jennifer does learn a reading came from a gift.** "She never learns it was
>   a gift" was written when redemption minted an order indistinguishable from
>   any other. `readings.gift_id` means the system knows, and hiding it costs
>   her context she can use
> - **`MarkOrderPaid` gains no exception.** The moment a reading starts existing
>   leaves it entirely, into `AskForReading`, which both settlement and
>   redemption reach
> - **One-card is not giftable**, decided 2 September 2026. `isGiftable()` is a
>   property of a product, alongside `allowsQuestion()`
>
> **What in here survives, and is decided:**
>
> - **A gift is a flag on the order line, not a product.** The whole argument
>   under "This amends `CLAUDE.md` section 8a" stands, including why four gift
>   `ProductKey` cases and four duplicated price rows were refused
> - **A code names a reading, not an amount**, so it is worth the right price in
>   every currency
> - **A code for a withdrawn product still redeems.** The person paid, and
>   `order_lines.product_id` is `restrictOnDelete` precisely so a purchase
>   cannot be orphaned by tidying. Redemption reads the product by id and
>   ignores `is_published`, which is the one place in the system that does
> - **The pass is not giftable**, and the rule is enforced rather than trusted
> - **Scheduled delivery is not built**, and the reason has not changed:
>   `CLAUDE.md` caps managed queue delays at 15 minutes, so it needs a due
>   column and a scheduled sweep, and the platform's scheduler tick is still
>   carried as unverified
> - **VAT does not arise while Stripe Tax is off**, and returns as an
>   accountant's question the day Jennifer registers
>
> **On the nine references below to "`CLAUDE.md` section 8a".** That section no
> longer exists, and nothing is wrong: section 8 of `CLAUDE.md` says an open
> item that gets answered moves to `docs/CHECKPOINT.md` and leaves the list, and
> that is what happened to it. **Its content is the gifting section of
> `docs/CHECKPOINT.md`**, which holds the same cross-border reasoning and the
> same gifting-is-a-flag reversal. The references are left as they were written,
> because this document is the record of what was argued on 25 August 2026
> rather than a live instruction.


**Drafted 25 August 2026. Nothing is built.** A milestone of its own, after milestone 5 phase B and **before launch**, because the client confirmed on 25 August that gifting is required at launch rather than desirable.

It was carved out of phase B rather than dropped from it. `docs/plans/milestone-5-phase-b-plan.md` records why: **redemption is a path where an order becomes paid with no money involved**, and that should be written against a payment flow already proved rather than alongside one being built.

---

## Goal

Somebody buys a reading for somebody else. The recipient gets a code by email, enters it on that reading's page as a payment method, asks their own question, and the reading is placed. Jennifer is told exactly as she is for any purchase and never learns that a gift was involved.

## This amends `CLAUDE.md` section 8a

Section 8a currently says: *"Gifting a reading is a product whose fulfillment mints a code for that reading and emails it to the recipient."*

**That sentence is wrong and this plan replaces it.** Everything else in 8a survives.

The button in Jennifer's design says **GIFT A READING and it lives on the Month Ahead page, under the $75**. The customer is not buying a thing called a gift. They are buying Month Ahead, for somebody else. Making that a product means:

- **Four new `ProductKey` cases**, and `ProductKey` **is also the URL** by an explicit decision from 28 July 2026. So four addresses for pages that do not exist in the design
- **Four rows duplicating three prices each.** Jennifer raises Month Ahead to $85, forgets its gift twin, and the gift is quietly cheaper forever, with nothing in the schema preventing it

**A price that must be kept equal in two rows by discipline is a thing this project has already refused twice**, once when the polymorphic `prices` table became three `NOT NULL` columns, and once when a stored readiness flag became a calculated one. Both times for the same reason: a value maintained by hand in two places is wrong the first moment somebody is busy.

**So a gift is a flag on the order line.** Same product, same price, one row, one URL.

What 8a already said and this keeps, word for word in intent:

- **A gift code names a product.** Not an amount, not a percentage. All of 8a's reasoning about cross-border gifting, price drift, rounding and forfeiture stands and is the reason this shape works
- **One code covers one line, at quantity one.** Anything else in a basket is paid for normally
- **Recipient, message and delivery date are captured per line at checkout, the way `question` already is.** 8a item 4 said this, and it is what a line flag means

What it costs: `MarkOrderPaid`'s rule that **fulfillment is driven by the product** gains exactly one exception. A line with a recipient on it mints a code instead of doing what its product says. One branch, in one place, with a comment naming this document.

## Decided

### The code is a bearer instrument

Whoever holds it can spend it. They give their own email at redemption, which we need for delivery anyway, and phase A's guest path turns that into an unclaimed account exactly as a normal purchase does.

The alternative, binding redemption to the address the gift was sent to, was declined. **Its failure mode is somebody who was given a present and cannot open it**, because they forwarded it to a partner or read mail at a different address, and that is a support conversation about a gift. Bearer's failure mode requires somebody to have read the recipient's mailbox already.

### Delivered immediately, and it does not expire

The gift email goes out the moment payment lands, which is what a button on a product page implies.

**Scheduled delivery is deliberately not built.** "Send it on her birthday" cannot be a delayed job, because managed queue delays cannot exceed fifteen minutes per `CLAUDE.md` section 2, so it needs a due date column and a scheduled sweep. **And the platform's scheduler tick is still unverified**, carried as unknown since M4. Phase B step 5 measures it. Building scheduled delivery before that measurement exists is building on an assumption this project has already been bitten by.

**No expiry**, matching the Viewing Room pass, which never expires. A code that stops working is a refund conversation about a present, and there are no refunds.

### A code for a withdrawn product still redeems

Section 8a's first open question: what happens when Jennifer unpublishes or retires the gifted reading before somebody redeems.

**It still works.** The person paid for it. Refusing punishes the recipient for a decision made after the money changed hands, and the catalogue already keeps sold products alive: `order_lines.product_id` is `restrictOnDelete` and products soft delete, precisely so a purchase can never be orphaned by tidying.

The consequence, in writing: **redemption reads the product by id and ignores `is_published`**, which is the one place in the system that does. It gets a comment saying so.

### Redemption is a payment method, not a discount

**This is the structural decision and it came from the client on 25 August.** The recipient does not receive a discount that zeroes a line. They receive **a fourth way to pay**, beside Apple Pay, Google Pay and card, on the same page and in the same list.

So `GiftCodeRedemption implements PaymentGateway`. It validates the code, consumes it under a row lock, and returns `PaymentInstruction::nothingToPay()`. `MarkOrderPaid` then fires through the same seam Stripe fires through.

**This retires the worst thing in the original phase B draft.** That draft called the zero-total path *"a way to become paid without money, and a bug there is free readings for anybody who finds it"*, because it went around the gateway. As a payment method it goes **through** the gateway, which means it inherits the row lock, the single transition, the transaction and the redelivery guarantee that `MarkOrderPaid` has had since phase A. The dangerous case stops being a special case.

It works because phase B step 1 makes the gateway a choice per payment rather than a binding per environment. `gift_code` is simply another method the environment offers.

### VAT does not arise, for now

Section 8a's question 6 asked whether a voucher naming a specific product is taxed when sold or when redeemed, and said to confirm with an accountant.

**Stripe Tax is off as of 25 August 2026**, so nothing computes tax anywhere and the question does not arise. **It returns the day Jennifer registers for VAT**, and it returns as an accountant's question rather than a developer's. Recorded here so it is not mistaken for answered.

---

## The flow

**Buying one**

1. Month Ahead page. The buyer taps **GIFT A READING** and enters a recipient email and an optional message
2. They pay with the same wallet or card buttons, at the same price
3. On payment, the line mints a code naming Month Ahead and emails the recipient
4. The buyer's `OrderPaid` receipt says the gift was sent, and to whom
5. **Jennifer is not told**, because nothing is owed to anybody yet

**Spending one**

1. The recipient opens the Month Ahead page, chooses **Redeem a code** as their payment method, and enters it
2. They type their own question and their own email, exactly as any buyer does
3. The order is placed at the captured price. `GiftCodeRedemption` consumes the code and settles it
4. **Jennifer gets `ReadingToWrite` exactly as she does for a paid order**, and never learns it was a gift
5. The recipient gets `OrderPaid` and, if their account is unclaimed, `SetPasswordLink`

---

## The steps

### 1. Gift fields on the line, and the `gift_codes` table

**Build.** `gift_recipient_email` and `gift_message`, both nullable, on `order_lines`, beside the existing `question`. A `gift_codes` table: `code` unique, `product_id` restricted, `issued_to_email`, `order_line_id` naming the line that bought it, and `redeemed_at`, `redeemed_by_customer_id` and `redeemed_order_id` all nullable.

`StoreOrderRequest` accepts a recipient only on a line whose product is a **reading**. The pass is not giftable and the rule says so rather than relying on nobody trying.

**Tests.** A gift line validates. A gift line naming the pass is refused. **Uniqueness of `code` is enforced by the database, proved by inserting a duplicate and catching it**, not by a check-then-insert that a second request can slip between.

**Could go wrong.** Code generation that is merely random-looking. It needs to come from a cryptographic source and be long enough that guessing is pointless, and the test should assert the source rather than the appearance.

### 2. Minting and sending

**Build.** `MarkOrderPaid`'s fulfillment loop gains its one exception: a line carrying a recipient mints a code and queues the gift mail, instead of doing what its product's `fulfillment` says. An order holding only gift lines is **fulfilled immediately**, since nothing is owed to Jennifer, exactly as an order holding only the pass already is.

A new mail from `orders@`, carrying the code, the reading's name and the buyer's message.

**Tests.** A gift order does not appear on Jennifer's list and does not send `ReadingToWrite`. **A redelivered payment does not mint a second code**, which it inherits from `MarkOrderPaid` refusing a second transition, asserted directly rather than assumed. An order holding a gift and a normal reading still tells Jennifer about the reading. The buyer's receipt names the recipient.

**Could go wrong.** Mail queued inside a transaction that then rolls back has still been sent, and nothing unsends it. The mint is inside the transaction and the mail is queued after commit, which is the rule phase A already follows and the reason it is written down.

### 3. `GiftCodeRedemption` as a payment method

**Build.** A `PaymentGateway` implementation. It locks the code row, refuses one already redeemed, refuses one whose product is not the single line in the basket at quantity one, stamps the redemption, and returns nothing to pay. Registered as the `gift_code` method from phase B step 1.

**Tests.** A valid code settles the order with **no Stripe call at all**, asserted rather than assumed. A code already redeemed is refused. A code for a product not in the basket is refused, with the field named. A code for an **unpublished** product succeeds, which is the withdrawn-product decision, pinned so nobody helpfully adds a published check later. **Two requests racing for one code: exactly one wins**, tested against a real database with real locks rather than in the abstract.

**Could go wrong.** This is the step that turns a code into a paid order, so it is where a mistake means free readings. The lock is the whole defence and the race test is the only thing that proves it exists.

### 4. Codes in the panel

**Build.** A read-only Filament resource matching the Orders screen: which code, which reading, who it went to, whether it has been spent and when. Jennifer can also **issue one by hand**, which is how she says sorry to somebody.

**Tests.** The resource shows state and lets no money be edited. A hand-issued code redeems exactly like a bought one.

### 5. The staging walk

**Build.** Extend `tests/Staging`: buy a gift, receive the code, redeem it in a second session as a different person, and assert Jennifer was told once, at the right moment, about the right thing.

**Could go wrong.** The usual. A skipped test reads as a passing one, so count the skips and know which are new.

---

## Open, and needing answers before this is built

1. **What the buyer is told, and how much.** Their receipt says the gift was sent. It should probably not repeat the code back to them, since a receipt is forwardable and the code is a bearer instrument
2. **What happens when the recipient's address bounces.** The buyer typed it and may have typed it wrong. Nothing here detects a bounce today, and the honest answer may be that Jennifer reissues by hand from the panel, which step 4 already gives her
3. **Whether a gift can be bought alongside a normal purchase in one order.** Nothing forbids it and nothing needs it. Say which
4. **Scheduled delivery**, deferred here, returns as its own piece of work once phase B step 5 has established whether the scheduler ticks every minute
