# The journeys, redesigned from scratch

Written 22 August 2026, at the instruction to re-derive rather than inherit. **A proposal. Nothing here is decided and nothing is built.**

If it is approved it supersedes `docs/plans/milestone-7-journeys.md` and `docs/plans/purchase-journeys.md`. Until then those remain the record, and where this document disagrees with them, the disagreement is deliberate and is called out.

## What is being re-derived, and from what

Not from the existing documents. From three facts about what is actually sold:

- **The One Card Experience** is a film and a written reading, and the customer sees both on the website
- **The Three Card, Month Ahead and In Depth readings** are written by a person and, today, sent from her own mailbox
- **The Viewing Room Pass** opens every film in the library, forever, decided 22 August 2026

Everything else follows from those and from the constraint that binds all of it: **an account may never be required in order to buy**, because demanding one costs the sale at the worst moment.

---

## The central proposal: one page for everything bought

Today each product is heading toward a different destination. The One Card lands on a page of its own, the hand written readings land in Jennifer's sent items, and the pass lands nowhere at all because it is a stamp on an account. Three products, three delivery surfaces, one of which is a person's private mailbox.

**Propose instead: every order has one page, reached by one link, and it is the home of everything on that order.**

| Product on the order | What the page shows |
|---|---|
| One Card Experience | The card, its film, and the reading, as soon as they exist |
| A hand written reading | "Being written for you", and then the reading itself when Jennifer delivers it |
| Viewing Room Pass | "Your pass is active", and the way into the library |
| A mixed basket | All of the above, on one page, each at its own stage |

**Why this is worth the redesign and not merely tidier:**

- **It answers the return from Stripe once, for everything.** The customer comes back from a payment page and has to land somewhere that works whether or not the webhook has arrived yet. Building that per product means building it three times
- **It serves the guest.** No account exists at the moment of purchase, and this is reachable without one
- **It makes the email simple.** Every confirmation says the same thing, "here is your order", and the page carries whatever is true at the time. Today's confirmation has to describe the contents of the order in prose, and it already says something that will be false the day the One Card automates
- **It gives the hand written readings somewhere to live** that is not a mailbox

---

## Decision 1: how somebody reaches what they bought

**Options:**

- **A token in a link.** Anybody holding the link sees the order. No account, no sign in
- **An account, always.** Sign in to see anything you bought
- **Both, which is a token that also attaches to the account** the order already belongs to

**Recommendation: both.** The customer row exists from the first order whether or not anybody ever claims it, so a purchase can be reachable by link immediately and appear in the account retrospectively the day they set a password. That property is already built and is currently doing nothing.

**The honest cost, and it is real:** a link that opens a personal reading is forwardable, and whoever holds it holds the reading. The mitigations are that the token is long and random, the page carries nothing but the purchase, and it is the customer's own link to lose. **The alternative costs more**, because requiring an account to read something already paid for is a wall in front of a thing somebody has bought.

**Accepted 22 August 2026, on two conditions**, both of which are requirements rather than remarks:

- **The token cannot be guessable.** Generated from a cryptographically secure source, long enough that the whole keyspace is not worth walking, and compared in a way that does not leak its length. It is a bearer credential for a paid thing and should be treated as one
- **A customer must be able to see everything they have bought in one list**, rather than depending on having kept every link. That means a signed in customer has a list of their purchases, which **is new scope**: no endpoint reads a customer's own orders back today, and `API_CONTRACT.md` currently records the absence as a question for the frontend partner rather than a plan

**This is where the pass differs and cannot follow.** See decision 4.

## Decision 2: where a hand written reading is delivered

**This is the one I would most like reopened**, and it is the decision from 12 August that this redesign disagrees with most directly.

Today she writes the reading and emails it herself, and the system's involvement ends at telling her to. That was the right call for scope at the time. As a permanent design it has four costs, and they compound rather than sit still:

- **The customer who bought a One Card and a Three Card gets two different products from two different places.** One is a page on the site, one is an email from a person. The second feels like the first one broke
- **Nothing is stored.** A customer who loses the email has lost the reading, and there is nothing to resend, nothing to turn into a PDF later, and nothing in their account
- **Her personal mailbox becomes the support channel** for anything they reply with, permanently
- **Fulfillment is on trust.** The panel records that she marked it fulfilled, not that anything was delivered

**Options:**

- **Leave it.** She emails from her own mail. Nothing to build
- **She delivers through the panel.** She pastes the text or uploads a file against the order, and the system puts it on the order page and emails a link. She still writes it wherever she likes
- **She writes it in the panel.** A full editor. Withdrawn on 12 August and I am not proposing it again

**Recommendation: the middle one.** It is a text or file field on the order and a mail, which is small, and it buys the single delivery surface, a stored record, resending, and a real fulfillment signal. **What it must not do is dictate where she writes.** She composes wherever she is comfortable and pastes the result.

**What it costs her:** one extra step, in a panel she is already in to mark the order fulfilled. The step replaces sending an email, so it is close to even.

## Decision 2a: a reading is structured content, rendered twice

**Decided 22 August 2026, and it replaces both the file upload and the pasted text.**

A reading is **a set of fields**, not a document and not a blob of prose. Jennifer fills the fields. The system renders them into a branded PDF from a template we own, and renders the same fields onto the order page. The customer gets both from one source.

### Why this is the right shape, and it is not mainly about PDFs

**It makes the hand written path and the AI path the same shape.** Today they are different in kind: a person produces a finished artifact, and M7's writer produces text this system has to find a home for. Under this design **both fill the same fields**, and everything downstream of that is identical. Automating a product later stops being a rewrite and becomes a change of who fills them in.

That is the move this codebase already makes everywhere it matters. `PaymentGateway` has a person settling by hand today and Stripe later, at one seam, with everything downstream untouched. This is the same idea pointed at fulfillment, and it is worth more here than it looks, because **the alternative was a file whose contents nothing could read**.

Three other things fall out of it for free:

- **One source, two renderings**, so a phone gets a web page that reads well and the customer still gets a document to keep. The choice between those stops being a choice
- **A reading can be regenerated.** Fix the template, correct a typo, and the artifact is rebuilt from what was actually written
- **Her layout is enforced rather than hoped for.** The brand is in the template, not in whatever she exported that day

### What it costs, and the first one is a real blocker

- ~~**The fields have to be defined, per product, and nobody has defined them.**~~ **Largely answered 22 August 2026**, and it is the first time these products have been described anywhere: **Three Card is three cards, Month Ahead is five, In Depth is twelve.** `milestone-7-plan.md` still says this is deliberately undecided and is now out of date on the point. What remains open is the deck, below
- **It constrains her.** Every reading of a kind comes out the same shape. That suits a Three Card, which has three cards and something to say about each. It may not suit In Depth. **Mitigation: one of the fields is freeform**, so there is structure where structure helps and room where it does not
- **Nothing renders a PDF today**, and the platform limits how

### The fields, which are one template at three sizes

The card counts settle the shape. A reading is:

| Field | Notes |
|---|---|
| An opening | Freeform. Her voice, addressing what they asked |
| **Per position, one to N** | The position's meaning, **the card that came up**, and her interpretation of it |
| A closing | Freeform |

`N` is three, five or twelve. **That is one template parameterised by a number, not three templates**, and it is why defining these products turned out to be cheap rather than a project. It also means a fourth spread later is a row rather than a build.

The freeform opening and closing are what keep this from being a straitjacket, per the cost above.

### The open question this creates: which deck

**The system holds twenty two cards.** `CardSeeder` seeds the Major Arcana and nothing else, because that is what the Living Tarot films are.

A twelve card In Depth reading drawn from twenty two Major Arcana is possible and is not what most readers would do. A full deck is seventy eight.

This decides something structural about the fields above:

- **If readings use only the Major Arcana**, the card in each position is a **reference to a card row we already have**, and the template gets its name, its artwork and its meanings for free. The web page can show the artwork. Nothing new is seeded
- **If readings use a full deck**, then either the remaining fifty six cards are seeded, which is real content Jennifer owes and which has nothing to do with the Living Tarot films, or the card in each position is **just text she types**, and the template gets nothing for free

**Recommendation: ask her, before the template is designed.** The difference is the difference between a designed document that shows the cards and a document that names them.

### The rendering constraint, which is a platform problem

`CLAUDE.md` section 2: Laravel Cloud, managed, **no Dockerfile and no self managed servers**. That most likely rules out headless Chrome, which is what produces the best looking output and is what a designer would assume. It has to be confirmed against the platform rather than guessed, and the answer decides between:

- **A pure PHP renderer**, no binary to install, dated CSS support, custom fonts workable. Constrains the design
- **A hosted rendering service**, best fidelity, costs money per document, and is a third party. It would sit behind an interface with a captured vector, per `.claude/external-services.md`

**Open. See the questions at the end.**

### When the PDF is generated

**At delivery, and stored.** Not on every request, and not lazily. The document the customer was sent must stay the document they were sent, so a later template change cannot silently rewrite a reading somebody already has. Regenerating is a deliberate act with its own record.

### Her side, in Filament

1. **A working list**: paid, holds something written by hand, not delivered
2. **The order**, showing who it is for, what they bought, **their question**, and when they paid
3. **Fields on the line, not the order.** An order can hold two readings with two questions, so a per line form is the only shape that works
4. **Fill, preview, then deliver, as separate acts.** She can see the rendered PDF and the page before anything is sent
5. **Delivering does everything**: renders and stores the PDF, stamps the line delivered, mails the customer, and **marks the order fulfilled once every hand written line has gone**

Point 4 is worth defending: if saving were delivering, a half written reading reaches a customer with no taking it back. Point 5 is what makes this cheaper for her than today, because "mark as fulfilled" stops being a button she has to remember.

**Afterwards** she can correct the fields and redeliver, and both are recorded rather than silent.

### What it needs underneath

- **A place for the fields.** Structured content against the order line, which is also where M7's writer will put its output
- **`delivered_at` on the line**, and the stored path of the generated document
- **A private disk, never a public one.** Paid content, and a public bucket URL is a permanent unauthenticated link to somebody's personal reading
- **The download served through the API against the order token**, so there is one rule about who may read a purchase rather than two

## Decision 3: is a reading ever emailed as text

The 12 August position is that the One Card's words are never emailed, and the email exists only to lead back to the page. That is coherent for a product whose point is the film.

**Options:** never email the text, always email it, or email it only for the hand written readings.

**Decided 22 August 2026: the generated PDF is attached, and the email also links to the order page.** A discrete artifact somebody paid for is what people expect to receive, and it is different in kind from prose in a message body. **The cost accepted is that a correction cannot recall the copy already sent**, which is what redelivery is for.

The rule below survives for everything that is not that attachment.

**Recommendation: never email the text in the body, for any of them, but always email a link.** The reason is not the experience, it is that the page is the only place that can show the film, hold the reading, and be updated if anything is corrected. An email is a copy that is wrong the moment anything changes. **Worth revisiting only if customers ask for something they can keep**, and the answer to that is the PDF that was already parked as a possible add on, generated from stored text.

## Decision 4: the pass, and the account it cannot avoid

**A pass is a property of an account. A reading is not.** A token that opened the library forever would be a pass anybody could forward, so the pass is the one thing that genuinely requires signing in.

Which means the guest who buys a pass pays, comes back, and finds a password between them and the thing they just bought.

**Options:**

- **Lean on the set-password mail that already goes out**, and have the order page say plainly that the library is waiting behind a password, with a resend
- **Ask for a password during checkout, but only when the basket holds a pass.** Friction before the money rather than after
- **Sign them in automatically on return from payment.** Smoothest, and it is a login without a credential

**Recommendation: the first, and reconsider the second if real people get stuck.** The third should not be built.

**A new consequence of the pass being forever:** it is the only thing here that cannot be sold to the same person twice. **Buying it while already holding it should be refused at checkout**, not charged and quietly ignored. Nothing does that today.

## Decision 5: the order nobody ever pays for

Not in either existing document, and it appears the moment a hosted checkout exists. Somebody places an order, reaches Stripe, and closes the tab. The order sits `pending` forever, holding a captured price and, if gift codes have been redeemed against it, possibly holding a code hostage.

`OrderStatus::Cancelled` exists and nothing writes it.

**Answered 22 August 2026: two hours.**

**The part that makes this safe or dangerous is not the number, it is that Stripe has its own.** A Checkout Session carries its own expiry, defaulting to twenty four hours and settable between thirty minutes and twenty four. If our order expires at two hours and the session lives for twenty four, then somebody who wanders back at hour three **pays successfully against an order we have already cancelled**, and money has been taken for something no longer owed. The webhook then arrives about a cancelled order and has to decide between honouring it and refunding it, which is a conversation nobody wants to have with a real customer.

**So the session's `expires_at` is set to match the order's, and the two die together.** With that, the failure mode is safe rather than expensive: the late customer is refused by Stripe before paying, nothing is collected, and they place a fresh order. Without it, two hours is materially worse than seven days.

**The interaction with gift codes is the other half**, and it is an argument for validating a code at placement and consuming it at payment, which is what the phase B plan already recommends. An expiring order must release anything it was holding.

---

## The journeys, as they would be

Assuming every recommendation above.

### One Card Experience

1. Reads the product page, types a question or does not, pays at Stripe
2. Comes back to **their order page**. It says the payment is being confirmed if the webhook has not landed yet
3. The card turns over, **the film plays**, the reading appears
4. An email arrives carrying the link back
5. The link opens the same card, the same words, the same film, indefinitely

### A hand written reading

1. Same until step 2
2. The order page says the reading is being written by hand, and roughly when to expect it
3. Jennifer writes it wherever she likes, and **delivers it through the panel**
4. An email tells the customer it is ready, linking to the same page
5. The reading is on the page, permanently, alongside anything else they bought

### The Viewing Room Pass

1. Sees the locked library, buys the pass, pays at Stripe
2. Comes back to the order page: **the pass is active**
3. If they have an account, the way in is right there
4. If they do not, the page says the library is behind a password and offers to send the link again
5. Once in, every film, forever

### A mixed basket

One page, three stages, each moving at its own pace. The order is not fulfilled until Jennifer has done her half, which the existing check already gets right.

---

## The unhappy paths, which no document has covered

| What happens | What the customer should see |
|---|---|
| Card declined | Back on the basket with the order still pending and nothing lost |
| Webhook never arrives | The page says confirming, and reconciliation settles it within the sweep window |
| They close the tab at Stripe | The order expires quietly. Decision 5 |
| They typed the wrong email | **They still land on the order page**, because the token is in the return URL rather than only in the mail. This is an argument for the return URL carrying it |
| They already hold the pass | Refused at checkout, before payment |
| A gift code covers the whole order | No Stripe at all. Straight to the order page, finished |
| The reading cannot be written | The page says it is being finished personally. Jennifer is told, and handles it as she handles any other |
| Refund | The pass is revoked if the order granted one. Phase B question 6 |

---

## What this changes against what exists

| | Today or planned | Proposed |
|---|---|---|
| Delivery surface | Three, one of them a private mailbox | One order page |
| Hand written readings | Emailed by her, unstored | Delivered through the panel, stored, on the page |
| Reaching a purchase | Token, for the One Card only | Token for every order, plus the account retrospectively |
| Confirmation email | Describes the order in prose | Says "here is your order" and links |
| Unpaid orders | Pending forever | Expire on a schedule |
| Buying a pass twice | Charged | Refused |

## What it costs, and who owns it

- **The order page endpoint and its token** are phase B, not M7, because every journey needs them
- **Panel delivery for hand written readings** is small and is arguably phase B as well, since it is what makes the order page true for the products that exist today. M7 does not need to exist for it
- **Expiring unpaid orders** is phase B, and is a scheduled sweep beside the payment reconciliation one
- **Refusing a second pass** is phase B, at validation
- **The One Card's automatic half** stays M7 and is unaffected

## Open, and needing an answer before this is built

1. **Does Jennifer accept delivering through the panel** rather than from her own mail. This is a conversation with her, not a technical question, and the redesign leans on it
2. **How long before an unpaid order expires**
3. **Is a personal reading behind a forwardable link acceptable**, which is decision 1's cost stated plainly
