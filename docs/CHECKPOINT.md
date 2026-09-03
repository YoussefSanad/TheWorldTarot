# Checkpoint

Current state, 2 September 2026. `CLAUDE.md` holds the standing rules, this holds what is true today.

**Read this before `docs/plans/`.** Several plans there describe designs that were later reversed, and the reversals table below is the authority on which.

**The long accounts live in `docs/checkpoint-history.md`**: walk transcripts, the evidence behind each proof, the newsletter and trusted-proxy write-ups, and this file's own corrections. Reach for it when you need to know *how* something was proved rather than *that* it was.

---

## State

| | |
|---|---|
| Milestones 1 to 4 | Complete |
| M5 phase A | Built, on staging since 12 August, merged to production |
| M5 phase B | Merged 26 August, PR #37, deployed to staging. **The card walk passed there the same day** |
| Checkout shape | **Reversed 29 August to a hosted Stripe page, then given a second road the same day.** The card goes to the hosted page, the wallet keeps its own PaymentIntent. ADRs 0002 and 0003 |
| Gifting | **Designed 1 to 2 September 2026, building since 2 September.** ADRs 0004, 0005, 0006 and the frontend's 0003 carry the design; `docs/plans/gifting-build.md` carries the build order and `docs/plans/gifting-tickets.md` the sixteen units. `docs/plans/gifting-plan.md` is superseded. **The sixteen are filed**: #67 to #76 here, 70 to 75 on the frontend tracker, indexed on #55. ~~**B1 and B2 are shipped, committed and not pushed.** B3 (#69) is next and is the proving unit.~~ **B1 to B7 are shipped, 3 September 2026, all committed and none pushed**: `/docs` tracked and `gift_code` gone from `API_CONTRACT.md` (B1, B2), the `readings` table and `AskForReading` (B3), the panel's three screens (B4), the `gifts` table and what may be gifted (B5), minting at settlement and the two mails (B6), and `POST /{locale}/gifts/lookup` and `/gifts/redeem` (B7). **B7 is the join with the frontend track and F5 is now unblocked in code and not in reach**, since nothing is deployed. **B9 (#75) is the next backend unit**, the gifts screen, unblocked by B6. B8 (#74) is the querent's own mail and unblocks on B7. B10 (#76) waits for the whole backend track. Required at launch, and comes after phase B |
| M6, M7, M8 | Not started |
| Panel fulfillment | Not started, and deliberately not planned. Comes last |
| Suite | ~~1189 tests, 1185 passing, 3 skipped, measured on 31 August with #52 in the tree.~~ **1359 tests, 1356 passing, 3 skipped**, `./vendor/bin/pest`, measured 3 September 2026 with the gifting epic B1 to B7 in the tree. The three skips are `StripeWebhookVectorTest`, unchanged. ~~1 failing: `CommentIntegrityTest.php:125` hardcodes "CLAUDE.md has 14" and that file now has 15 hard rules.~~ **Fixed 2 September at `78fbc0f`**, the fixture counts fifteen. The reason it could happen stands: **`CLAUDE.md` is gitignored**, so nothing committed pins the number that test asserts against, and the suite can go red on a file no reviewer can see |
| Staging suite | `composer test:staging`, skips entirely unless configured. `tests/Staging/README.md` |
| `origin/main` | `9b5d156`, PR #50 |
| `staging` | `d0af128`, 30 August, level with `origin/staging` and **three commits ahead of `origin/main`** |
| Issue #42 | `POST /orders/status`, committed at `bdd1698`, **not yet pushed or deployed** |
| Issue #43 | `stripe_wallet` and `GET /api/v1/payment-methods`, **committed locally and not yet pushed or deployed**. It unblocks the frontend's #48 |
| Issue #44 | **Open and owed.** Prove against a real wallet on a real device that `latest_charge.billing_details.email` is populated with `emailRequired: true`. It blocks launch, and the whole wallet identity path rests on it |
| Issue #47 | **Committed and pushed, `d0af128`, 30 August.** The confirmation leaves from `noreply@theworldtarot.com` carrying no Reply-To, and the new order notification lands at `orders@theworldtarot.com` on its own `mail.notify.orders` key. `Sender::Orders` is deleted. **Two of the eight "Done when" items are not code and remain open**: a real order settled on staging arriving in a real orders@ inbox, and a reply to noreply@ bouncing or being discarded, which is Jennifer's choice and is still unmade |
| Issue #51 | **Committed and pushed, `3f74f8c`, 30 August. Not yet deployed.** Option B of #46, 30 August: the password invitation came off the reading path and onto the pass. `MarkOrderPaid::grantsAccess()` answers it once for both `fulfill()` and `tell()`, the mail says Viewing Room rather than "your account", the ten tests that settled a reading now settle a pass, and four new ones cover the reading, the pass, the two together, and the buyer who comes back later. `API_CONTRACT.md`, `docs/backend-components.md` and this file are updated |
| Issue #52 | **Committed, `c4dd186`, 31 August. Not yet pushed or deployed.** A receipt opened "Hello Card Holder Name,". Nothing on the road a customer takes asks them their name, so the name in every message was whatever Stripe collected, and `BuyerIdentity` invented one from the part of the address before the `@` when it collected none. That invention is gone, `customers.name` is nullable, and `App\Support\Greeting` opens "Hello," for the three messages that greet. **A typed placeholder is undetectable and deliberately not chased**: card authorisation carries no name check, so Stripe never verifies a typed name against the issuer. `API_CONTRACT.md` now says `name` can be null |
| Issue #53 | **Committed, not yet pushed or deployed.** The purchase confirmation is the designed HTML document. **This is the day the text-only rule was revisited**, and it was narrowed rather than dropped: `resources/views/mail/` is still text and still echoes raw, `resources/views/mail/html/` is markup and must escape, and every message must still carry a text part. `OrderPaid` renders both. The 18 frame images ship in `public/email/` behind `mail.assets.url`, replacing the designer's Netlify preview host. Product subtitle and delivery window are new `ProductKey` methods. **Three open items are copy, not code**, and all three are Jennifer's: `one-card` had no design so its subtitle is invented, the designs' 24 and 48 hour promises contradict the rush add-on's "instead of seventy-two", and the design's `<title>` implies the subject line should name the reading rather than say "Your order is confirmed" |

**A local branch ref is not a statement about what is deployed.** The local `main` in this working copy sits at `4a3fac6`, 119 commits behind `origin/main`, and reading it is how this file once reported the project's own state wrongly. **Ask `origin` after fetching.**

## What moved, 26 to 29 August

**The checkout design reversed, at the frontend's request.** `StripeCheckout` now creates a Checkout Session and answers `PaymentInstruction::redirect($session->url)`, rather than creating a PaymentIntent for a form on our own page. The frontend cannot lay out a panel around a control whose height depends on whether the visitor's device has a card in its wallet, and chose one button of a known size over a wallet sheet that saves a tap.

This reverses finding 10 of the phase B review, which had deleted `PaymentInstruction::redirect()` on 26 August as a case with no caller. It has a caller now. **`docs/adr/0002-the-redirect-case-comes-back.md` is the record**, and what did not change matters more than what did: the amount is still `$order->total_amount` passed as `price_data`, there is still no Stripe Price catalogue, settlement is still `payment_intent.succeeded` keyed by `metadata.order_id`, and `refuseAnythingButAPendingOrder` is untouched. **We do not subscribe to `checkout.session.completed`.** `adaptive_pricing` is explicitly disabled, so Stripe cannot convert the amount out from under `ReconcileOrders`.

Two consequences followed it into the schema and the contract:

- **`orders.customer_id` is nullable**, migration `2026_08_29_000000`. Stripe's page collects the buyer's email after the order exists, so there is a window where nobody owns the order. Identity is filled at settlement from `session.customer_details`, before `MarkOrderPaid` runs, so **a paid order always has a customer**. Null covers a pending order and an abandoned one
- **`POST /api/v1/orders/status`**, issue #42, committed at `bdd1698` and not yet pushed. It takes the pay token in the body rather than a path segment, because the token is a credential and a path segment lands in every access log. It answers the **PaymentIntent's status string**, not the order's status, because the frontend already maps seven Stripe statuses onto four outcomes and returning `pending` would hedge at a customer whose payment plainly succeeded. **The Checkout Session id in `success_url` is not the key to this endpoint**: it is opaque, nothing here maps one back to an order, and the frontend keeps the pay token across the redirect instead

### The wallet got its own road, 29 August

**`/pay` answers two ways now**, and which one it gives is the method the customer's button names. `stripe` is unchanged: a hosted Checkout Session, answering `PaymentInstruction::redirect()`. `stripe_wallet` is new: it creates a PaymentIntent and answers `PaymentInstruction::clientSecret()`, which is what the Express Checkout Element on the frontend's own reading page confirms against. `docs/adr/0003-the-wallet-keeps-its-own-payment-intent.md` **amends ADR 0002 rather than reversing it**: the hosted page stays and stops being the only road.

**What did not change is again the larger half.** Settlement is still `payment_intent.succeeded` keyed by `metadata.order_id`, and a wallet payment emits exactly that, so the webhook, `SettleStripePayment` and `MarkOrderPaid` were not opened. **We still do not subscribe to `checkout.session.completed`.** `ReconcileOrders` sweeps the wallet order identically, since it holds an intent id from the moment the payment starts. `POST /orders/status` does not know which road minted the intent it retrieves.

**The trap, which was the whole of the work.** `RecordStripePayment::identify()` asked `BuyerFromCheckoutSession` who paid, and **a wallet PaymentIntent has no Session**. That list comes back empty, the resolver correctly answers null, and the order is left pending on a customer who has already been charged, forever, because the sweep hits the same empty list every time. The log line for it already existed and would have fired on every wallet payment ever taken.

So identity has a second source. **`BuyerFromLatestCharge` is new and sits beside an untouched `BuyerFromCheckoutSession`**, and `identify()` tries the Session first and the charge after it. Session first leaves the hosted road bit for bit as it was. The charge is read with `expand: latest_charge`, because an unexpanded one is a string id carrying no billing details at all. The "left pending" line now names both sources it exhausted, since it is the line that fires if the assumption below turns out to be wrong.

**The assumption, and it is not proved anywhere in this repository.** That a real wallet populates `latest_charge.billing_details.email` when the element is mounted with `emailRequired: true`. A substituted `StripeClient` agrees with whatever we wrote. **Issue #44 owns that proof, it needs a real device, and it blocks launch.** If the email turns out not to arrive, ADR 0003 records why server-side confirmation through a ConfirmationToken was not taken first.

Three smaller things went with it:

- **`GET /api/v1/payment-methods`**, top-level beside `/currencies`, answering `{ "methods": [...] }`. It reads a new `PaymentMethods::offeredToCustomers()`, which is `offered()` less `manual`: hand settlement **is** offered, to a person in the panel, and is not a button. `PayOrderRequest` still validates against the whole registry
- **The guard is shared rather than copied.** `refuseAnythingButAPendingOrder` left `StripeCheckout` and became the invokable `App\Payments\RefuseAnythingButAPendingOrder`, which both gateways call. A base class was declined: it would have pulled the `StripeClient` constructor into a parent and made two genuinely different roads look alike
- **`payment_method_types: ['card']`, not `automatic_payment_methods`.** Apple Pay and Google Pay are card payment methods, so the element loses nothing, and the set cannot then grow from a Dashboard nobody is watching. It is also what makes ADR 0003's `return_url` paragraph literally true

**`tests/Staging/WalletPaymentWalkTest.php` lands with it**, gated on `STAGING_WALLET=1`, confirming a real test-mode intent over raw HTTP. It proves the intent, the confirmation and the settlement. **It proves nothing about identity** and says so: no headless client opens a wallet sheet, so its order is placed signed in and settlement never asks who paid.

## Built is not proved

**Payment is proved on staging, since 26 August 2026.** The card walk ran and passed: an order placed, a card confirmed at Stripe the way a browser confirms one, and the pass appearing by itself inside two minutes with nobody in the panel. The sweep leaves an order alone for fifteen, so that can only have been the webhook, which makes it the only evidence anywhere that the endpoint is registered, reachable and carrying a matching secret. The account of it, and the three failures it took to get there, is in `tests/Staging/README.md`.

**On staging. Production has still never been walked by anybody**, and that is the gate that means something.

**The walk that passed used the PaymentIntent shape, which is no longer the shape.** The reversal of 29 August changed the front of the funnel and deliberately did not touch settlement, so the webhook half of that proof stands. The half that does not is the half a customer touches: **nobody has been sent to a hosted Stripe page and come back.**

What is still outstanding:

- **The captured Stripe vector does not exist.** `StripeWebhookVectorTest` skips, loudly and counted. Procedure in `tests/Fixtures/stripe/README.md`. Its urgency dropped on 26 August: it was the only authority on Stripe's signature format while nothing had verified a real one, and a live webhook has now been accepted end to end, which is strictly stronger evidence of the same thing. What the vector still buys is a **regression pin that runs in CI with no network**, so a change to our verification cannot go green while breaking against real Stripe signatures. Worth capturing, no longer the thing blocking a launch. **Still do not fabricate it**
- **A browser on the real frontend origin**, which no automated test can reach: `SameSite`, third party cookie blocking, `credentials: "include"`, and now the round trip to Stripe's page and back to `success_url`
- **Production has never been walked**

`docs/reviews/phase-b-human-tasks.md` is the ordered list of what a person has to get and where to put it. Items 1 and 2 of it are done.

## What is next

1. **Push `POST /orders/status`**, issue #42, committed locally at `bdd1698` and sitting one commit ahead of `origin/staging`. The confirmation screen cannot be built without it
2. **Deploy the redirect reversal to staging and walk it.** The card walk passed against a shape that no longer exists at the front. A customer going to Stripe's page, paying, and landing back on `success_url` with a resolvable status is a different path and has never been travelled
3. **Deploy the wallet road to staging and walk it**, `STAGING_WALLET=1`. Unlike the hosted road it can be walked from a terminal, because it answers a secret a raw HTTP client can confirm, so this is the cheapest proof available that a deployed environment takes a payment at all. **Then close #44 with a real device**, which is the only thing that can say whether a wallet's email reaches the charge
4. **Deploy phase B to production, and walk it there.** Staging proves the code and proves nothing about the environment that takes real money. Production needs its own Stripe keys, its own webhook endpoint registered at Stripe with its own `whsec_`, and its own walk. **`STRIPE_SECRET` is the variable name**, not `STRIPE_SECRET_KEY`, which cost a run on staging
5. **Settle what closes M5 commercially.** Payment is tied to milestone completion and M5 was split in two after that was agreed. Phase B's six steps are built and the walk has passed, so this is now answerable
6. **Approve the CMS fulfillment plan, or argue with it.** 25 August. Section 0.2 wants a round before building. **Gifting has left this item**: its 25 August plan is superseded, and the design it was waiting on is now in ADRs 0004 to 0006
7. **One message to Jennifer**, three asks left: whether she is VAT registered and where, prices in all three currencies, and a design without the 24 hour rush. The Stripe invite has landed
8. **Gifting**, which is required at launch. **The design is settled and those four questions are answered**, so what is left is filing the epic's tickets and building them. The accountant's question was VAT, which does not arise while Stripe Tax is off and is carried as an open item in `CLAUDE.md` section 8
9. **The browser proof**, which waits on the frontend having pages

Smaller, and none of it blocking: capture the Stripe vector as a CI regression pin, and `payments:check` if it is wanted.

## Gifting, redesigned 1 to 2 September 2026

Decided 17 to 19 August, reshaped 25 August, and **redesigned 1 to 2 September 2026 while planning issue #54**. Nothing is built.

**The design is not restated here, deliberately.** This section used to hold a second copy of it, and that copy is exactly what went stale: for a day after ADR 0004 withdrew the payment-method design, this file was still telling every agent that the withdrawn version was settled and must not be re-litigated. The ADRs are the record and they are what to build from.

| Document | Carries |
|---|---|
| `docs/adr/0004-a-reading-is-a-row-of-its-own.md` | The `readings` and `gifts` tables, why not a second order, what leaves `MarkOrderPaid` |
| `docs/adr/0005-gift-codes-do-not-expire.md` | No expiry, and why no speculative `expires_at` |
| `docs/adr/0006-one-screen-per-question.md` | Orders, Readings and Gifts, one question per screen |
| frontend `docs/adr/0003-redemption-is-a-page-of-its-own.md` | `/redeem/`, and lookup kept separate from redeem |

**The build order is `docs/plans/gifting-build.md`**, which carries the sixteen units, the gate on each one, and the single join between the backend and frontend tracks. `docs/plans/gifting-tickets.md` scopes each unit. Neither restates the ADRs, and neither is approved: section 0.2 wants a round on the build document before anybody starts.

`docs/plans/gifting-plan.md` is **superseded** and carries a header naming what in it is now wrong. Read it for the reasoning nothing else holds, not for the shape.

**What this file recorded as settled, and what then reversed:**

- ~~**Redemption is a payment method, not a discount.** The recipient does not get a line zeroed, they get a fourth way to pay. `GiftCodeRedemption implements PaymentGateway`, consumes the code under a row lock, and settles through `MarkOrderPaid` like everything else.~~ **Withdrawn 1 September 2026**, before `gift_code` ever acquired a caller. Redemption collects nothing, so there is no payment for a gateway to make and no second order for one to settle. Redemption mints a **reading** instead. ADR 0004 names the four places that still promise the method, each to be corrected when this is built

**The substance of the redesign**, and it is why the reversal above was not a small correction: **a reading is a row of its own**, existing from the moment somebody asks and never before. A gift bought in September and redeemed in December pulls "the money arrived" and "somebody asked for a reading" three months apart, and one row cannot hold "paid, and not yet asked". ADR 0004 argues it; ADR 0006 follows it into the panel, splitting one screen into Orders, Readings and Gifts so that no screen answers two questions.

**What was not re-opened, and still holds:**

- **A gift code names a product. Not an amount, and not a percentage.** The cross-border reasoning below is unchanged and is the reason the shape works
- **Gifting is a flag on the order line, not a product.** `ProductKey` is also the URL, so four gift products would mean four addresses for pages that do not exist, plus four rows duplicating three prices each that Jennifer must keep in step by hand
- **A code is a bearer instrument.** Whoever holds it spends it, and gives their own email at redemption. Binding it to the recipient's address was declined because its failure mode is somebody who was given a present and cannot open it
- **One code covers one line, at quantity one**
- **A withdrawn product still redeems.** The person paid for it. Redemption reads the product by id and ignores `is_published`, which is the one place in this system that does
- **No expiry**, matching the pass. ADR 0005 now carries this, along with the indefinite liability it knowingly accepts and the Gifts screen that exists to make it visible
- **The recipient is told immediately, on payment.** What changed is only what the mail links to, which is `/redeem/` rather than the reading's own page

**The three questions this section left open are answered**, all on 2 September 2026:

- **A recipient's address bouncing**, since the buyer typed it: a **confirm-email** field at checkout, because the buyer never receives the code and a typo is otherwise unrecoverable, plus **Resend** as a row action on the Gifts screen
- **Whether a gift and a normal purchase may share one order**: foreclosed by the UI, which allows one product or one gift per order, and left possible by the schema
- **Scheduled delivery**: still not built. It needs a due column and a scheduled sweep, `CLAUDE.md` caps managed queue delays at 15 minutes, and the platform's scheduler tick is carried as unverified

**One-card, the Viewing Room pass and the rush are not giftable**, decided 2 September 2026. `ProductKey::isGiftable()` sits beside `allowsQuestion()`, and the rule is enforced rather than trusted.

**Why a product rather than money or a percentage**, because two other answers were held first. Cross-border gifting is what moved it: money is denominated in one currency and nothing here converts, so a pound code cannot be spent by somebody being priced in dollars. A percentage travels across currencies but does not know what it was bought for, so a code minted for a ten pound reading can be spent on a twenty pound one and the difference is lost. **Naming the product answers both by leaning on a decision this system already made**: every product carries a fixed price in every currency, set by hand and never derived from a rate.

## Decisions that reversed earlier ones

Anything in `docs/plans/` predating these is stale. Do not re-litigate without an instruction.

| Earlier | Now | Why |
|---|---|---|
| Polymorphic `prices` table | Three `NOT NULL` columns on `products` | Nothing may exist without a price in every currency |
| A table per sellable type | One `products` table with a `type` enum | Seven rows for the life of the site |
| Translated slugs, `slug_history` | A fixed `ProductKey` enum that is also the URL | A link the client cannot break. SEO cost accepted |
| `config/localization.php` deciding languages | The `locales` table alone | Taking a language down is a status change, not a deploy |
| A manual readiness flag | Completeness calculated from the data | A stored flag is stale the moment its content is edited |
| `?locale=es` | `/api/v1/es/products` | A path segment is an address, so a non-public language 404s rather than falling back |
| Editable page copy as a `pages` table | Config-declared blocks, M6 | A textarea cannot express a designed page |
| A card slug | The card's id | A card is a pop-up, not a page |
| The homepage reveal as client-side only | `GET /{locale}/cards/draw`, card and signed film | Public and session-limited by design. Behind `FilmSource` so what plays is swappable |
| Playback URLs bound to the viewer's IP | No IP in the signature, thirty minute life | **Never worked once.** The zone is IPv4 only and the API is dual-stack, so every IPv6 visitor was refused every film |
| Save the video locally, forward it | Upload to a bucket, job reads from it | Managed queue workers are a different container |
| One video column per card | A live one and an incoming one | A replacement would empty the card while Bunny transcodes |
| A poster frame gating playback | The card's own picture, no poster | Dropped 7 August. It was 404ing the draw on staging |
| Removing a video clears the master path | Clears every column and throws film and master away | The panel said "Ready to watch" about a card with nothing to watch |
| M5 as one milestone, starting with Stripe | Split in two, second half built first | Fulfillment and the whole Viewing Room path were sitting behind payment, unbuilt. **Phase A completing is not M5 completing**, in both directions |
| Money collected, then fulfillment | An interface at "an order becomes paid" | The honest seam. Upstream is a processor's business, downstream is ours |
| `customers.password` is `NOT NULL` | Nullable | A buyer with no account gets one that waits, unclaimed. **An account with no password cannot be signed in to by any route** |
| A claim link at settlement to every unclaimed buyer | **Only to somebody who bought the Viewing Room pass, 30 August** | The claim mail is the only reason a reading buyer ever hears the word "account", and Jennifer read it as a signup. The password exists for the films, so the invitation moves to the purchase it is actually for. The unclaimed row is still written, it just stays silent. **Built: #51.** Reasoning and the two options declined are in #46 |
| The confirmation comes from `orders@theworldtarot.com` and invites a reply to it | **From `noreply@theworldtarot.com`, no Reply-To, no invitation, 30 August** | Jennifer asked that a confirmation not invite a reply, and a customer reads the sender before they read anything else. orders@ became the mailbox the internal new order notification is delivered **to**, so `Sender::Orders` is deleted rather than kept for an address nothing sends from, and the two `mail.notify` keys were split so a chargeback did not move with it. **The invitation was removed with no replacement**, knowing #47 argued for one: the obvious candidate was the site's `/contact`, which the footer nav lists and no route serves, so it would have sent a paying customer to a 404. #47 |
| `orders.customer_id` is `NOT NULL` | **Nullable, 29 August** | A hosted checkout collects the email after the order exists. Identity is filled at settlement, so a **paid** order still always has one |
| Stripe via Cashier | `stripe/stripe-php` behind `PaymentGateway` | Cashier is built for subscriptions. Phase A already built the abstraction it would have been. Cost accepted: signature verification is ours, and hard rule 6 rests on it |
| A hosted Stripe checkout page | Checkout on our own page, wallets and card alike | No Stripe parameter can pre-select a wallet on a hosted page, and Jennifer's design put the buttons on the reading page |
| Checkout on our own page | **A hosted Stripe page again, 29 August** | **Reversed at the frontend's request.** It cannot lay out a panel around a control whose height depends on the visitor's wallet. Every clause about Stripe stayed true; the clause about the design did not. ADR 0002 |
| A hosted page for every button | **A hosted page for the card and an element for the wallet, 29 August** | **An amendment rather than a reversal**, at the client's request the same day. ADR 0002 argued a wallet sheet cannot be the only control on the panel, which was never an argument that a wallet button is unwelcome. Two methods rather than one, because a Session and a PaymentIntent are not reconcilable. ADR 0003 |
| One buyer identity resolver | **Two, Session first and then the charge** | A wallet PaymentIntent has no Checkout Session, so the one resolver would have left every wallet payment pending on a customer who had been charged |
| `checkout.session.completed` | `payment_intent.succeeded`, order id in `metadata` | A PaymentIntent has no `client_reference_id`. **Unchanged by the redirect reversal**, deliberately |
| Stripe Tax on | **Off, and no tax column** | It collects only where a registration is declared. Correct while Jennifer is not registered. Prices stay tax inclusive, trivially true at zero |
| Refunds with a pass-revoking action | **No refunds** | Client decision. **Residual**: a dashboard refund is invisible here and a granted pass stays granted |
| One gateway per environment | A registry of methods this environment offers | A gateway is chosen per payment, not per environment |
| Gift codes as phase B steps 7 and 8 | Their own milestone, required at launch | Redemption is where an order becomes paid with no money involved, so it wants a proved payment flow underneath |
| Gifting a reading is a product | **A flag on the order line** | `ProductKey` is also the URL, so four products means four addresses for pages that do not exist |
| A gift code zeroes a line | ~~**Redemption is a fourth payment method**~~ | ~~It then inherits the row lock and single transition `MarkOrderPaid` has had since phase A~~ **Itself reversed 1 September 2026, see the next row** |
| Redemption is a fourth payment method | **Redemption takes no payment and mints a reading, 1 September 2026** | Redemption collects nothing, so a gateway would be settling a payment that does not happen. `gift_code` is withdrawn before it ever acquired a caller. ADR 0004 |
| The question lives on the order line, and a paid line is work owed | **A reading is a row of its own, 1 September 2026** | A gift pulls "the money arrived" and "somebody asked" months apart, and one row cannot hold "paid, and not yet asked". `order_lines.question` stays as what the buyer typed and stops being the question of record. ADR 0004 |
| The orders table is Jennifer's working list | **Readings is, 2 September 2026** | One screen cannot say that the money came in, no work is owed, and something is still outstanding, which is what a gift means for three months. `scopeAwaitingFulfillment` would otherwise have grown a clause about gifts that `needsAPerson` must agree with forever by hand. ADR 0006 |
| A gift code holds money | **It names a product** | Money is denominated in one currency and nothing converts. Every product already has a fixed price in every currency |
| The 24 hour rush | Dropped by the client | **Her design still shows it** and the partner is building from that design |
| M7's `readings` table holds one card | A `reading_cards` pivot | Three of the four products draw more than one card |
| Email verification | Not built, deliberately | Password reset already proves somebody holds the mailbox |

## Traps already paid for

Do not rediscover these. The ones that cost the most come first.

**Proof and evidence**

- **A test asserting an external service's format against your own implementation proves nothing.** Not a weak assertion, an assertion about the wrong thing, and it stays green through total failure. This cost five days in M4. Anything only the other side can confirm needs a vector captured from the other side and pinned
- **A proof has to travel the channel real people arrive on.** `curl` defaults to IPv4, which is how playback passed as proved on 3 August while being broken for every IPv6 visitor until the 7th
- **A skipped test reads as a passing one in the summary line.** Count the skips and know which ones are new
- **An assertion about a random value is a coin flip wearing a proof's clothes.** The pay token test asked that a random forty eight character string did not *contain* the order's id, which against a single digit id fails about half the time. It looked green for a month because a full run gives orders two digit ids. **Ask whether the assertion could fail on correct code**, and if it could, it is measuring luck
- **A test suite staying green while you delete a condition means the condition was never tested.** Products had no language-gate test at all and 715 tests passed without it. **Deleting a rule to see whether anything notices is cheap**
- **A service can have more than one door.** Bunny's embed player served every film unsigned while the CDN zone was locked. Proving one door is shut says nothing about the others
- **A `git worktree` sharing a symlinked `vendor` runs the wrong code**, so a before/after comparison silently compares one commit with itself. Copy `vendor`, re-dump the autoloader, and prove the two sides differ
- **Bunny's error pages answer HTTP 200 with the failure in the body.** Read the body, not the status
- **A local branch ref is not a statement about what is deployed.** Fetch, then ask `origin`

**Configuration and platform**

- **A blank environment variable is not an absent one, and `env()`'s default only covers absent.** Collected twice: `MAIL_FULFILLMENT_RECIPIENT=` sent Jennifer's notifications to an empty string and reported success, and `env('STRIPE_SECRET') === null` offered Stripe on every laptop that had copied `.env.example`. **`blank()`, everywhere a credential decides whether a feature exists**
- **A platform helper can turn an address into user input.** `TrustProxies` applies `'*'` by itself on this platform, which makes Symfony return the client-supplied leftmost `X-Forwarded-For`. `$request->ip()` was caller supplied in both deployed environments until 15 August. **Residual**: the `*.laravel.cloud` hostname bypasses Cloudflare and is still trusted one hop too far
- **An inline `throttle:n,1` with no prefix shares one counter per address with every other one.** Buying something spent the budget for remembering your password. Give every throttle its own prefix
- **A scheduled task runs only when the platform's invocation lands on a minute its cron expression names.** Measured 26 August 2026: this platform invokes `schedule:run` every five minutes, not every minute. That is not "everything runs at worst every five minutes", because `Event::isDue` compares the expression to the invoking minute, so `*/5` and `0 0 * * *` fire on an aligned cadence and never on an unaligned one. **Frequency lives in code and alignment lives on the platform**, and only the pair of them says what actually runs. Closed by Youssef on 26 August, who has the platform's own answer. **Production is a separate measurement and has not been started**
- **A test reading its expectation out of an env file is a test of that machine.** CI copies `.env.example`, so anything `composer setup` copies is a second environment nobody is looking at
- **The `log` mailer accepts every message, reports success and delivers nothing.** `php artisan mail:check`, with `--now` and without: the first tests the transport, the second tests the transport and the queue worker
- **Laravel Cloud publishes buckets as one JSON blob**, `LARAVEL_CLOUD_DISK_CONFIG`. There is no `AWS_BUCKET` and there are no keys
- **Two different Bunny secrets are both called "Token Authentication Key"**, on two dashboard screens, holding the same value

**Code and framework**

- **Two implementations of one rule, kept in agreement by hand, fail silently.** A query and a predicate answered "is this card on the website" separately and disagreed, which surfaced as a card in the Library that 404d when watched. **The tell is that nothing is red.** `App\Availability` states it once, `availability:check` proves it on real rows
- **A missing column is loud in SQL and silent in PHP**, and the silent half reads as zero and empties the shop. Both raise `UnpriceableCurrency` now
- **A test can encode the exact opposite of a security property and look reasonable.** When a test and a documented security decision disagree, find out which is older before touching either
- **Mail queued inside a transaction that rolled back has still been sent.** Grant inside the transaction, queue after the commit
- **A text mail view HTML-escapes with `{{ }}`, and a text body has no HTML context to escape for.** So the escaping is not a defence, it is corruption. The `&` joining a password link's two query parameters left as `&amp;`, the website read a parameter named `amp;email` and no address at all, and it refused every claim link and every reset link this application had sent since 9 August. Anybody with an apostrophe in their name was greeted as `O&#039;Reilly`. **Both tests either side of it were green**: one asserted the URL the builder returned, which was always correct, and the other asserted `token=`, which survives escaping because it is the first parameter. `{!! !!}` in `resources/views/mail/`, and `MailBodyTest` reads the view source as well as the sent body, because a body-level assertion only sees this when the fixture happens to hold a character worth escaping. **The raw echo is only safe while every message stays text, so that precondition is asserted too**, along with every view living in the one directory being scanned. Unpinned, the fix is one `html:` away from putting a customer's own name and question into a document as markup, which is worse than the defect it replaced. **Issue #53 made that `html:` real on 1 September 2026, and the precondition became a boundary instead of a ban**: `mail/` is text and must not escape, `mail/html/` is markup and must, a message may add an HTML part and may never drop its text part, and `MailBodyTest` asserts all four. The trap that replaces it is the one #53 walked into: **a value that reads correctly in the enum and wrong in the view.** `deliveryWindow()` answered `'immediately'` for the Viewing Room pass and the view completes the sentence, so a pass confirmation rendered "DELIVERY WITHIN IMMEDIATELY" under "Your Reading Is On Its Way". The guard test looped `ProductKey::readings()`, which is three quarters of the catalogue and not the quarter that broke
- **A queued job must assume it runs twice**, must carry strings rather than models, and must treat a 404 from a delete as success
- **`config('app.locale')` is written by `app()->setLocale()`.** Use `config('app.fallback_locale')` for the default language
- **Migrations reference literals only**, never enums, config keys or support classes
- **Route parameters bind in path order, not by name.** An action taking `(string $locale, string $key)` that is called without the first silently receives `en` as the key
- **A translatable attribute reads as `''` when missing**, `pluck()` on one returns raw JSON, and a file field on a translation tab is emptied by the language switcher
- **A factory state that builds translations from other attributes is order-dependent**, so `translatedInto('es')->create(['name' => ...])` translates the default name and then overwrites the column, making a record that claims to be translated and is not. Apply the state to the made model
- **The queue runs inline in tests**, so anything dispatching on save reaches a third party during setup
- **Pest reads a name that is not a class as an expected message**, so `not->toThrow(SomeInterface::class)` asserts nothing
- **`Http::assertSent` cannot read a body from a stream the code has already closed.** Assert the bytes somewhere nothing closes it
- **`expectsOutputToContain` consumes the line it matches**, so two expectations against one line silently fail the second
- **An `HttpException` with no message renders as `{"message": ""}`** once debug is off. Give a 404 a reason
- **A nullable password column quietly makes an authentication check pass.** What keeps an unclaimed account shut is a test that tries every route into one
- **Rate limiting a sign in answers 429, not the framework's 422**

**Filament**

- **A file field does not delete the file it removes.** `deleteUploadedFileUsing` is null by default, so clearing the field clears the path and leaves the object in the bucket. Every replaced master leaked one until 7 August
- **The `unique` rule counts soft deleted rows**, so the panel can refuse a value the database would accept. Cards carry a partial unique index and the form rule matches it
- **Plugin locale lists are captured when the panel registers.** Resources override `getTranslatableLocales()` so the list is read when the form is built

## How Bunny playback works

Anything here that looks arbitrary is load-bearing, and getting any one wrong produces an identical 403.

- **The token is scoped to the video's directory, not the manifest.** A file-scoped token loads `playlist.m3u8` and 403s on everything it points at, which from `curl` is indistinguishable from working
- **The viewer's IP cannot be in the hash.** The zone is IPv4 only and this API is dual-stack
- **`token_path` appears twice, encoded differently**: raw inside the hash, percent-encoded in the query
- **A playback URL cannot be opened in a browser tab**, since the zone refuses a request with no referrer. `BlockNoneReferrer` was decided off on 7 August and measured still on three times since, most recently 25 August. **Any `Referrer-Policy: no-referrer` on the frontend breaks every film**
- **The frontend may fetch a playback URL from a server**, since 7 August. Caching one is still wrong: it is a credential with a timer

## Carried, not blocking

- **The website is a coming-soon page.** Its only live call here is the newsletter signup. API integration waits for the pages
- **The newsletter collects on staging only.** Production has no `MAILCHIMP_*`. **Four test contacts are in the live audience: archive them, never permanently delete**, because Mailchimp will not re-add a permanently deleted address through any API call, ever
- **A `workers.dev` origin is allowed on staging.** Remove it once the page is served from `staging.theworldtarot.com`, since that domain is shared with every Cloudflare user and this API allows credentials
- **We can send mail and cannot receive any.** Resend verification is SPF and DKIM, which create no mailbox, so a reply to `support@` is discarded silently. Needs an MX record. `CLAUDE.md` section 8 carries it as an open item
- **A password reset does not end browser sessions on other devices.** It deletes every bearer token, and a session elsewhere survives until it expires
- **No manual QA pass exists for M3 or M4.** M5's is nearly complete, section 10's browser half is what remains
- **Jennifer owes the content**: 22 pictures, 22 videos, copy for five products, and confirmation of the card names
- **Spanish is a draft**, untranslated, 404ing over the API. That is correct
- **DRM is off** in every environment, decided 29 July. **A pass holder can download**, knowingly: signing stops people without a pass, not the person holding one
- **The Scale-to-Zero cold start is unmeasured**
- **`docs/` and `.claude/` are gitignored** for confidentiality. Committed code comments reference `.claude/testing.md`, which therefore lives on one machine only

## Where to look

| For | Read |
|---|---|
| Standing rules and hard rules | `CLAUDE.md` |
| How something was proved, and the long accounts | `docs/checkpoint-history.md` |
| Translation, schema, API, panel, testing, third parties | `.claude/*.md` |
| The frontend agreement | `API_CONTRACT.md` |
| Why checkout redirects again | `docs/adr/0002-the-redirect-case-comes-back.md` |
| Why one film has two named adapters | `docs/adr/0001-one-film-implementation-two-named-adapters.md` |
| Phase B, gifting, panel fulfillment | `docs/plans/milestone-5-phase-b-plan.md`, `gifting-plan.md`, `cms-fulfillment.md` |
| Phase B's outstanding human work | `docs/reviews/phase-b-human-tasks.md` |
| What the client sees and expects | `docs/client-context.md` |
| Manual QA pass | `QA_CHECKLIST.md` |
