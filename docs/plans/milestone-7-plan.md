# Milestone 7, the One Card Reading as an experience

Drafted 11 August 2026. **Not approved, not started.**

`CLAUDE.md` section 5 describes M7 as "AI fulfillment of the One Card Reading: OpenAI service, payment gating, rate limiting, idempotency. Not a new product, a `fulfillment` change on the existing One Card row." That description assumed the AI simply replaced Jennifer at the keyboard and the reading arrived the way a written one does.

**The brief has changed, and it is larger than a fulfillment swap.** What was described on 11 August 2026 is a place a customer arrives at rather than a document they receive:

- Paying takes them to a page on the website
- A card is drawn for them there
- A film plays
- Their reading is revealed on screen as it comes
- The same reading can be returned to later and lived again

The reading being a stored thing somebody revisits is the part with the widest consequences. It makes the drawn card permanent, gives a reading an address of its own, and means a buyer with no account has to be able to reach something private. None of that existed in the one line version.

---

## Goal

Paying for the One Card Reading draws a card, writes a reading against that card and the question the customer asked, and delivers both as a page on the website that plays and reveals rather than a document that arrives. The card, the reading and the question are stored against the order line the moment they exist, so the page renders identically the first time and the hundredth. Nothing is ever written twice for one purchase, nothing is written at all without confirmed payment, and a reading that cannot be written falls back to a person rather than leaving a paying customer looking at an apology.

## Out of scope, and who owns it

| Out | Owner |
|---|---|
| The page itself, the film player, the reveal animation, everything presentational | The frontend partner, always. Hard rule 1 |
| Redirecting to that page from a card processor | M5 phase B. See dependencies below |
| The other three readings. They stay written by hand, and Jennifer emails them herself | Nobody. Confirmed 12 August 2026, after a widening earlier the same day was withdrawn. That path is `Fulfillment::ManualPdf` and is already built. Journeys in `docs/plans/milestone-7-journeys.md`, which also records the two gaps in it, neither of them code |
| Gift codes, the rush add-on, VAT | M5 phase B |
| Newsletter, FAQ, legal | M6 |
| Retrofitting `BunnyStream` behind `VideoPlayback` and `VideoLibrary` | Still unowned. This milestone touches playback as a caller and does not open that file, per `.claude/external-services.md` |

## What this builds on, read from the codebase on 11 August 2026

- **M5 phase A is built and on the `milestone-5` branch.** Orders, order lines, customers made by buying, `MarkOrderPaid`, the panel's settle action, and the mail. Phase B, the card processor, is not started
- **`MarkOrderPaid` is the only door.** It already branches on `products.fulfillment`, and its comment says in as many words that the One Card Reading becoming AI written "is a data change here and not a new branch". That is still true, and this milestone is what proves it
- **`Fulfillment` has two cases**, `ManualPdf` and `AccessGrant`. M7 adds a third
- **`ReadingWriter` is already declared** in `.claude/external-services.md` as the M7 interface, backed by OpenAI, named in `config/reading.php`. Neither the interface, the config file nor any implementation exists yet
- **`order_lines.question` exists**, nullable, on the line rather than the order, and `allows_question` is true for every reading
- **The draw exists and is stateless.** `GET /{locale}/cards/draw` remembers nobody by design. **A reading's card is the opposite of that** and cannot reuse it as it stands
- **`RevealVideoSource` and `FullFilmRevealVideo` exist**, named in `config/reveal.php`, and `RevealPlayback` carries a signed URL with a thirty minute life
- **Orders do not record a language.** Nothing in `orders`, `order_lines` or `PlaceOrder` captures the language the purchase was made in, and a reading has to be written in one
- **`ManualSettlement` settles an order from the panel**, which is what lets this whole milestone be built and walked before a card processor exists

---

## The shape of the thing, and the decisions inside it

### A reading is a row, created at payment, reached through the purchase that bought it

One row per reading, keyed to the order line that bought it, holding the drawn card, the question as it was asked, the language, the text and its state.

**The address is a token on the order, not on the reading.** Settled while walking the journey on 11 August 2026, and the reason is the moment of arrival. The customer comes back from paying before this system necessarily knows they paid, so the page they land on has to have an address that existed before the money moved. A token minted when the order is placed has one. A token minted when the reading is created does not exist yet at the moment somebody needs to be sent to it.

It is the better shape anyway. One purchase is one page, so an order holding a reading and a pass is one place to land rather than two, and a three card reading later is one page with three cards rather than three addresses. The token is minted at placement, returned by `POST /orders`, used as the return address after payment, carried in the email, and never changes.

The reading row itself is created at the moment of payment rather than at the moment of first view. Three reasons, and the third is the one that matters:

- The card must be drawn once. Drawing at first view means a refresh before the page settles draws a second card, and the customer has watched two different films for one purchase
- Writing starts while the film plays, which is where the waiting goes
- **A paid order with nothing recorded against it is indistinguishable from a bug.** If the row exists from the instant the money lands, support can answer "what did I buy" from the order alone

### The card is drawn once and never again

`cards.draw` stays exactly as it is, public, stateless and for the homepage. The reading draws its own card into storage. They are different acts that happen to look alike, and merging them would put a memory into an endpoint whose whole design is that it has none.

### Writing happens in a queued job, and the film covers the wait

Per `.claude/api.md`, anything touching a slow downstream service is queued. The job is dispatched from inside `MarkOrderPaid`, after commit, alongside the mail.

Two consequences from `CLAUDE.md` section 2 that bind it:

- **Delivery is at least once, so the job must tolerate running twice.** It survives by claiming the row: a conditional update from `pending` to `writing` that only one delivery can win, and a second delivery finds nothing to claim and stops. This is the same shape as `MarkOrderPaid`'s locked transition, for the same reason
- **A job slower than the visibility timeout is delivered again while still running.** Writing a reading is the slowest thing this system will do, so the claim has to be the protection rather than the timeout

### Failure falls back to a person, because the manual path still exists

A reading that cannot be written after its retries marks itself failed, tells Jennifer, and puts the order back on her working list. She writes it by hand and sends it the way every reading is sent today.

This costs almost nothing, because `ManualPdf` fulfillment, the `ReadingToWrite` mail and her list are all already built. **A paying customer must never be told the machine is unwell.** They are told their reading is being finished by hand and will arrive by email, which is the truth and is also what they would have got last month.

### The paywall is the row, not the endpoint

`CLAUDE.md` hard rule 7 says never call the AI without confirming completed payment. The structural version of that rule: **nothing but `MarkOrderPaid` may create a reading row, and nothing but a reading row may cause a call to the writer.** There is no endpoint a customer can reach that starts a generation, so there is no gate on such an endpoint to get wrong. Reading endpoints only ever read.

### Idempotency is keyed to the order line, not the order

`CLAUDE.md` section 4 says "AI readings save against the order, enforced by a unique constraint on order ID". **That was decided before orders held baskets and it is now wrong.** One order can hold two readings, and a unique constraint on order id would silently deliver one of them. The constraint goes on `order_line_id`, which is exactly one purchased reading, and quantity is capped at one for reading products so a line cannot mean two.

Flagged rather than quietly built around, per hard rule 14. If approved, `CLAUDE.md` section 4 changes with it.

---

## Decisions settled, 11 August 2026

The three that gated everything else. Recorded here rather than in conversation, because the steps below are shaped by them.

### 1. The reading is stored, then revealed. Settled

The job writes the whole reading into the row. The page plays the film, asks for the reading, and reveals it word by word in the browser. The reveal is a frontend animation over text that already exists.

The alternative was a long lived connection to this API forwarding each fragment as the writer produced it. Declined, and the deciding argument was that **the revisit forces the browser-side reveal to exist anyway**: a reading lived again cannot be re-streamed from a writer, so every visit after the first is a reveal over stored text whatever we do. A proxied stream buys a real one on the first visit only, and leaves two mechanisms where one does the job. It also holds a request open for the length of a generation, behind a CDN, on a platform whose scaling assumes short requests, and a connection dropped halfway is a partial reading and an ambiguous state.

What it costs: if a reading ever takes longer to write than the film takes to play, the customer waits. The answer then is to tail the stored text as the job appends to it, which is a change to one endpoint and none to the storage. Held in reserve, not built.

### 2. A token in the address, and it lasts forever. Settled

No account needed. A long random token addresses the purchase, works the moment they land, and goes in the email so they can come back to it. It never expires. If they claim an account, everything they have ever bought is listed there as well.

The consequence, accepted knowingly: **a forwarded link works for whoever receives it.** That is the same property the claim link, the reset link, and any link in any email already have. The response is never cached, the reading is read only, and nothing about the customer beyond their own purchase is reachable with it.

### 3. Only cards with a finished film can be drawn. Settled

The film is part of the experience, not decoration on top of it, and every card will have one before launch. So the reading draws from the same pool the homepage draw uses: cards on the website in that language **with a video ready to play**.

I recommended the opposite and was overruled, which is recorded here so the consequence is not rediscovered later. **A paid reading can now fail for a reason the customer had nothing to do with.** If no card in their language has a finished film, there is nothing to draw and the purchase cannot be fulfilled the way it was sold.

That is survivable because the failure path already exists and is built in step 6: no drawable card means the reading falls to Jennifer, who writes it by hand and picks the card herself, and the customer is told their reading is being finished personally. It is the same landing the writer refusing produces.

What this decision therefore buys itself, in step 4:

- **A count Jennifer can see**, saying how many of the deck can currently be drawn for a reading. An invisible risk becomes a number on a screen
- **A loud failure the first time a draw finds nothing**, telling her rather than only logging it
- **A line in `QA_CHECKLIST.md`** making a full pool part of the launch walk, since "cards will be in place before launch" is a commitment that needs somewhere to be checked

Declined as too blunt: refusing to let the One Card Reading be published while the pool is empty. It couples a product's publish state to unrelated video state, and Jennifer would meet it as a product she cannot turn on for reasons the screen does not explain. Say so if you would rather have the hard stop.

A note on repeats, which follows from the pool being small: two purchases by the same person can draw the same card, and with 22 cards that will happen. Allowed, because the reading is written fresh against their question either way, and avoiding it means a reading has to remember what somebody drew last time.

---

## The journey, end to end

Walked on 11 August 2026, after the three decisions above. Every state named here is one the page has to render, and every one of them is reached in ordinary use rather than only when something breaks.

### 1. The product page

The One Card Reading, priced in their currency, with a field for their question. Optional, which is what `allows_question` means today, and no reading demands one. See open question 10, because a reading written for somebody who asked nothing is a different piece of writing and the voice has to know which it is doing.

### 2. Placing the order

`POST /api/v1/orders`. The order is written as pending, the language it was bought in is recorded, and **its token is minted here**. Nothing is emailed, which is already built and deliberate: an endpoint that sends mail to any address somebody types is an email bomb pointed at whoever they name.

The token comes back on the order. Everything after this point has an address because of that.

### 3. Paying

Today nothing is collected. `payment_url` is null, the order waits, and Jennifer settles it in the panel. **That is enough to walk every state below**, which is the whole reason this milestone is not blocked on phase B.

Later they are sent to the card processor, and **the address they come back to is ours**: the website's reading page carrying the token from step 2. It is not derived from anything the processor invents. That is the same seam `PaymentGateway` already draws. How the money is collected may change. Where the customer ends up may not.

### 4. Landing, and the race that is normal rather than rare

**They arrive back before this system necessarily knows they were paid.** A processor redirects the browser at once and confirms the payment out of band, and those two arrive in whatever order they arrive in. A page written as though payment has registered by the time somebody lands on it is merely lucky on a good day and wrong on a slow one.

So the page asks about its token and is told one of four things, and it can meet any of them first.

| State | What is true | What the page has | What it does |
|---|---|---|---|
| `awaiting_payment` | The order is still pending | The order only | Holds, and asks again. Gives up into "we will email you" rather than spinning forever |
| `preparing` | Paid. The card is drawn, the writing is under way | The card, its film, the question | **Plays the film.** Asks again while it plays |
| `ready` | The reading is written | Everything, including the text | Reveals it |
| `by_hand` | Nobody could write it | The order, and an explanation | Says the reading is being finished personally and will arrive by email |

The card and the film are in the response at `preparing`, before a word of the reading exists. **That is the entire reason the card is drawn at the moment of payment rather than at first view**, and it is what turns the wait into the experience instead of a spinner.

### 5. Preparing, which is where the film goes

The film plays while the reading is written. It is expected to finish first, and the page must handle it not finishing first: if the film ends and the text is not ready, it holds rather than showing an error, because nothing is wrong yet.

### 6. Ready

The text arrives whole and the browser reveals it. Whether the reveal starts under the film's last seconds or after it is the frontend's call, and the API supports either by carrying the card and the text independently.

### 7. By hand

The failure landing, and it must not read like one. The customer is told their reading is being finished personally and will reach them by email. That is the truth, and it is also exactly what they would have received a month ago. They are never told a machine is unwell.

Two ways in, and they land identically: the writer could not produce a reading, or, since decision 3, there was no card with a finished film to draw.

### 8. The email

One message, when the reading is ready, carrying the permanent link. It is the thing they must not lose, and it is the only reason an email is needed at all, since they are already looking at the page when it is written.

**A guest buying a reading currently receives three messages within about a minute**: the order confirmation, the invitation to set a password, and this. See open question 11.

### 9. Coming back

The same address, forever. The film is signed again on every visit, thirty minutes each time, never cached and never stored. The text is read from the row, so a fifth visit is identical to the first.

Whether the fifth visit should have to sit through the film again is open question 12.

### 10. The account

If they set a password, every reading they have ever bought is listed. This needs no extra work: the customer row exists from the moment the order was placed, and the readings hang off their orders, so claiming an account gathers up everything retrospectively. It deserves a test rather than an assumption, and it has one in step 8.

### What the page has to survive

- **The film not playing.** The reading must still be readable. The film is part of the experience and must never be load-bearing for the words somebody paid for
- **The tab closed halfway through the writing.** The job does not care and finishes anyway. They come back to `ready`
- **A refresh at any moment.** Same card, same text, nothing drawn twice and nothing written twice
- **A playback URL that cannot be opened in a browser tab.** Still true, and still means nobody can debug one by clicking it

---

## Decisions still open

Numbered from where they were, so nothing shifts under a conversation already had. Items 10 to 13 came out of walking the journey.

### 4. Whether a One Card buyer effectively gets one Viewing Room film

Their reading page plays that card's film, and they can return to it whenever they like. That is one film out of 22, forever, for the price of a reading.

I do not think this is a problem, and it may sell passes rather than cannibalise them, since it is the only place somebody meets the Living Tarot properly. The homepage draw already serves every film publicly by the 2 August 2026 decision, so nothing is being given away that is not already reachable. **But it is Jennifer's revenue and she should be told in plain words**, not discover it. If the answer is no, `config/reading.php` names a different video source and the page plays a preview instead, which is one class and one line.

### 5. Who owns the words the writer is given

The instructions that shape a reading's voice are either code that arrives by deploy, or something Jennifer edits in the panel.

Recommendation: **code, versioned, with each reading storing which version wrote it.** The "structure, rows, values" spine in `CLAUDE.md` section 4 says she owns values, and tone reads like a value. It is not one. A prompt is the mechanism, an edit to it changes every future reading at once with no preview and no undo, and a bad edit is invisible until customers have paid for the results. She owns the output by reviewing it, and she owns the tone by telling us what it is.

This is `CLAUDE.md` section 8's open item, "AI tone and writing guidelines, if M7 is commissioned". It is now blocking, but only for the last step. Everything before it is buildable against a placeholder voice.

### 6. What the customer is emailed. Settled 12 August 2026

**The One Card Reading's words are never emailed, permanently.** It is an experience on the website and it stays one. Its email carries a link and nothing else.

Two things follow.

**The confirmation email has to stop speaking for every line.** `order-paid.blade.php` ends with "Readings are written by hand and sent to you as a PDF, so please allow a little time". True of every product today and **false about the One Card Reading the day this ships**, in the worst way: it tells somebody to wait for an email that will never arrive, about a reading already sitting on a page nobody mentioned. What the message says has to follow the line.

**The fallback is Jennifer's, outside this system.** When a reading cannot be written she is notified and deals with it herself, the same way she deals with every hand written reading. There is no panel for her to write in, decided 12 August 2026. So the fallback needs no new machinery: it is the notification she already gets, pointed at a different reason, and the fulfilled action she already has.

### 7. Whether anything can be rewritten

Recommendation: **never by the customer, once by Jennifer from the panel, recorded when she does it.** A refresh returning a new reading destroys the thing entirely, and it also costs money on every reload. She needs the override for the reading that comes out wrong, and support will need it too.

### 8. Retention

"Relived in future" reads as forever, and forever is what I would build. Worth naming that a customer asking to be deleted takes their readings with them, and that the panel therefore needs to be able to remove one.

### 9. What happens to the other readings. Settled 12 August 2026

**One product automates, not four.** The Three Card, Month Ahead and In Depth Readings stay hand written and Jennifer emails them herself, outside this system. That path is `ManualPdf` and it already works, so nothing is built for it. What those readings actually are is deliberately undecided until there is a reason to decide it.

The scope widened and was withdrawn on the same day. The record of both, and the journey for the hand written readings, is in `docs/plans/milestone-7-journeys.md`.

The commercial half of this question stands, narrowed to one product: from the day this ships, One Card Readings stop reaching her working list and stop sending her the fulfillment mail. She should agree to that rather than notice it.

**And one thing found while checking:** the fulfillment notification currently has nowhere to land. `MAIL_FULFILLMENT_RECIPIENT` is unset and falls back to an address this system only sends from, so the message that tells her somebody is waiting is delivered to no mailbox and nothing reports the failure. Her real address in Laravel Cloud, both environments, and it is fixed. That is not M7 work, it is a live hole in M5.

### 10. Whether a question is required, and whether the reading uses their name

A question is optional today across every reading, and nothing about that has to change. But a reading written for somebody who asked nothing is genuinely a different piece of writing, so the voice needs to know which one it is doing and both versions need to be good.

Recommendation: **keep it optional and write both.** Demanding a question at the checkout costs sales at the worst moment, and a card drawn for somebody with nothing specific on their mind is a perfectly ordinary reading to give.

Separately: we hold their name, and a reading that opens with it lands differently to one that does not. It is one line in the voice and it is Jennifer's taste rather than mine. Worth asking her.

### 11. Three emails inside a minute

A guest buying a reading currently receives the order confirmation, the invitation to set a password, and then the reading link. All within about a minute, all from different addresses, which is a lot of arriving for one purchase.

Three ways out, and I would not pick one without you:

- **Leave it.** They are three different things and each is individually justified
- **Fold the reading link into the order confirmation**, and drop the third. The link then arrives before the reading is finished, which is fine in practice since nobody opens an email in thirty seconds, and slightly dishonest
- **Fold the password invitation into the order confirmation** for guests, leaving two. This touches shipped M5 code and its security reasoning, which holds either way, but it is the one that reduces the noise without a lie in it

Nothing here blocks the build. It wants deciding before launch rather than before step 1.

### 12. Whether the fifth visit sits through the film again

Coming back is the point of storing this, and somebody returning for the fifth time should probably not have to watch four minutes before reading their own words.

Recommendation: **the first visit is the experience, and every visit after it opens on the reading with the film there to replay.** The API already supports it by carrying both, so this is a frontend behaviour and a note in the contract rather than work here. It needs saying out loud because the alternative is the default if nobody says anything.

### 13. What a refund does to a reading

The token lasts forever, so this needs an answer even though refunds belong to phase B. Recommendation: **a refunded order stops resolving**, and the page says so plainly. It was a purchase, and the money went back.

Cheap to decide now, expensive to retrofit onto an address people already hold.

---

## Dependencies

- **On you:** the nine decisions above. Items 1, 2 and 3 gate everything, and the rest gate single steps
- **On Jennifer:** tone and voice guidance, which blocks step 9 alone. Also the 22 films, which are already owed and which this milestone tolerates the absence of if decision 3 goes as recommended
- **On M5 phase A being merged.** This builds directly on `MarkOrderPaid`, so it starts from `milestone-5` and not from `main`
- **On M5 phase B for the arrival, and only the arrival.** "Upon payment the customer lands on a page" needs a card processor with a return address, which does not exist and is blocked on the VAT and gift code questions in `CLAUDE.md` section 8. **This is not a blocker for the milestone.** Everything here is built and walked against an order settled by hand in the panel, exactly as phase A did, and phase B later supplies a redirect to a page that already works. The one thing that cannot be proved until then is the redirect itself
- **On an OpenAI account and key**, in both deployed environments, before step 9
- **On the frontend partner** for the page. `API_CONTRACT.md` gains a section in step 11, and the partner has still not acknowledged the contract at all

---

## Steps

Eleven, each one coherent commit, each deployed to staging before the next begins per `CLAUDE.md` section 6. Steps 1 to 8 assume decision 1 goes to option A, decision 2 to option A and decision 3 to option A. Step 9 is blocked on decision 5.

### 1. What an order needs before anything can hang off it: a language and an address

**Builds.** Two columns on `orders`, in one migration.

`locale`, not null, defaulting to the fallback language for rows that already exist. `StoreOrderRequest` accepts it and validates it against the locales that are actually servable, `PlaceOrder` stores it. Not on the line, because a basket is bought in one sitting in one language.

`token`, unique and indexed, generated at placement and never changed. A long random string from a cryptographic source, not a hash of anything and not sequential. `OrderResource` returns it, which is what lets the frontend send somebody to their own page and what phase B builds a return address from.

**Tests.** That an order placed in Spanish records Spanish. That a language which is not live is refused rather than stored. That an order arriving with no language gets the fallback rather than an error, since the frontend does not send this field today and this must not break checkout on the day it deploys. That every order gets a token, that no two orders share one, and that the token is in the response.

**What could go wrong.** This changes an endpoint already in the contract. Adding a field to a response is safe by `.claude/api.md`, and the language must stay optional on the request for the same reason.

### 2. The readings table and its model

**Amended 25 August 2026, and this is the only change to this plan.** A reading holds **many cards**, through a pivot, rather than one card through a column. Reasoning in `docs/plans/cms-fulfillment.md`, and the short version is that the panel fulfillment milestone writes into this same table for the Three Card, the Month Ahead and the In Depth, which draw three, five and more. `card_id` singular asserts that a reading is about one card, and three of the four products this table will serve are not. This plan's own words were that nothing holds a reference to a reading yet, so this is the last cheap moment to get the key right. It is still open, and this is it being taken.

**Builds.** Two tables. `readings`: `order_line_id` unique and restricted on delete, `customer_id`, `locale`, the question captured as asked, the text, a `status`, `written_at`, `failed_at`, and the writer and voice version that produced it. `reading_cards`: `reading_id`, `card_id` restricted, and an ordinal.

**A One Card reading is one row in the pivot**, and the AI path fills exactly one. The pivot carries a position and not a meaning: what "past, present, future" refers to is a property of the product and belongs to the layout, so putting it here would be the database holding presentation.

No token here. The address is the order's, settled while walking the journey.

**A reading with no cards at all is valid**, which is decision 3 surviving the amendment: a reading can come into existence with nothing drawn, when nothing in the deck has a finished film. That row exists so the purchase is recorded and Jennifer is told, and it goes straight to the hand written path. Under a column that was expressed by `card_id` being nullable. Under a pivot it is expressed by there being no rows in it, which is the same statement and needs no null.

The question is copied rather than joined, for the same reason `order_lines` captures `unit_amount` and `product_key`: what the reading answers has to survive anything later happening to the order.

A `ReadingStatus` enum, string column with a PHP cast per `.claude/database.md`: `pending`, `writing`, `ready`, `failed`. Plus a label and a colour, since Jennifer reads it in the panel.

Quantity capped at one for reading products in `StoreOrderRequest`, so one line is one reading and the unique constraint means what it says.

**Tests.** That a second reading against one line is refused by the database and not only by the code. That deleting a card a reading refers to fails loudly. That a reading with no cards is a valid row. That a reading holding five cards reads them back in the order they were drawn, since an unordered pivot is a reading laid out wrongly and nothing would say so.

**What could go wrong.** The unique key differs from `CLAUDE.md` section 4, which this step also corrects. Nothing in the system holds a reference to a reading yet, so this is the last cheap moment to get the key right.

### 3. The writer interface, and something that writes without a vendor

**Builds.** `App\Contracts\ReadingWriter`, named in a new `config/reading.php`, per `.claude/external-services.md`. It takes what a reading needs, the card, the question, the language and the voice, and answers with text. It says nothing about models, tokens, temperatures or messages, and the test of that is whether somebody could implement it against a different vendor without touching a caller.

Two implementations ship, and both earn their place exactly as `ManualSettlement` and Stripe do. `SampleReading` writes a fixed piece of prose of realistic length, and is what runs locally, in tests and on staging until step 9. The OpenAI one arrives in step 9.

**Tests.** The interface's callers, against the sample. **Nothing here proves anything about OpenAI**, and no test written in this step may be read later as though it did. That sentence exists because it is the trap that cost this project five days.

**What could go wrong.** The sample being too short or too clean sets the wrong expectation for the page's layout. It should be as long and as awkwardly shaped as a real one.

### 4. Drawing a card and starting a reading at the moment of payment

**Builds.** `Fulfillment::AiReading`, and `ProductKey::OneCard` returning it. A `BeginReading` action: draw a card, create the row, dispatch the job. Called from `MarkOrderPaid`'s existing fulfillment loop, which grows one branch.

**The pool is cards on the website in the order's language that have a film ready to play**, per decision 3. Same narrowing the homepage draw applies, reached the same way, through the video source rather than by a controller knowing what a playable card is.

**An empty pool creates the reading anyway, with no card, and hands it straight to a person.** It does not throw, and it does not leave the payment half applied. The purchase is recorded, Jennifer is told, and the customer meets the same landing the writer refusing produces. A paid order must never end with nothing written down.

Two guards ship with it, because decision 3 turns an empty pool into a customer facing failure:

- **A count in the panel** of how many cards can currently be drawn for a reading, out of how many are on the website. Jennifer sees the number before it matters rather than after
- **A message to her the first time a draw finds nothing**, not only a log line

Plus a line in `QA_CHECKLIST.md` making a full pool part of the launch walk.

An order holding an AI reading and nothing manual is not put on her list and does not send the fulfillment mail. An order holding both waits for the manual one, which the existing `needsAPerson` check already gets right.

`fulfilled_at` is stamped when the reading is ready, not when the money lands, because a reading nobody can read yet is not delivered.

**Tests.** That paying for a One Card draws exactly one card and creates exactly one row. That settling the same order twice creates one reading and dispatches one job. That the card drawn is always one with a ready film, and always in the order's language. **That an empty pool produces a reading with no card, on her list, with the customer told, and never an exception out of `MarkOrderPaid`.** That an order with no reading in it creates nothing. That the pass and the hand written path behave exactly as they did, which is the regression that matters most here.

**What could go wrong.** `MarkOrderPaid` is the most load-bearing action in the system and now does more inside its transaction. The draw is a single query and the dispatch is after commit, both where the access grant already sits. The genuinely new risk is decision 3's: on the day of launch the pool is the thing to check, and the count exists so that checking it is possible.

### 5. Writing the reading, and surviving being asked twice

**Builds.** The queued job. Claims the row with a conditional update from `pending` to `writing`, and stops if it did not win. Calls the writer, stores the text, marks it ready. Retries with backoff on a failure that could pass, gives up on one that cannot.

Backoff bounded by the fifteen minute ceiling on delays in `CLAUDE.md` section 2.

**Tests.** That two deliveries of the same job call the writer once. That a redelivery arriving while the first is still running does nothing. That a claim, followed by a crash, leaves a row that the recovery in step 6 can see rather than one stuck forever. That a writer refusing produces a failed reading and not a half written one.

**What could go wrong.** A row claimed by a worker that then dies is stuck in `writing` with nobody coming. That is what the next step exists for.

### 6. Telling people, and sweeping up what got stuck

**Builds.** Three messages, and one correction to a message that already exists.

- **Ready.** One message when the reading is written, carrying the link and **never the words**, per settled decision 6. It is the only reason an email is needed at all, since they are looking at the page while it is being written, and it is the only way back if they lose the tab
- **Finished by hand.** A failed reading, or one with no card to draw, notifies Jennifer and puts the order on her list, and tells the customer their reading is being finished personally and that they will hear shortly. **It promises no document**, because this product does not arrive as one. She then handles it outside this system
- **The correction.** `order-paid.blade.php` stops telling every buyer their reading is written by hand and sent as a PDF. That sentence follows the line it is true of

A scheduled sweep, alongside the existing Bunny one, finds readings stuck in `writing` past a sensible age and either retries them or fails them.

**Tests.** That a ready reading sends one message carrying the token, and sends it once however many times the job is delivered. **That the message never contains the reading text**, asserted directly rather than assumed, because it is a permanent promise that one careless template change breaks silently. That a One Card order's confirmation does not promise a PDF, and that an order for a hand written reading still does, including the order holding both. That a failed reading reaches Jennifer with the question attached. That the customer is told once and not on every retry. That the sweep leaves a reading that is legitimately still being written alone.

**What could go wrong.** `CLAUDE.md` carries an open item that the platform's scheduler may not tick every minute, which would quietly make this sweep daily. It is still unmeasured, and this is the second scheduled task to depend on it.

### 7. The page's endpoint, and the four states it answers with

**Builds.** `GET /api/v1/orders/{token}`. Flat, with no language segment, because a purchase is a possession rather than content and its language was fixed when it was made. Looked up by the token column alone, never by id, so nothing here is enumerable.

It answers with one of the four states in the journey above: `awaiting_payment`, `preparing`, `ready`, `by_hand`. Plus what that state has: the card in the reading's own language, the question, a freshly signed film, and the text once there is text.

`Cache-Control: no-store`. It is personal, and it carries a credential with a timer.

**Nothing about this endpoint ever starts a generation.** It only reads. That is the structural form of hard rule 7, and it is why there is no payment gate here to get wrong.

The film comes from a source named in `config/reading.php`, against the existing `RevealVideoSource` contract, used exactly as the homepage uses it: `eligible` narrowed the draw in step 4 and `playbackFor` signs it here. Decision 3 is what makes that clean. Had the pool been widened to cards without films, this would have had to use half the interface and the asymmetry would have needed writing down.

Rate limited per address, because a token is all it takes to ask and every ask signs a URL.

**Tests.** An unknown token is a 404 that says why, per the trap about empty `HttpException` bodies. All four states, in the order the page meets them. A pending order answers `awaiting_payment` and carries no card, so nothing is revealed before the money lands. A reading being written carries its card and film and no text. The signed URL appears on nothing cacheable. The token of one customer's order never reaches another's.

**What could go wrong.** A signed playback URL cannot be opened in a browser tab while `BlockNoneReferrer` is on, which `docs/CHECKPOINT.md` says was decided off on 7 August 2026 and still measured 403 as of that date. The reading page is a page, so it is unaffected, but nobody will be able to debug one by clicking it.

### 8. A reading belonging to somebody

**Builds.** `GET /api/v1/readings`, authenticated, listing the signed-in customer's readings newest first: the card, when it was written, and the token of the order that reaches it. Both auth paths, cookie and bearer, per `.claude/api.md`.

Readings bought before an account was claimed are already attached, because the customer row is created at order time and the reading hangs off the order. So claiming an account gathers up everything that person has ever bought with no extra work, and that deserves a test rather than an assumption.

**Tests.** That a customer sees their own readings and no one else's, which is tested by the denial as much as the success. That a guest purchase appears in the account after the claim. That both auth paths work.

### 9. OpenAI, and a vector that proves it

**Blocked on decision 5 and on Jennifer's tone guidance.**

**Builds.** The OpenAI implementation of `ReadingWriter`, named in `config/reading.php` for deployed environments while local and test stay on the sample. The voice, versioned in code, with each reading recording which version wrote it. A ceiling on length. Key in Laravel Cloud, both environments.

**Tests.** The request shape against a pinned vector captured by hand from the real service, with the date it was accepted and a comment saying that regenerating it means proving the new one live first. `Http::fake` for everything else, and never a live call from the suite.

**The proof that matters is not a test.** This step is not complete until a real reading has been written on staging, read on the page, and read by Jennifer. `.claude/testing.md` exists because of what happened the last time this project accepted a green suite as evidence about somebody else's service.

**What could go wrong.** The model refuses, or writes something wrong for the brand, or writes at a length the page cannot hold. The refusal path is the failure path from step 6 and is already built by here. The other two are why a person reads the output before this ships.

### 10. What Jennifer sees

**Builds.** The reading on the order in the panel: which card was drawn, the question, the text, its state and when it was written. A rewrite action, recorded, per decision 7. A way to delete one, per decision 8.

**Read only, plus those two actions.** No writing surface, decided 12 August 2026. She writes and sends readings from her own mail, and the panel's job is to notify and record, which is what it does for every hand written reading today.

**Tests.** That the state, the card and the text persist and display. That a rewrite replaces the reading and is recorded. That nothing here can create a reading for an order that is not paid.

### 11. The contract, and telling the partner

**Builds.** `API_CONTRACT.md` gains the endpoints, the token on the order and where it comes from, **the four states and the fact that the page can meet any of them first**, and a plain statement that a playback URL from here obeys every rule the existing ones do: thirty minutes, never cached, never stored.

The state table is the part that matters. A partner who assumes payment has registered by the time the customer lands will write a page that works in every test and fails in production, and that assumption is the natural one to make.

**What could go wrong.** The partner has never acknowledged the contract at all, which `docs/CHECKPOINT.md` has carried since M4. This adds a page they have to build to a document they have not agreed to.

---

## What this milestone does not prove

Worth writing down before rather than discovering after.

- **Nothing about the card processor.** Every path here is walked against an order settled by hand. When phase B arrives, the only new thing is where the customer is sent, and it is sent to a page that already works
- **Nothing about how good a reading is.** The tests prove one was written, stored once, and shown to the right person. Whether it is worth paying for is read by a person or it is not known
- **Nothing about load.** One writer call per purchase, and no purchases yet
