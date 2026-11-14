# The World Tarot, backend

The words this repo uses for things it would otherwise call three names.

**Deliberately not the whole glossary.** The domain words currently live in
`CLAUDE.md` and in `AGENT-BRIEF-architecture-tickets.md`, and copying them here
where none of the three would be authoritative is the drift this file exists
against. Moving them properly is its own piece of work. A term arrives here
when something has actually had to name it.

## Accounts

**Unclaimed**:
An account that has never had a password. It is what a purchase creates for
somebody who has never signed up, it is the only kind of account a claim link is
worth sending to, and it is what makes that link work exactly once. Asked
through `Customer::isUnclaimed()` and never by comparing the column, because
three modules used to spell out `password === null` separately and agreed only
because all three happened to write it the same way.

The negative is the named state on purpose: every caller asks about the
negative, so naming the positive would mean three call sites negate.

_Avoid_: passwordless, incomplete account, guest account (a guest is a buyer
with no session, which is a different thing and belongs to the frontend's
glossary), pending account

**Claim**:
The one moment an unclaimed account stops being unclaimed, owned by
`App\Actions\ClaimAccount` and by nothing else. A claim is not a reset: nothing
is being replaced, and no other device is signed out.

_Avoid_: activate, register (registering makes an account; claiming finishes one
that already exists), set up

**Claim link**:
The link in the paid-order mail inviting the buyer of an unclaimed account to
choose a first password. Minted by `MarkOrderPaid`, carried by
`App\Mail\SetPasswordLink`, and pointed at the website's `/set-password/`
because this application renders nothing a customer can use. It lasts an hour
and works exactly once.

**Since 11 November 2026, issue #122, the same link also reaches the
confirmation page**, through `POST /orders/status`, for fifteen minutes after
settlement and only for an account the order made. It is the receipt's own
token, kept by `App\Support\ClaimLinkHandover`, never a second one: the broker
keeps one per address, and a second link would kill the first.

_Avoid_: activation link, welcome link, invite, set password link (that is the
mailable's class name, not the thing)

**Entering after payment**:
A first-time Viewing Room pass buyer signed in by the payment itself, with no
password, through `POST /orders/enter` and `App\Actions\EnterAfterPayment`.
Decided in #126 on 14 November 2026, built as #128. Only for an account this
payment made (unclaimed, no other order), within fifteen minutes of settlement,
once, from the browser holding the pay token, and only as a cookie session. It
is bounded by `App\Support\ClaimLinkHandover`, the same rule that bounds the
claim link on the page, and it replaces that link: the page's hand-over is
retired by #128's second step. The receipt still carries the claim link for
later visits. **Somebody who typed another person's address is signed in to an
account in that person's name**, accepted in #126 because claiming or resetting
it signs that session out on its next request.

_Avoid_: auto login, magic sign in, guest session (a guest holds no session at
all), welcome link (there is no separate welcome email, decision 6 of #126)

## Readings

The words for a reading as a thing of its own are the **frontend's**
`CONTEXT.md`, which exists to fix what both repositories call the same thing:
**Reading**, **Ask**, **Querent**, **Gift**, **Gift code**, **Redeem**,
**Recipient**, **Gift signature**. The argument for all of them is
`docs/adr/0004-a-reading-is-a-row-of-its-own.md`. They are not restated here —
two glossaries and an ADR saying the same thing in three voices is the drift the
note at the top of this file is about.

One distinction is this repository's alone, because nothing on the wire can see
it:

**Question of record**:
`readings.question` — what the querent asked, and the only thing anybody writes
a reading from. **Not `order_lines.question`**, which is what a buyer typed at
checkout and exists because a question is typed before the money arrives and a
reading may not exist before it does. Settlement copies the one onto the other,
the way a line already captures `product_key` and `unit_amount` rather than
joining for them, and a gift line has nothing to copy.

_Avoid_: the question (ambiguous the moment both exist), the buyer's question
(true of the line, and wrong for every gift)

## The panel

Also this repository's alone, because nothing on the wire can see a panel.
Settled 2 September 2026 in `docs/adr/0006-one-screen-per-question.md`, which
carries the argument for three screens rather than one.

**Orders**, **Readings**, **Gifts**:
One screen per question. **Orders is money**, and a row is a purchase.
**Readings is work**, and a row is an asking. **Gifts is liability**, and a row
is a gifted line until it is spent. No screen answers two of them, and no number
on one is derived from another's table. `OrderResource` answered all three until
gifting, because until gifting they were the same question asked three ways.

_Avoid_: calling Orders the work queue (it stopped being one, and the navigation
badge moved to Readings), a Gifts tab on Orders (the liability is not filed under
the money)

**Due**:
`readings.asked_at` plus the product's delivery window, **computed at read time
and never stored**. A stored `due_at` is a second writer for a value that already
has one, and it goes stale the day a window changes, which is the fault
`MarkOrderFulfilled` exists to prevent for `fulfilled_at`. The screen shows it
relatively, colours it, and sorts by it oldest first. No job measures it and
nothing alerts on it: the screen is where she looks.

_Avoid_: deadline, SLA, `due_at` as a column, overdue as a stored flag

**Delivered**:
`readings.delivered_at`, stamped one reading at a time, because she delivers a
reading and not an order. An order becoming **fulfilled** follows from it, when
everything it owes has gone: every reading delivered, every gift redeemed and
delivered. `MarkOrderFulfilled` still owns what that is recorded as, and gains a
caller rather than a button.

_Avoid_: fulfilled for a single reading (that word is the order's, and
`MarkOrderFulfilled` owns it), sent, completed

