# The unclaimed account gets a name

> Written 28 August 2026, out of an architecture review of the backend. Approved the same day.
> Not started. Nothing below has been built.

## What is wrong

An account with no password is a real thing in this domain. It is what a purchase creates for
somebody who has never signed up, it is the only kind of account a claim link is worth sending to,
it is what makes that link work exactly once, and it is why signing in has to pay for a bcrypt it
throws away.

Four consequences, and the concept behind them is written nowhere. It exists only as the literal
comparison `$customer->password === null`, spelled out three times, in three modules that never
look at each other.

| Where | What it is really asking | How it is written |
|---|---|---|
| `MarkOrderPaid:141` | Should a claim link go out | `password === null` |
| `SetPasswordController:33` | Is this link still worth anything | `password !== null` |
| `SessionController:111` | Is there a hash to check | `password === null` |

Each of those carries good reasoning about why it asks. None of them carries the reasoning about
what the answer means, so that reasoning is triplicated too, in three docblocks, agreeing by hand.

This is the same finding `MarkOrderFulfilled` was built out of, arriving from the other direction.
There, one column had two writers and they agreed only because both happened to write `now()`.
Here, one fact has three readers and they agree only because all three happened to spell the same
comparison. Neither is agreement by structure.

## The part that is not merely untidy

`SetPasswordController` reads the condition through its own `firstWhere`, and the write that ends
the claim happens later, inside the password broker's callback. The check and the write are in
different scopes.

Two claim requests arriving together both read null, both pass, and both write. The loser
overwrites the winner's password. The window is one broker call wide and it is real.

That single condition is what its own docblock calls the thing stopping a claim link becoming a
spare key. It is currently enforced by a read that nothing holds still.

## What gets built

A predicate that names the concept, and one writer that owns the claim, so the condition and the
write it depends on cannot be in different scopes.

Deliberately **not** a module owning the token and the mail as well. `MarkOrderPaid` goes on
minting its own token and sending `SetPasswordLink`, because the reasoning at that call site, that
a claim link sent to a claimed account is a phishing shaped tool pointed at our own customers, is
reasoning about why it asks rather than about what claiming is. Pulling the mint across would put
`Password::broker` and a mailable behind an interface that has to serve password reset too, which
is a larger seam decision than this needs and is not being taken today.

### The word

**Unclaimed.** The negative, because the negative is the interesting state: it is what a purchase
creates, what a link is worth sending to, and what makes the link work once. Every caller asks
about the negative, so naming the positive would mean three call sites negate.

It goes into a new `CONTEXT.md`, which this repo does not yet have. Only this term, not the whole
glossary. The domain words currently live in `AGENT-BRIEF-architecture-tickets.md` and
`CLAUDE.md`, and copying them into a third file where none of the three is authoritative is the
drift this exercise is against. Moving them properly is its own piece of work.

## The steps

### 1. The predicate

`Customer::isUnclaimed()`, beside `hasViewingRoomAccess()`, which it resembles: a guarded reading
of one nullable column where the presence of the value is the whole answer.

Its docblock is the one that does not exist anywhere today. What an unclaimed account is, that a
purchase is what creates one, and that a null column is the whole answer **for now**, so the day
claiming means anything more, an `invited_at`, a claim that expires, a second product that creates
accounts, there is one place that has to learn it.

Then the three comparisons become three questions. `SessionController` joins even though its
question sounds different: "is there a hash to compare against" is true of exactly the unclaimed
accounts, and the coincidence is not one. Its docblock keeps every word about bcrypt timing, which
is reasoning about what it does with the answer rather than about the answer.

**No behaviour changes.** Every existing test stays green exactly as written, which is the check
on this step.

### 2. The writer

`app/Enums/ClaimOutcome.php`: `Claimed`, `AlreadyClaimed`, `LinkNotValid`.

Three outcomes rather than a boolean, on the argument `PaymentInstruction` already makes in its own
docblock: a scalar can carry one of them plus folklore about what the others mean. There are
genuinely three answers here and the endpoint says something different about each.

`app/Actions/ClaimAccount.php`, beside `MarkOrderPaid` and `MarkOrderFulfilled`, which is where this
codebase puts the one moment something happens when more than one road reaches it. Takes an email,
a token and a password. Answers a `ClaimOutcome`.

Inside, the thing this whole plan exists for:

- A transaction wraps `Password::broker('customers')->reset`
- The callback re-reads the row with `lockForUpdate` and refuses if it is already claimed
- Refusing throws, which rolls back the broker's token deletion along with everything else, so a
  claimer who loses the race keeps their token rather than having it spent by the winner

The broker has no way to say no from inside its callback, which is why the refusal is a throw
caught at the edge of the action rather than a return. Stated here because it looks like control
flow through exceptions and it is not: it is the only way to roll back a write the broker performs
after the callback returns.

A conditional `whereNull('password')->update(...)` was considered and declined. It is simpler and
just as atomic, and it costs the `hashed` cast, which is what both password controllers currently
rely on to do the hashing on assignment. Making the hashing explicit in one of the two places that
writes a password is how the two come apart later.

Ships with `tests/Feature/ClaimAccountTest.php`: all three outcomes, and a second claim leaving the
first password standing.

**The concurrent case is proved sequentially and said so plainly**, per `.claude/testing.md` on
anything genuinely impractical to test. A two-connection test would prove that Postgres implements
`lockForUpdate`, which is not our behaviour to prove, and it is exactly the test that goes flaky in
CI and gets weakened later, which hard rule 3 forbids. The docblock says the lock is what makes the
concurrent case safe.

### 3. The endpoint

`SetPasswordController` becomes the mapping from a `ClaimOutcome` to wording and status, and drops
its own `firstWhere` and its own broker call.

**Nothing a customer sees changes.** Unknown address and already-claimed keep the deliberately
identical message, so this cannot be used to ask whether an address has an account or whether it
has been claimed. An expired token goes on saying so, on `ResetPasswordController`'s reasoning that
somebody holding a token learns nothing new from being told it is stale.

`PasswordResetTest` gains one case pinning that unknown and already-claimed answer identically.
That promise is currently kept by two branches happening to construct the same string, and this is
the first time anything checks it. Its existing endpoint cases stay where they are.

### 4. The word, written down

`CONTEXT.md`, with "unclaimed" and with "claim link" if it earns its own entry.

**`ResetPasswordController` is not touched**, and that is a decision rather than an oversight. It
shares six lines with the claim path: `forceFill` the password and a fresh `remember_token`, then
`event(new PasswordReset)`. Those six lines are the framework's prescribed way of writing a
password, not this codebase's rule, so sharing them would buy locality over Laravel rather than
over the domain. The one line that differs, reset killing every token the customer holds, is the
line with security reasoning attached, and a shared writer would have to carry it as a flag.

### 5. The suite

Full run, output reported as run rather than asserted.

## What this gives up

The three call sites go on reading one nullable column, and nothing stops a fourth being written
tomorrow that spells the comparison out again. The predicate makes that a choice rather than the
only option; it does not make it impossible.

`ClaimAccount` owns the write and not the invitation, so "everything that happens when somebody
claims an account" is still two modules. That is the Q1(b) shape, chosen knowingly. The day a
third thing invites somebody, the mint and the mail should follow.

## Decisions taken, so they are not reopened by accident

| Decision | Why |
|---|---|
| A predicate plus one writer, not a full claim module | Smallest change that makes the condition and the write inseparable |
| `SessionController` folds in | Same fact, and the resemblance is not a coincidence |
| The race is in scope | It is the difference between a naming improvement and a correctness one |
| The word is "unclaimed" | Every caller asks about the negative |
| An action, not a model method | House pattern for one moment reached by more than one road |
| Transaction and locked re-read, not a conditional update | Keeps the `hashed` cast doing the hashing |
| A named outcome, not a boolean | Three answers, and the endpoint says something different about each |
| Reset is untouched | Its shared lines are Laravel's rule, not ours |
| Sequential proof of the race, stated | A two-connection test proves Postgres, and goes flaky |
| `CONTEXT.md` gets this term only | Three copies of the glossary and no authority is the drift being fought |
