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
an outstanding obligation, and `redeemed_at IS NULL` is the whole of the query
that finds them. It wants a screen in the panel from the first release, not
because anyone has to act on it, but because otherwise a recipient whose address
bounced is invisible: the money is taken, the code exists, and nobody is
waiting for anything they know about.

**A nudge rather than a deadline.** A reminder to an unredeemed recipient some
weeks on does the thing an expiry date is usually reached for — getting the gift
used — without the failure mode of taking it away.

## What this forecloses

Gifts already sold cannot be retroactively expired, so this is genuinely hard to
reverse. If the client later wants a limit, it applies to gifts sold after that
date: a nullable `expires_at`, null meaning the promise already made.

**No such column is added now.** A column nothing writes and nothing reads is a
column the next person assumes is enforced somewhere.
