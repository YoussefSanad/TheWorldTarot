# Milestone 7, the journeys

Written 12 August 2026, extending `docs/plans/milestone-7-plan.md`. That document is the build record. This one is the customer's view, and the three states a customer can be in when they buy.

**Scope moved twice on 12 August 2026 and this is where it landed.** Earlier the same day three products were to be automated, the Three Card and Five Card Readings arriving by email. That is withdrawn. The position now:

| Product | What the system does | Who writes it |
|---|---|---|
| One Card Reading | Draws, writes, stores, and serves it as a page with a film | Automatic |
| Three Card Reading | Takes the money, confirms it to the customer, tells Jennifer | Jennifer, and **she emails it herself, outside this system** |
| Month Ahead, In Depth | The same | The same |

**Only the One Card Reading is automated.** Everything else keeps working exactly as it does today, and what the other readings actually are is deliberately undecided until there is a reason to decide it.

---

## The hand written readings are already built

This is the useful finding, and it is worth stating before anything is planned.

"Complete the order, tell the customer it is being prepared, notify Jennifer" is `Fulfillment::ManualPdf`, shipped in M5 phase A and working today:

- `MarkOrderPaid` records the payment once, and only once, however many times it is told
- `OrderPaid` goes to the customer. Subject "Your order is confirmed", listing what they bought, what they asked, and what it cost. It already says readings are written by hand and to allow a little time
- `ReadingToWrite` goes to Jennifer, carrying the order, the customer, and **their question**, which is the thing she cannot work without
- The order sits on her working list in the panel until she marks it fulfilled

Nothing about that changes, and no code needs writing for it. Two gaps, and neither is a feature.

### Gap 1: the notification has nowhere to land

`MAIL_FULFILLMENT_RECIPIENT` is empty. It falls back to `fulfillment@theworldtarot.com`, which is an address this system **sends from**. Verification in Resend authorises sending and creates no mailbox, so as things stand the notification is delivered to nothing and nobody is told that it was not.

Her real address, set in Laravel Cloud for staging and production, is the entire task. That is the "email will be provided" item, and until it is provided the manual path is silently broken.

### Gap 2: her replies, and the customer's

Two things point at mailboxes that may not exist. ~~The confirmation invites the customer to reply to `orders@theworldtarot.com`.~~ **Half of that changed on 30 August 2026, issue #47.** The confirmation comes from `noreply@theworldtarot.com`, carries no Reply-To, and no longer invites a reply at all, so nobody is directed into the void any more. She will be emailing readings from her own mail, so her customers will reply to wherever she sent from, which is fine.

**The gap did not close, it moved and got worse.** `orders@theworldtarot.com` is now where the internal new order notification lands, so that address has to *receive*, and receiving is the half no Resend verification provides. A customer being told to reply somewhere dead costs a reply. Jennifer never seeing that a reading is waiting costs the reading.

This matters more now than it did, because manual delivery makes correspondence normal rather than exceptional. It is the open GoDaddy item in `CLAUDE.md` section 8 and it wants settling before the first real customer, not after.

---

## What changes with who is buying

Three states, not two, and the third gets forgotten. This applies to every product.

| | **New customer** | **Returning, signed in** | **Returning, not signed in** |
|---|---|---|---|
| Who the order attaches to | A new account, made from the email they typed, with no password | Their account. Anything they type is ignored | Their existing account, found by email |
| Order confirmation | Yes | Yes | Yes |
| Invitation to set a password | **Yes**, when the order is paid, never when it is placed | No | **No** |
| Their One Card reading | Reachable straight away by token | By token, and listed in their account | Reachable straight away by token |
| Appears in their account | Once they claim it, retrospectively, including this purchase | Immediately | Next time they sign in |

Two things in that table are load-bearing and already built.

**A returning customer is never sent a password link**, even though the checkout has no idea it is them. If it did send one, typing somebody else's email into a checkout would land a set-a-password message in their inbox. It hands the sender nothing and it is still a phishing shaped tool aimed at our own customers. They get a confirmation telling them to sign in.

**The account gathers everything up retrospectively.** The customer row exists from the first order, so somebody who buys three times as a guest and then claims their account finds all three waiting.

---

## Journey 1: the One Card Reading, lived on the page

Delivery is the page, permanently. **Its words are never emailed.** The email exists so they can find their way back.

### What the customer does

1. Reads the product page, priced in their own currency, and types their question or leaves it empty
2. Pays
3. Lands on their page. A card turns over. **The film plays.** Their reading appears word by word and they read it
4. An email arrives carrying a link back to it, and nothing else
5. Weeks later the link still opens the same card, the same words, and the film ready to play again

### What the system does

| Moment | What happens |
|---|---|
| Order placed | The order and its lines written in one transaction, prices captured as they are now, the language recorded, the token minted. Nothing emailed |
| Payment lands | `MarkOrderPaid` locks the order, moves pending to paid once, and inside that transaction draws **one card from those in that language with a film ready to play** and writes the reading row. After it commits, mail goes out and the writing job is queued |
| The page asks | `GET /api/v1/orders/{token}`, repeatedly. `awaiting_payment` while the confirmation is still travelling, then `preparing` **carrying the card and a freshly signed film**, then `ready` carrying the text |
| The job | Claims the reading so a redelivery cannot write a second one, calls the writer, stores the text, marks it ready, sends the email |
| Every later visit | The same endpoint. `ready` on the first ask, text from the row, film signed again for thirty minutes |

### When it cannot be written

Two ways in: no card in that language has a finished film, or the writer refused after its retries.

**The customer** is told their reading is being finished personally and that they will hear shortly. Not an error, and not a promise of a document, since this product does not arrive as one.

**Jennifer** is notified, and **she handles it herself, outside this system**, the same way she handles every other reading. There is no panel for her to write into, by decision on 12 August 2026. She marks the order fulfilled when she has dealt with it, using the action that already exists.

Which means the fallback needs no new machinery at all. It is the notification she already gets, pointed at a different reason.

---

## Journey 2: the hand written readings

Three Card, Month Ahead and In Depth. Unchanged, and included so the whole picture is in one place.

### What the customer does

1. Reads the product page and types their question or leaves it empty
2. Pays
3. Lands on a confirmation. Nothing to watch, nothing to read yet
4. Receives the confirmation email, which tells them the reading is written by hand and to allow a little time
5. Some time later Jennifer's own email arrives with their reading

### What the system does

| Moment | What happens |
|---|---|
| Order placed | Identical to journey 1. Same transaction, same token, nothing emailed |
| Payment lands | `MarkOrderPaid` records it. No card is drawn, no reading row is created, nothing is queued. The order stays unfulfilled, because half delivered is not delivered |
| Emails | `OrderPaid` to the customer, `ReadingToWrite` to Jennifer with the question attached |
| Then | The system waits. It notifies and records, and it does not deliver |
| Jennifer | Writes the reading, emails it from her own mail, and marks the order fulfilled in the panel |

### The mixed order

Somebody can buy a One Card Reading and a Three Card Reading together. One order, one payment, one token, and two different fulfillments: the One Card draws and writes itself while the Three Card waits for her. The order is not fulfilled until she has done her half, which the existing `needsAPerson` check already gets right.

---

## What this changes in the build

Almost nothing, which is the point of the scope having come back in.

**1. The confirmation email cannot say the same thing about every line.** `order-paid.blade.php` currently ends with "Readings are written by hand and sent to you as a PDF, so please allow a little time". **That becomes untrue the day the One Card Reading automates**, and it is untrue in the worst way: it tells somebody to wait for an email that is never coming, about a reading already sitting on a page they were not told about. The wording has to follow the line, not the message.

**2. No spread, no multi card reading, no delivery declaration.** Everything the withdrawn scope needed is withdrawn with it. A reading holds one card, `readings.card_id` as originally planned. If a multi card reading is ever defined, that column becomes a table, which is a small and well understood migration and a bad thing to build in advance for a product nobody can currently describe. That is the same rule `.claude/external-services.md` applies to interfaces: one real case and an imagined second justifies a guess, and the guess is usually wrong.

**3. Step 10 loses the writing affordance** it gained earlier today. She sees the reading, can have it rewritten, and can delete one. She does not write in the panel.

---

## Deferred, on purpose

- **What Month Ahead and In Depth actually are.** Undecided until there is a reason to decide. Nothing in this milestone depends on it
- **Whether they and the Three Card ever automate.** Same
- **The rush add-on.** `Rush24h` is still unseeded and still sells faster turnaround. An instant One Card Reading has no turnaround left to sell, so the add-on and that product do not belong on the same page. Not urgent while the add-on does not exist
- **A PDF.** Planned as a possible add on and nothing more. The design property that keeps it cheap is already there: the reading is stored as text against the order, so a document is generated later from something that exists rather than being the only form the reading ever had
