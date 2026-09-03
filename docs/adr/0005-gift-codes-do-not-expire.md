# Gift codes do not expire

> **Decided 1 September 2026**, alongside
> `0004-a-reading-is-a-row-of-its-own.md`. The client's proposed flow does not
> mention expiry either way, so this is a decision rather than a reading of it.

Most gift cards expire, so a future reader will look at `gifts` and wonder where
the column went. It is deliberately absent.

**There are no refunds here**, stated in `API_CONTRACT.md`. An expired gift is
therefore money kept for a reading never delivered, and the person it is taken
from is the one person in the transaction who never paid and may never have seen
the email. That is a worse thing for this business to do than to owe a reading
indefinitely — the obligation is one reading, it never grows, and honouring it
late costs exactly what honouring it on time would have.

**The liability is real and is named rather than capped.** An unredeemed gift is
an outstanding obligation, and ~~`redeemed_at IS NULL` is the whole of the query
that finds them~~ `code IS NOT NULL AND redeemed_at IS NULL` finds them. It
wants a screen in the panel from the first release, not because anyone has to
act on it, but because otherwise a recipient whose address bounced is invisible:
the money is taken, the code exists, and nobody is waiting for anything they
know about.

**That query was corrected on 3 September 2026, building B9 (#75).** The
original was written on 1 September, before ADR 0004's addendum split a gift row
from its code: the row is written when the buyer types their four fields at
checkout and minted when the money arrives, so `redeemed_at IS NULL` on its own
counts every abandoned checkout as an obligation. Nothing was taken and nobody
was told, so nothing is owed. `Gift::scopeOutstanding` is the one place that
query lives now, and `docs/adr/0006-one-screen-per-question.md` carries the
argument beside the screen it is read on.

**A nudge rather than a deadline.** A reminder to an unredeemed recipient some
weeks on does the thing an expiry date is usually reached for — getting the gift
used — without the failure mode of taking it away.

## What this forecloses

Gifts already sold cannot be retroactively expired, so this is genuinely hard to
reverse. If the client later wants a limit, it applies to gifts sold after that
date: a nullable `expires_at`, null meaning the promise already made.

**No such column is added now.** A column nothing writes and nothing reads is a
column the next person assumes is enforced somewhere.
