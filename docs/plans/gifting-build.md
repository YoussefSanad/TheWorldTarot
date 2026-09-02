# Building gifting

> **The orchestration for the gifting epic (#54).** It carries the order, the
> gate on each unit, and the one join between the two tracks. It carries no
> design and no per-unit scope, because both already have a home: the ADRs
> decide, and `gifting-tickets.md` scopes. A second copy of a decision is what
> left a withdrawn design sitting in `docs/CHECKPOINT.md` for a day, described
> there as settled and not to be re-litigated.
>
> **Drafted 2 September 2026 and not yet approved.** Section 0.2 wants a round
> on this before building, and hard rule 10 makes the approved version the thing
> that unblocks the first commit.

## Step 0. The sixteen units are not issues yet

`gifting-tickets.md` holds them as B1 to B10 and F1 to F6, and it is currently
the only place their scope exists. **File them before building**, backend on
`YoussefSanad/TheWorldTarot` and frontend on `YoussefSanad/theworldtarot-frontend`,
each body opening with the required reading below rather than restating it.

Filing is also what makes hard rule 15 possible: progress is written back to an
issue as it happens, and a unit with no issue has nowhere to write. #55 is the
map, and its "Decisions so far" table is the index of what is already settled.

**Done when** sixteen issues exist, each linked from #55, and #54 carries the
build order.

## Read before the first line of code

The design is decided and this epic does not reopen it.

| Read | For |
|---|---|
| `docs/adr/0004-a-reading-is-a-row-of-its-own.md` | The `readings` and `gifts` tables, why not a second order, what leaves `MarkOrderPaid`, what is withdrawn |
| `docs/adr/0005-gift-codes-do-not-expire.md` | No expiry, and the liability accepting it creates |
| `docs/adr/0006-one-screen-per-question.md` | Orders, Readings and Gifts, due computed at read time, delivered per reading |
| frontend `docs/adr/0003-redemption-is-a-page-of-its-own.md` | `/redeem/`, presentation minus commerce, lookup kept separate from redeem |
| `CONTEXT.md` here, and the frontend's | The vocabulary. Six units will otherwise each invent a word |
| `docs/CHECKPOINT.md` | What is true today, and which earlier decisions reversed |

`docs/plans/gifting-plan.md` is **superseded** and carries a header naming what
in it is dead. Read it for reasoning nothing else holds, not for shape.

## The graph

Two tracks. Three frontend units need nothing and start on day one; one unit is
where the tracks meet.

| Unit | Opens when | Track |
|---|---|---|
| B1 The ADRs enter version control | now | backend |
| B2 `API_CONTRACT.md` corrected | now, and before any code | backend |
| B3 A reading is a row | B1, B2 | backend |
| B4 The panel splits into three | B3 | backend |
| B5 Gift storage, and what is giftable | B3, B2 | backend |
| B6 Minting and mailing at settlement | B5 | backend |
| B7 Lookup and redeem | B5 | backend |
| B8 The querent's own mail | B7 | backend |
| B9 The gifts screen | B6 | backend |
| B10 The staging walk | the backend track is built | backend |
| F1 The presentation and commerce seam | now | frontend |
| F2 The gift panel's two new fields | now | frontend |
| F3 The confirmation's gift state | now | frontend |
| F4 The gift toggle from the product | B5 | frontend |
| F5 `/redeem/` | **B7. This is the join** | frontend |
| F6 Content for the other giftable readings | client copy and artwork | frontend |

**B2 goes before any code on either track.** The partner builds against
`API_CONTRACT.md`, and a contract still advertising `gift_code` as a payment
method is how a button gets built for a method nobody will implement.

**F6 is not this epic.** It is filed so the dependency is visible: each
`ReadingPage` record that lands lights up gifting for that reading with no
gifting work at all.

## The order, and the gate on each unit

Each unit runs the inner loop in section 0.5 and owes its tests in the same
step, hard rule 11. What follows is only the **gate**: the observable fact that
has to hold before the next unit opens. A gate is not a summary of the unit.
Read the unit in `gifting-tickets.md` for what to build.

1. **B1.** `/docs` is out of `.gitignore`, the 40 files under it are committed,
   and the superseded header on `gifting-plan.md` is in the same commit, so
   nobody clones the plan without the notice that it is dead
2. **B2.** No sentence in `API_CONTRACT.md` predicts a gift payment method, and
   the frontend can build its whole track from that file alone
3. **B3, the proving unit.** **Every existing settlement test passes unchanged.**
   That is the whole reason the readings model lands with nothing gift-shaped in
   it: if the shape is wrong, this is where it is found, with nothing confusing
   the diagnosis
4. **B4.** An order holding two readings becomes fulfilled only when both are
   delivered, and the due column stays correct across a delivery-window change,
   which is what proves it is derived
5. **B5.** A gift object on a line that is not giftable is **refused**, and the
   refusal is asserted
6. **B6.** A redelivered `payment_intent.succeeded` mints one code and not two,
   and a gift order sends no `ReadingToWrite` and reaches no work queue
7. **B7.** Two requests race one code against a real database with real locks
   and **exactly one wins.** That race is the only thing that proves the lock
   exists, and this is the endpoint where a mistake means free readings
8. **B8.** A redeemed gift produces the querent's mail carrying the delivery
   promise, counted from `asked_at`
9. **B9.** The gifts screen opens on outstanding, shows how long each has been
   outstanding, and resends. The badge is neutral
10. **B10.** The walk buys a gift, receives the code, redeems it in a second
    session as a different person, and proves Jennifer was told **once**, on the
    day of redemption. **Count the skips**, because a skipped test reads as a
    passing one in the summary line

Frontend units run their own loop against that repository's `AGENTS.md`. F1's
gate is `month-ahead` rendering through the seam with no visual change, and the
presentation half mounting on its own.

## The join

**F5 needs B7 deployed somewhere it can reach.** It is the only place the two
tracks meet. Run the frontend track F1, F2, F3 on day one, F4 once B5 lands, and
hold F5 until B7 does.

## Writing it back

Hard rule 15: progress goes on the issue as it happens. Section 10 and
`docs/agents/issue-tracker.md` govern the shape. For this epic:

- Boxes are ticked on the unit's own issue as they land
- The shipping comment names the commit and says whether it is committed, pushed
  or deployed. Those are three different states here and the difference has
  mattered
- A decision made while building, that the ADRs did not already make, goes on
  the issue **and** into the ADR that owns the area. Ten planning tickets closed
  with no comments on them once, and three of those decisions had to be
  recovered from a temporary directory

## Traps that span more than one unit

- **`ReadingToWrite` becoming one mail per reading.** It is one mail per asking.
  Its docblock says why: Jennifer gets a single thing to work from rather than
  three that arrive together and have to be reassembled. B3 moves it and B6 adds
  a caller, so the reason has to survive both
- **`Mark delivered` built as a button a PDF upload later replaces.** ADR 0006
  refuses the upload for this epic and says why. Build the action an upload
  hangs off, so the shape survives it
- **Due stored rather than derived.** A stored `due_at` is a second writer for a
  value that already has one, and it goes stale the day a window changes
- **A gift spent on arrival.** Email scanners and link prefetchers follow links,
  so spending belongs on submit. With no expiry and no refunds, a gift spent by
  a scanner is unrecoverable
- **Lookup answering vaguely.** "Already redeemed" is a state the real recipient
  has to be told about, so it cannot hide behind the answer given to an unknown
  code. The code's own entropy plus a throttle is what closes the oracle

## The epic is done when

- [ ] A purchaser can gift any reading that is `is_giftable` **and has a page**, today `month-ahead`
- [ ] The gift panel takes a signature, a recipient email typed twice, and an optional message, and refuses through one validation path
- [ ] The recipient's mail names who it is from, carries a working link, and prints the code underneath
- [ ] `/redeem/` resolves a code **without spending it**, and renders that reading's presentation with no commerce on the page
- [ ] Redemption is atomic and single-use, proved by a race against a real database with real locks
- [ ] A redeemed gift creates a reading and tells Jennifer **on the day it was redeemed**
- [ ] An unredeemed gift appears on no work queue, and is visible on the gifts screen with how long it has been outstanding
- [ ] Nothing in the panel answers two questions
