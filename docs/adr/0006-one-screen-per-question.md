# One screen per question, and the orders table stops being a work queue

> **Decided 2 September 2026**, planning the gifting epic (#54). It extends
> `0004-a-reading-is-a-row-of-its-own.md`, which said Jennifer's working list
> changes from orders to readings, called that "the real cost of this decision",
> and then left the panel itself undesigned.
>
> Nothing in the panel has a user yet. That is why this is a design rather than
> a migration: there is no habit to preserve and no screen anybody has learned,
> so the only constraint is what the work actually is.

## What one screen was doing

`OrderResource` answers three questions at once today, and until gifting they
were the same question asked three ways.

- **What money came in.** The total, the currency, the status badge, and the
  hand-settlement action
- **What work is owed.** `What they asked`, wrapped into a table cell, and
  `Mark as fulfilled`
- **What is still outstanding.** The navigation badge, which counts
  `awaitingFulfillment`

A gift bought in September and redeemed in December makes those three answers
disagree for three months. The money came in. No work is owed. Something is very
much outstanding, and it is not a reading. **One screen cannot say that**, for
the same reason one row could not, and ADR 0004 already argued the row half.

## The decision

**Three resources, and each one answers exactly one question.**

| Screen | Answers | A row is |
|---|---|---|
| Orders | what money came in | a purchase |
| Readings | what work is owed, and what has gone | an asking |
| Gifts | what obligation is outstanding | a gifted line, until it is spent |

No screen answers two, and no number on one screen is derived from another
screen's table.

## Orders becomes what it already was

The question column and `Mark as fulfilled` leave. What stays is money: who
bought what, for how much, paid or not, and `Mark as paid`, which is the road
`MarkOrderPaid` reaches by hand.

`fulfilled_at` stays as a column and stops being something anybody sets. An
order is delivered when everything it promised has been, which is what
`MarkOrderFulfilled`'s docblock has said since it was written; what changes is
that the fact now follows from readings being delivered rather than from a
button on this screen.

**And the clause that never gets written.** `scopeAwaitingFulfillment` is `paid`
and not `fulfilled_at`. Left as the work queue it would have needed a clause
about gifts, and so would `needsAPerson`, and the two would have to agree
forever by hand — `MarkOrderPaid` already carries two such pairs and says both
times why answering once is what keeps them true. Moving the queue is what
means nobody has to remember a third.

## Readings is the working screen, and it is the first one with a detail page

**Oldest ask first**, which inverts Orders deliberately. Orders is sorted newest
first because the thing she opens it to find is whatever just came in. This
screen answers the opposite question — what has been kept waiting longest — and
sorting it the same way would bury exactly the row that matters.

**A detail page, which no resource in this panel has today.** A question runs to
2000 characters, and a gift adds a signature and the buyer's message on top of
it. That stopped fitting a wrapped table cell. The list carries due, reading,
querent, a gift marker and the question's first line; the page carries the whole
of it.

**The navigation badge moves here**, because readings waiting is the number she
actually needs and orders paid is not.

### Due dates, computed and never stored

`ProductKey::deliveryWindow()` says in its own docblock that nothing models a
turnaround, no job measures one, and nothing tells Jennifer when one has been
missed. `readings.asked_at` being NOT NULL is what makes the sum possible for
the first time: due is `asked_at` plus the window, and the screen shows it
relatively — "due in 6 hours", "3 hours overdue" — colours it, and sorts by it.

**Derived at read time.** A stored `due_at` is a second writer for a value that
already has one, and it goes stale the day a window changes. That is the fault
`MarkOrderFulfilled` exists to prevent for `fulfilled_at`, and it is not worth
re-introducing one screen along.

**No job, no alert, no email.** The screen is where she looks. An escalation
system for a business with one person writing the readings is a thing to want
later, and building it now would mean a promise nobody has yet failed to keep
generating mail about itself.

This is the first time the sentence a customer reads in their inbox is visible
to the person who has to keep it.

### Delivered is per reading, and the order follows

She delivers a reading, not an order. Her action stamps `readings.delivered_at`;
the order becomes fulfilled when everything it owes has gone — every reading
delivered, every gift redeemed and delivered.

`MarkOrderFulfilled` is untouched and gains a caller. It owns what being
delivered is recorded as, and it is asked after each reading rather than pressed
directly. ADR 0004 noticed this generalises for free; this is where it lands.

## Gifts is a liability screen, and it exists because ADR 0005 refused expiry

`0005-gift-codes-do-not-expire.md` accepts an indefinite obligation and says the
cost of accepting it is that somebody has to be able to see it: *"otherwise a
recipient whose address bounced is invisible — the money is taken, the code
exists, and nobody is waiting for anything they know about."* This is that
screen, and it is why it ships in the first release rather than after.

Its own resource rather than a tab on Orders, defaulting to outstanding. The row
is code, reading, sent-to, signature, bought-on, and **how long it has been
outstanding** — the column that turns a liability into something anyone can act
on. **Resend** is the row action.

**Not editable, and not because editing is hard.** Changing a gift's recipient
address is not what it looks like. The original mail is in an inbox and the code
in it still works, so "changing" the address hands a second person a live bearer
credential rather than moving one. If that case has to be served it is *revoke
and reissue*, which is a different feature with a different name.

**The badge here is neutral, not warning.** An unredeemed gift is not a task.
A badge that is permanently orange because three gifts are legitimately
outstanding teaches somebody to stop reading badges, and the one it costs them
is the readings badge, which does mean something.

## What this refuses, for now

**The PDF does not go through the panel.** Uploading the written reading and
letting the system send it is the right end state — one action instead of two,
the querent's address already in the row, and no chance of pasting the wrong one
into a mail client, which matters more for a gift where she has never seen that
address before. It is refused here because it means file storage, a mailable
with an attachment, a delivery-failure path and a new meaning for
`Fulfillment::ManualPdf`, and none of that is gifting. **`Mark delivered` is
built as the action that upload later hangs off**, rather than as a button that
gets replaced.

**No hand-issued gift codes.** The superseded `docs/plans/gifting-plan.md`
wanted Jennifer able to issue one as an apology. A hand-issued gift has no
purchase behind it, and `gifts.order_line_id` is NOT NULL, unique and
restricted by ADR 0004. Building it means deciding whether a gift can exist
without money, which is a larger question than the one it was reached for.

## Consequences

- `OrderResource` loses a column, an action and its badge, and reads honestly
  for the first time since gifting was designed
- A `ReadingsResource` with a list and a detail page, which is new ground in
  this panel — everything else here is index-only
- A `GiftResource`, read-only with one action
- The 24 and 48 hour windows become visible internally. Nobody has been measured
  against them before, and the first week of that screen is likely to be the
  first honest information anyone has about whether those numbers are real
