# The ticket cut, gifting epic (#54)

> **Drafted 2 September 2026** while planning #54, and moved into the repository
> the same day so that `gifting-build.md` has a scope reference that resolves.
> Until then it existed only in a temporary directory.
>
> **The sixteen units below are not filed as issues.** Filing them is step 0 of
> `gifting-build.md`; until that happens this file is the only place their scope
> exists, and a unit referred to as "B5" anywhere else means the B5 here.
>
> **Punctuation was normalised to hard rule 8 on the way in. No wording changed.**

Backend tickets go on `YoussefSanad/TheWorldTarot`, frontend on
`YoussefSanad/theworldtarot-frontend`. Every ticket body should open with the
required-reading table from #54 rather than restating decisions.

---

# Backend track

## B1. The ADRs enter version control

**Question.** `.gitignore` carried `/docs`, so ADRs 0004, 0005 and 0006 and
every plan in `docs/plans/` existed only in a working tree that concurrent
sessions share. Every ticket below cites them by path.

**Done when** `/docs` is out of `.gitignore`, the 40 files under it are
committed, and `docs/plans/gifting-plan.md`'s superseded header is in the same
commit, so nobody clones the plan without the notice that it is dead.

Note that the frontend repo already tracks its own `docs/`; this removes an
asymmetry rather than inventing a convention.

---

## B2. `API_CONTRACT.md` says what gifting actually is

**Question.** The contract still advertises `gift_code` as a payment method in
four places, and its "Gift codes" section describes a flow no part of which
survives. **A contract advertising a method nobody will implement is how a
partner builds a button for it**, and the partner here builds against this file.

**Strike, do not delete**, in the four places ADR 0004 enumerates: sections 2, 9
and "Gift codes", `PaymentMethodIndexResource`'s docblock,
`PaymentInstructionType::NothingToPay`, and `PaymentStatus::settled()`. The
document's own house style is strikethrough with a date, because a reversal a
reader can see beats one they cannot. `nothing_to_pay` **keeps its case**: a
hand-settled order still answers it; only the sentence predicting a gift goes.

**Replace wholesale**: the "Gift codes" section of 25 August. Nothing in it is
true, and striking it line by line leaves a section that is 80% strikethrough.

**Add**: the gift object on a line in `POST /api/v1/orders`
(`recipient_email`, `message`, `signature`, `locale`); `is_giftable` on
`/products` beside `allows_question`; and the two gift endpoints from B7.

**Done when** no sentence in the file predicts a gift payment method, and the
frontend can build B-track work from this file alone.

---

## B3. A reading is a row, and no gifting is in this ticket

**Question.** ADR 0004's model, landed on its own so that the shape is proved
before anything gift-shaped touches it.

**Build.** The `readings` table (`order_line_id` restricted, `gift_id` nullable,
`question` NOT NULL, `querent_email`, `querent_name` nullable, `asked_at` NOT
NULL, `delivered_at` nullable). A `Reading` model. **`AskForReading`**, which
creates the reading and tells Jennifer, the job that lives in
`MarkOrderPaid::tell()` today and is not payment's. `MarkOrderPaid` calls it at
settlement, copying `order_lines.question` onto the reading the way a line
already captures `product_key` and `unit_amount`.

**No `gifts` table, no code, no `gift_id` writer.** The column exists and stays
null.

**No backfill.** Nothing has launched, so the migration creates a table and
nothing else.

**Tests.** Every existing settlement test still passes unchanged, and that is the
point of the ticket. A settled reading order creates exactly one reading with
`asked_at` from the settlement. A redelivered payment creates one, not two.
`ReadingToWrite` is still one mail per order settled in the same instant.

**Could go wrong.** `ReadingToWrite` becoming one mail per reading. Its docblock
says why one message covers everything opened in the same instant: so Jennifer
gets a single thing to work from rather than three that arrive together and have
to be reassembled. That reason survives the move and would be quietly lost.

---

## B4. The panel splits into three screens

**Question.** ADR 0006. Blocked by B3.

**Build.** A `ReadingsResource`: list sorted **oldest ask first**, columns of
due / reading / querent / gift marker / the question's first line, plus **a
detail page**, the first in this panel, carrying the whole question, the
signature and the buyer's message. Due is computed at read time as `asked_at`
plus `ProductKey::deliveryWindow()`, shown relatively, coloured, sortable, and
**never stored**. `Mark delivered` stamps `readings.delivered_at` and then asks
`MarkOrderFulfilled` whether the order is now complete.

`OrderResource` loses the `What they asked` column, loses `Mark as fulfilled`,
and loses its navigation badge to Readings. It keeps money and `Mark as paid`.

**Tests.** An order with two readings is fulfilled only when both are delivered.
A reading delivered twice keeps its first date. The due column is right across a
window change, which is what proves it is derived.

**Could go wrong.** Building `Mark delivered` as a button that a PDF upload
later replaces, rather than as the action an upload hangs off. ADR 0006 refuses
the upload for this epic and says why; the shape of the action is the part that
has to survive it.

---

## B5. Gift storage, and which products may be gifted

**Question.** Blocked by B3 and B2.

**Build.** The `gifts` table (`order_line_id` unique and restricted, `code`
unique and indexed, `recipient_email`, `message`, `signature`, `locale`,
`redeemed_at` nullable). **`ProductKey::isGiftable()`** beside `allowsQuestion()`
is true for `three-card`, `month-ahead`, `in-depth`; false for `one-card`, the
pass and the rush. `is_giftable` on the product resource. `StoreOrderRequest`
accepts a gift object only on a giftable line, and **refuses rather than
ignores** one elsewhere. `order_lines.question` is null on a gift line: nothing
composes prose into it any more.

**Tests.** A gift on `one-card` is refused with the field named. A gift on the
pass is refused. Uniqueness of `code` is proved by **inserting a duplicate and
catching the database's refusal**, never by a check-then-insert a second request
can slip between.

---

## B6. The code is minted at settlement, and two people are mailed

**Question.** Blocked by B5. ADR 0004 never said when the gift row comes into
existence.

**At settlement, not at placement.** A code minted at placement is a live bearer
credential backed by a payment that may never arrive, one per abandoned
checkout. `MarkOrderPaid` is already the one seam both the webhook and a hand
settlement reach, already locked, already survives redelivery. Minting there
inherits all of it, and "what happens to an abandoned gift" stops being a
question.

**The code.** 12 characters of Crockford base32 (`0-9A-Z` less `I L O U`, ≈60
bits), from `random_int` with rejection sampling, **not `Str::random`**, whose
alphabet contains exactly the glyph pairs people mistype off a screen. Printed
`XXXX-XXXX-XXXX`. **Not derived from anything about the gift**: the client's
`3CARD10021` names its own value and can be counted to, and `Order::mintPayToken`
already argues that point for the token beside it.

**Two mails.** A new mailable to the recipient, subject naming the signature,
because an unsolicited mail carrying a code and a link from an unknown brand is
shaped exactly like phishing and **a named sender is the strongest signal it is
real**. Link, and the code printed underneath for typing. Rendered in
`gifts.locale`. And a **branch of `OrderPaid`** for the buyer, not a new
mailable: same event, same person, same receipt, with the recipient's address
where the delivery window goes. **The buyer never receives the code**, decided
2 September, so that address is the only thing they can check, and it is
prominent rather than a footnote.

**`ReadingToWrite` does not fire.** A gift tells Jennifer nothing until it is
redeemed. This is the trap this ticket exists to avoid reopening.

**Tests.** A gift order sends no `ReadingToWrite` and appears on no queue. A
redelivered `payment_intent.succeeded` mints one code, not two. An order holding
a gift and a self-purchase still tells Jennifer about the reading only. Mail is
queued after commit, never inside the transaction.

---

## B7. Looking a code up, and spending it

**Question.** Blocked by B5. ADR 0003 requires resolving a code **without
spending it**, or a visitor who opens the link and closes the tab has lost their
present.

**Two endpoints, code in the body both times.** `POST /api/v1/gifts/lookup` and
`POST /api/v1/gifts/redeem`. POST for a read is deliberate: it keeps a bearer
credential out of access logs and out of every proxy that records URLs, and
`POST /orders/status` already takes a credential in a body. Entry is normalised
before lookup: uppercased, non-alphanumerics stripped, `I`/`L`→`1`, `O`→`0`.

**Lookup** answers product key, the reading's name, and redeemed-or-not in plain
words. It cannot answer vaguely: "already redeemed" is a state the real recipient
has to be told about, so it cannot hide behind the answer given to an unknown
code. **The entropy in the code is what closes the oracle**, plus an IP throttle.

**Redeem** takes question, querent email and optional name. It locks the gift
row, refuses one already spent, stamps `redeemed_at`, and calls `AskForReading`
**inside the same transaction**, mail after commit. It returns the confirmation
directly and **never reaches `POST /orders/status`**, which reports a payment
that does not happen here.

**The querent is not necessarily the recipient.** `/redeem/` asks for an email
rather than inheriting `gifts.recipient_email`: a forwarded gift is enough to
part them, and inheriting silently delivers the reading to whoever forwarded it.

**A code for a withdrawn product still redeems.** Redemption reads the product
by id and ignores `is_published`, the one place in the system that does, and it
gets a comment saying so. The person paid.

**Tests.** **Two requests racing one code against a real database with real
locks: exactly one wins.** This is the only thing that proves the lock exists,
and this is the endpoint where a mistake means free readings. Lookup does not
spend. An unpublished product's code succeeds, pinned so nobody helpfully adds a
published check later. The throttle refuses.

---

## B8. The querent gets a mail of her own

**Question.** Blocked by B7. She has no account, no order, and no lookup, and the
confirmation ADR 0003 renders from the redemption's answer dies with the tab.

**Build.** A short mail on redemption: what she asked for, and **the delivery
promise**, which is the one surface allowed to make it. The buyer's receipt does
not (nothing is being delivered), the gift mail does not (nobody has asked yet),
and `/redeem/` states the window as a property of the reading rather than a
commitment. `asked_at` is the moment the clock starts, and this mail is the
durable record that it has.

---

## B9. The gifts screen

**Question.** ADR 0005 accepts an indefinite obligation and says the cost is
that somebody has to be able to see it. Blocked by B6.

**Build.** A read-only resource, third in the sidebar, defaulting to
outstanding. Row: code, reading, sent-to, signature, bought-on, **how long it
has been outstanding**. **Resend** as the row action. Badge in a neutral colour,
not warning. An unredeemed gift is not a task, and a permanently orange badge
teaches somebody to stop reading badges.

**No address editing**, and not because it is hard: the original mail is in an
inbox and its code still works, so changing the address hands a second person a
live credential rather than moving one. That case is *revoke and reissue*, which
is a different feature.

**No hand-issued codes.** `gifts.order_line_id` is NOT NULL.

---

## B10. The staging walk

**Build.** Extend `tests/Staging` beside `CardPaymentWalkTest` and
`WalletPaymentWalkTest`: buy a gift, receive the code, redeem it in a second
session as a different person, and assert Jennifer was told **once**, at the
right moment, about the right thing.

**Could go wrong.** A skipped test reads as a passing one. Count the skips and
know which are new.

---

# Frontend track

## F1. The presentation/commerce seam

**Needs nothing. Start on day one.**

**Question.** ADR 0003: `/redeem/` must mount what a reading *is* without what it
*costs*, and a reading page is one composition today. **One of four reading
pages exists.** Cutting the seam once, before three more are written against the
un-seamed shape, is the difference between a refactor and a rewrite, and it is
the argument for doing it inside this epic rather than after.

**Done when** `month-ahead` renders through the seam with no visual change, and
the presentation half mounts on its own.

---

## F2. The gift panel takes a signature and the address twice

**Needs nothing.**

**Build.** Two fields on `RecipientDetails`. **Signature first**, required, ~50
characters, labelled as who the gift is *from*, not "Your Name": an order may
have no name since #52, the card path collects identity on Stripe's page after
the order exists, and "Mum" is a truer answer than what is on the card.
**Confirm-email second**, because the buyer never receives the code and a typo'd
address is otherwise unrecoverable.

**The mismatch must go through `setCustomValidity`**, not raise its own error.
`orderFormAccepts` calls `reportValidity()` once and the wallet road depends on
that being the single refusal path. A parallel branch is how a buyer authorises
with their face for a gift the form had already rejected.

**The panel may not shift.** The section is sized to the question field's 607px
box on purpose. The message textarea drops from 3 rows to 2: it is the only
optional field on either side, and not shifting is worth more than a taller
optional box.

---

## F3. The confirmation screen stops promising a gift buyer a reading

**Needs nothing.** The session record already carries `gift`.

**Build.** A gift variant of `received` only: the gift has been sent, and to
whom. It does not name a reading as being on its way, because nobody's is. The
other six states are untouched; four of them say no money was taken, and a
screen that hedges about a payment while promising a reading is worse than
either half. `scripts/check-confirmation.mjs` holds one run per state, so this
is a new run.

---

## F4. The gift toggle is drawn from the product

**Blocked by B5.**

**Build.** `is_giftable` off `/products` decides whether the page draws
`GIFT A READING`, the way `allows_question` already decides the question field.
Hardcoding the list ships a toggle on `one-card` that 422s on submit.

---

## F5. `/redeem/`

**Blocked by B7. This is the join.**

**Build.** One page for every reading. The code arrives as a **query parameter**,
because a static export cannot pre-render a path segment per code. Manual entry
sits on the same page for somebody typing it off the mail, forgiving case and
separators.

**It looks the code up without spending it.** Spending happens on submit and
never on arrival: email scanners and link prefetchers follow links, and a gift
spent by a scanner is unrecoverable with no expiry and no refunds.

**It is a reading page with the commerce taken out, not a bare question box.**
The querent is the one person in this flow who never chose the reading they are
holding, so name, artwork, description and what-you-receive are exactly what
they most need. What goes is everything that sells. The form is
content-independent, the same fields for every reading, so a product with no
`ReadingPage` record falls back to the API's own name and description and
**still redeems**. Refusing to render is refusing to redeem.

**States**, in plain non-technical words: valid, already redeemed, unknown code.
**No expired state.** Gifts do not expire, and building the state means
building the concept.

---

## F6. Content records for the remaining giftable readings

**Not this epic; filed so the dependency is visible.** `three-card` and
`in-depth` need a `ReadingPage` record, copy and artwork from the client. Each
one landing lights up gifting for that reading with no gifting work at all.
