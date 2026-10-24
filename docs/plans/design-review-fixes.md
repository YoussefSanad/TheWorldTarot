> **Status: approved 22 October 2026, ~~not started~~ in progress since 22 October 2026 on branch `design-review-fixes`. ~~Step 1 built, not committed.~~ As of 23 October 2026, steps 1 to 4 are built and reviewed, ~~none committed~~ committed one per step (`61157ac`, `1df70a0`, `23093e7`, `e7467f8`); ~~step 5 not started~~ ~~step 5 built 23 October 2026, awaiting review~~ step 5 committed as `8323747` on 23 October 2026. **Step 5a added 23 October 2026** (security follow-up to step 5, approved by the user), revised after critique the same day, ~~not started~~ built, reviewed and green 24 October 2026, committed and pushed as `c2bb0b0`. ~~**Step 12 gains a `CHECK` constraint, a paid order has a customer** (user decision, 23 October 2026).~~ **The `CHECK` constraint, a paid order has a customer** (user decision, 23 October 2026), **is its own step 12a since 24 October 2026** (user decision), not started. **Step 7 redesigned 24 October 2026** for the managed queue's 90 second visibility ceiling (user decision: keep the push upload, cap masters at 50 MB), not started; steps 8 to 11 stand unchanged. Progress table in the handoff brief.** Drafted 22 October 2026, revised after critique 22 October 2026, ~~not approved~~ approved by the user 22 October 2026 with open questions 1 and 2 answered (see "Open questions"). Nothing here is built. The handoff brief is `docs/plans/design-review-fixes-handoff.md`. Source findings: `docs/reviews/backend-design-review-2026-10-22.md`, items R1, R2, R3, R4, R5 and R7. The user's decisions of 22 October 2026 are taken as settled and are not reopened here, including the second round recorded under "Revision record". A refinement found by reading the code is marked **Refinement**.

# Design review fixes: retried payments, video redelivery, foreign key indexes

## Goal

Close the gap the review found between "this request" and "the previous attempt", plus one schema gap.

**Payments.**
- Stripe, through the webhook and the reconcile sweep, becomes the only road to a paid order. The panel's "Mark as paid" is removed and `MarkOrderPaid` stays the single funnel.
- A retried payment never overwrites the record of the payment that settled an order.
- A second successful Stripe payment is named (`PaidTwice`), logged, and stored durably as an attempt row in state `duplicate`, so it can be found and refunded by hand in the Stripe dashboard.
- Every payment attempt becomes a row (`order_payments`). The reconcile sweep, `POST /orders/status` and dispute notifications can then see hosted Checkout payments, whose PaymentIntent does not exist when the Session is created (R2).

**Video.**
- `SendCardVideoToBunny` finishes inside the queue's visibility timeout.
- It carries the upload it was sent for as strings, claims the card's incoming video atomically, and stands down once its upload has been replaced.
- Replacing a master mints a fresh incoming video.
- `ForgetCardVideo` refuses to delete any film a card still holds.

**Schema.** Five missing foreign key indexes are added, with a machine check that keeps the next one from going missing.

## Revision record

Earlier positions are struck through and dated, never deleted (CLAUDE.md section 0.6).

- ~~Step 11, "Every payment already started has its attempt" (backfill migration), and every step relying on it~~ **Deleted 22 October 2026.** Production has never been live and holds no real orders, so the only rows a backfill would carry are staging and local test data. Steps 17, 18 and 19 below say what happens to pre-attempt test orders instead.
- ~~Step 16, "The Orders screen shows an order that was paid twice"~~ **Deferred 22 October 2026** by the user, now in "Out of scope". The durable `duplicate` row and its `Log::error` remain.
- ~~Finding 4, "a hand settlement must clear the column"~~ and ~~step 2's hand-settlement branch (`PaidBy` optional, `charged_amount` null on a hand settlement)~~ **Withdrawn 22 October 2026.** The panel's "Mark as paid" is removed (step 3), so there is no hand road. `PaidBy` becomes required, and `charged_amount` is always written at settlement and always equals `total_amount` (CLAUDE.md section 4, "Prices are tax inclusive").
- ~~Open questions 1 to 6 of the first draft~~ **Answered 22 October 2026.** Step 16 deferred. No production data. Attempts link through `metadata.order_payment_id`: yes. The claim requires `video_status = pending`: yes. `AttemptAtStripe` joins the Stripe allowlist: yes. A second `DatabaseTruncation` file: yes, provided a green CI run of `RedeemAGiftRaceTest` comes first (it hard-asserts `pcntl` at `RedeemAGiftRaceTest.php:142`).
- ~~"No API contract change"~~ **Corrected 22 October 2026.** `API_CONTRACT.md` prose changes, with no shape changes. See "Contract changes".
- Steps are renumbered. Old to new: 1→1, 2→5, 3→6, 4→7, 5→8, 6→9 and 10, 7→11, 8→12, 9→13, 10→14, 12→16, 13→17, 14→18, 15→19, 17→20. New: 2, 3, 4 (~~conditional~~ no longer conditional, 22 October 2026) and 15.
- ~~Open question 1, whether to retire `manual`~~ **Answered yes, 22 October 2026.** Step 4 is part of the plan.
- ~~Open question 2, whether to drop the dispute lookup's fallback to the order column~~ **Answered, drop it, 22 October 2026**, because production was never live. Step 18 resolves through `order_payments` only.
- **Step 5a added 23 October 2026**, after step 5 was committed as `8323747`. Security's re-check of step 5 found that the first `identify()` to write sets an unowned order's owner while the first intent to take the row lock settles it, so two buyers paying at once could leave the order owned by the buyer whose payment is logged as `PaidTwice` (handoff brief, "Open with the user" item 2, low severity). The user approved the suggested fix: `PaidBy` carries the resolved customer and `MarkOrderPaid` fills `customer_id` inside its lock. Named 5a so no later step is renumbered, because other documents cite the numbers. This changes the `PaidBy` shape in "Shared PHP types" (old shape struck), the `Settlement` rule for a pending order, acceptance criterion 4 (amended) and adds criterion 18, and adds notes to steps 14 and 16. New open question 5.
- **Step 5a revised after critique, 23 October 2026.** One blocker (a failure test green at `8323747` for the wrong reason) and five smaller points, all accepted. See "Critique resolved", "Step 5a critique".
- **Step 12 gains a `CHECK` constraint, decided by the user 23 October 2026**: `orders_paid_has_a_customer`, `CHECK (status <> 'paid' OR customer_id IS NOT NULL)`, so "a paid order always has a customer" (CLAUDE.md section 4) is enforced by Postgres and not only by the PHP type step 5a leaves as the guard. This narrows the R8 row in "Out of scope" (this one constraint comes in, the rest of R8 stays out), adds a migration under "Database", adds acceptance criterion 19, and adds notes to step 5a and the deploy order. No backfill: a violating row stops the migration, it is not repaired by it. **Moved to step 12a, 24 October 2026**, see the next entry.
- **The `CHECK` constraint becomes step 12a, decided by the user 24 October 2026.** Its own commit, right after step 12, named 12a so nothing is renumbered. Its build items, `PaidOrderHasACustomerTest`, its risks and the paid-without-customer pre-check move there unchanged, except that its migration file is renamed to sort after step 12's (see "Database"). Step 12 is back to the attempt table alone, with struck and dated pointers. This is the split step 12's own "Two ideas in one step" note offered. Step 12a needs neither `order_payments` nor step 6; it needs step 5a. Criteria 18 and 19, the R8 row in "Out of scope", step 5a's notes, the ordering notes, "Deploy order" and "Dependencies" now name step 12a.
- ~~**Step 7: `upload_timeout` 300 < `$timeout` 360 < visibility 420**, the user having set 420 on both environments on 23 October 2026.~~ **Withdrawn 24 October 2026.** The Laravel Cloud managed queue's visibility timeout cannot exceed 90 seconds, and the user has set staging and production to 90. Step 7 was paused the same day, then redesigned with the user:
  - **Chosen (user, 24 October 2026): keep the push upload and make it fit the window.** `upload_timeout` 60 < `$timeout` 75 < visibility and local `retry_after` 90. Masters never exceed 50 MB (user), and the card video field enforces it where Jennifer uploads. See step 7.
  - **Rejected: Bunny pulls the master from a signed bucket URL.** Bunny Stream API spec 1.6.12, `POST /library/{libraryId}/videos/fetch`, answers only `{success, message, statusCode}`: no video id comes back, so the job cannot store the id before the bytes move, which is the rule CLAUDE.md section 4 rests on ("the video is created at Bunny before its bytes are sent"). The endpoint that fetched into an already created video is gone from the spec. **Do not re-propose a pull** unless Bunny's spec changes on both points.
  - **Not chosen: TUS chunked upload** (resumable, each chunk well inside the window). It was the fallback if 50 MB could not be held; kept here so it is the first thing reached for if masters ever grow.
  - Knock-on: acceptance criterion 8, step 7 (and its commit subject), "Deploy order" item 3, "Dependencies" and the close-out list's visibility row are rewritten, old text struck. New open question 6 (Livewire's own upload limit). Steps 8 to 11 stand unchanged (see the note at the head of step 8).

## Found in the code (read before approving)

1. **`ReadPaymentStatus` has the R2 bug, and customers see it.** `app/Actions/ReadPaymentStatus.php:49-51` answers `requires_payment_method` whenever `orders.stripe_payment_intent_id` is null. On the hosted road that is true until the webhook settles the order, so a customer polling after `success_url` is told nothing was collected. Fixed in step 17.
2. **`RecordStripePayment::identify()` depends on the line R1 removes.** `RecordStripePayment.php:180` reads the order column, which only holds the paying intent because lines 100-103 have just written it there. Step 5 switches it to `$payment->id`.
3. **`BuyerUnknown` recovery depends on that line too** (`RecordStripePayment.php:193-196`). Step 5 keeps a narrow write for that outcome that only fires while the order is pending. Step 14 moves it onto the attempt and step 19 removes it.
4. ~~A hand settlement must clear the column.~~ Withdrawn, see "Revision record".
5. **The test fake encodes the assumption R2 says is false.** `tests/Feature/StripeCheckoutTest.php:156-193` fakes a Session holding `payment_intent: pi_written_1`. The comment at `:195-200` says "only one can succeed", which R1 disproves. Replaced from the captured vector in step 13.
6. **The sweep would ask about abandoned hosted checkouts forever.** Orders never expire and the sweep runs every five minutes (`routes/console.php:65-67`). Step 16 marks an expired Session `expired` and sets `expires_at` to 30 minutes.
7. **`MarkOrderPaid` has two production callers today.** They are `OrdersTable.php:146` ("Mark as paid") and `RecordStripePayment.php:133`. After step 3 only the second remains. Gift redemption does not call it. Test callers: `MarkOrderPaidTest`, `AskForReadingTest`, `SettleAGiftOrderTest`, `OrderPaymentColumnsTest`, `OrderResourceTest:107,196`, `SettleAnUnownedOrderTest:226`.
8. **Refinement, approved:** the claim requires `video_status = 'pending'` (step 11). Without it, a late redelivery after promotion mints a second video and its eventual promotion deletes the live film.
9. **Promotion and sync can act on a stale model.** `Card.php:398-413` and `SyncCardVideo.php:58-60` write from in-memory attributes. Step 10 makes both compare-and-set.
10. **`Card::fresh()` ignores the soft-delete scope** (`Model::fresh` uses `newQueryWithoutScopes`). Step 8 keeps that behaviour with `Card::withTrashed()`.
11. **Removing "Mark as paid" orphans the `manual` payment method.** ~~This changes the scope of the decision, so it is open question 1.~~ **Answered 22 October 2026: retire it, step 4.**
    - `ManualSettlement::begin` (`app/Services/Payments/ManualSettlement.php:36-39`) answers `nothing_to_pay` and leaves the order pending "until a person marks it paid". After step 3, nothing can.
    - `config/payments.php:45-52` registers `manual` in every environment and says it "is never removed". The default is `env('PAYMENT_METHOD', 'manual')` (`:138`).
    - `PayOrderRequest` validates against the whole registry (CHECKPOINT:61), so anyone holding a pay token can still `POST /pay` with `method: manual` and get an order that can never be paid.
    - Step 4 retires it if approved.
12. **The staging Viewing Room walk buys its pass by hand.** `tests/Staging/ViewingRoomWalkTest.php:18-20,176-180` tells the person to "use Mark as paid". Step 2 moves it onto Stripe before step 3 removes the action. `GiftWalkTest.php:672-689` names "Mark as paid" in a comment only. No seeder, factory or artisan command relies on hand settlement: `OrderFactory::paid()` sets the status directly, and the only `MarkOrderPaid` caller in `app/Console` is via `RecordStripePayment`.
13. **`OrderPaymentColumnsTest.php:18-35` asserts hand-settlement behaviour** ("settles an order by hand with nothing written in the payment columns"). It goes with the action in step 3. Step 5 adds the replacement assertion (`charged_amount === total_amount`).
14. **Local development loses its only road to a paid order** unless a developer holds Stripe test keys and runs `stripe listen`. This follows from the decision and is recorded rather than worked around. `tests/Staging/` already uses test-mode keys.
15. **CI runs only on pull requests and pushes to `main`** (`.github/workflows/tests.yml:3-7`), so the green CI run step 11 needs means opening a pull request. The workflow installs `intl, pdo, pdo_pgsql` and does not name `pcntl`. Whether setup-php's CLI build includes it is exactly what that run shows.

## Out of scope

| Excluded | Where it belongs |
|---|---|
| R6, rate limits bypassable through the `*.laravel.cloud` origin | A separate plan before any marketing push. It is an options decision (section 0.1) |
| R8, CHECK constraints, case-insensitive email uniqueness | A schema-hardening plan. `order_payments.state` follows today's convention (plain string column, backed enum in PHP). **Narrowed 23 October 2026 by the user:** the one constraint `orders_paid_has_a_customer` is in this plan (~~step 12~~ step 12a, 24 October 2026). Every other R8 constraint, including one on the `status` values themselves, stays out |
| R9, an error reporter, failed-job alerts, `/up` | **Next round**, its own options decision. Routing `PaidTwice` to a reporter belongs there |
| **Showing duplicates in the panel** (first draft's step 16) | **Deferred 22 October 2026**, naturally alongside R9. Until then a duplicate is found with `select * from order_payments where state = 'duplicate'` and in the error log |
| R10 to R17 | As convenient, per the review's suggested order |
| A refund feature of any kind | **Never.** Duplicates are refunded by hand in the Stripe dashboard. Nothing here calls the Refunds API, subscribes to `charge.refunded`, or revokes a pass |
| A backfill of `order_payments` | **Not needed, 22 October 2026.** Production has never been live. Pre-attempt orders exist only as staging or local test data |
| Sweeping abandoned **wallet** intents forever | Existing behaviour, unchanged. Noted for R16-style cadence work |
| A shared fork harness for race tests | A refactor of its own. Step 11 copies the pattern |
| Editing `CLAUDE.md` | Close-out item for the documenter: section 4's "a refund is the only thing that can take a pass away" against "no refunds ever" |

## Acceptance criteria

1. `./vendor/bin/pest` passes after every step. Each step report states passed, failed and **skipped** counts. After step 6 the skip count falls by three (`StripeWebhookVectorTest` runs). Any other change is explained.
2. Postgres has an index whose leading column is each of `orders.customer_id`, `order_lines.order_id`, `order_lines.product_id`, `readings.order_line_id`, `readings.gift_id` and, from step 12, `order_payments.order_id` (`SELECT indexdef FROM pg_indexes WHERE tablename = ...`). A test fails, naming table and column, for any foreign key column in `public` without one. It was seen red listing exactly the first five.
3. The Orders screen offers no action that marks an order paid (`assertTableActionDoesNotExist('markAsPaid')` on a pending order). `grep -rn "markAsPaid\|Mark as paid" app/` returns nothing.
4. On an order paid by intent A, a signed `payment_intent.succeeded` for intent B gets a 200.
   - The order still holds `stripe_payment_intent_id = A` and its original `charged_amount` and `paid_at`.
   - No mail is sent. The pass grant and any gift codes are unchanged.
   - `Log::error` names the order and both intents, and from step 5a both buyers' customer ids (`owner_customer_id`, `duplicate_buyer_customer_id`).
   - From step 14, B's attempt row has `state = duplicate`.
   - `PaidTwice` arises only from two distinct succeeded Stripe intents.
5. A redelivered `payment_intent.succeeded` for the settling intent changes nothing and is reported as `AlreadySettled`.
6. Every order that becomes paid holds `charged_amount === total_amount` and a non-null `stripe_payment_intent_id` naming the intent that settled it.
7. `StripeWebhookVectorTest` runs against a payload, header and secret captured from Stripe test mode. From step 15 its captured payload carries a non-null `order_payment_id`. `tests/Fixtures/stripe/checkout-session-created.json` shows `"payment_intent": null`, with its API version recorded.
8. ~~`SendCardVideoToBunny::$timeout` is 360, above `services.bunny.upload_timeout` (300) and below `queue.connections.database.retry_after` (420), pinned by a test. `docs/deploy/laravel-cloud-setup.md` and `docs/CHECKPOINT.md` record a managed queue visibility timeout of 420 seconds on both environments, with the date.~~ **Rewritten 24 October 2026** (90 second ceiling, step 7):
   - `tests/Unit/QueueTimeoutsTest.php` passes and was seen red: `config('services.bunny.upload_timeout')` (60) < `SendCardVideoToBunny::$timeout` read by reflection (75) < `config('queue.connections.database.retry_after')` (90), and `retry_after <= 90`.
   - `CardForm::VIDEO_MAX_KILOBYTES` is at most 51200, read without building the form.
   - In `CardResourceTest`, a 51201 KB video on the card form is refused with a form error, stores no master and queues nothing; a 51200 KB video saves. The refusal says "under 50MB".
   - `docs/deploy/laravel-cloud-setup.md` and `docs/CHECKPOINT.md` record a managed queue visibility timeout of 90 seconds on staging and production, set 24 October 2026.
   - The user confirms that `BUNNY_UPLOAD_TIMEOUT` is unset, or at most 60, in the environment variables of staging and production (Laravel Cloud dashboard). Nothing in the repository can observe this.
9. A queued `SendCardVideoToBunny` serialises to a card id and a master path, and no `Card`. A job for a superseded path makes no Bunny call and writes nothing, and its `failed()` does not mark a newer upload Failed.
10. `ForgetCardVideo` never deletes a Bunny video id that any card, soft-deleted included, holds in `bunny_video_id` or `bunny_incoming_video_id` when the job runs. It logs a warning naming the id and the card instead.
11. Saving a new master over one still transcoding queues `ForgetCardVideo` for the in-flight incoming id and leaves `bunny_incoming_video_id` null, `video_status = pending` and `bunny_video_id` untouched. A "ready" callback naming the superseded video changes nothing.
12. Two processes running `SendCardVideoToBunny` for one card at once, over several rounds, each write an outcome file. Each round shows two creates, exactly one claim and one adopt-and-delete, one incoming id on the card, and a delete request for the other. With the claim reverted to check-then-act, the test goes red.
13. Each `POST /api/v1/orders/{payToken}/pay` with `stripe` or `stripe_wallet` inserts one `order_payments` row, and the PaymentIntent carries `metadata.order_payment_id` naming it. The hosted Session is created with `expires_at` 30 minutes ahead. Staging card and wallet walks still settle.
14. `php artisan orders:reconcile` settles a pending hosted order whose webhook never arrived, by retrieving the Session and its PaymentIntent. It marks an expired Session's attempt `expired` and stops asking about it. Proved against captured shapes, and on staging with the test-mode webhook endpoint disabled.
15. `POST /api/v1/orders/status` for a hosted order paid at Stripe and not yet settled answers Stripe's real status, not `requires_payment_method`.
16. A `charge.dispute.created` naming a duplicate intent sends the dispute mail naming that intent's order.
17. After step 19, `orders.stripe_payment_intent_id` is non-null only on paid orders, and equals the intent of that order's `settled` attempt.
18. From step 5a (added 23 October 2026), an order placed with no customer belongs to the buyer whose payment settled it. Observable in `SettleAnUnownedOrderTest` and `MarkOrderPaidTest`:
    - Two succeeded payments by different buyers on one unowned order leave `customer_id` naming the buyer of the intent held in `stripe_payment_intent_id`, in whichever order they reach the lock. The `PaidTwice` error line names both customer ids.
    - An order that already has a customer, pending or paid, never changes `customer_id` through settlement.
    - `select count(*) from orders where status = 'paid' and customer_id is null` is 0 after every settlement case (CLAUDE.md section 4, "a paid order always has a customer"). From ~~step 12~~ step 12a (24 October 2026) Postgres refuses the state outright (criterion 19).
    - A settlement that throws leaves the order pending with `customer_id` still null. `RecordStripePayment` writes no owner outside `MarkOrderPaid`'s lock (`grep -n "customer_id" app/Actions/RecordStripePayment.php` shows reads only).
19. From ~~step 12~~ step 12a (added 23 October 2026, user decision; its own step since 24 October 2026), Postgres refuses a paid order with no customer. Observable three ways:
    - `SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'orders_paid_has_a_customer'` returns one row on local, staging and production after deploy. Postgres re-renders the definition with casts, so nothing asserts its exact text; the behaviour below is the proof.
    - Inserting an order with `status = 'paid'` and `customer_id` null, or updating an unowned pending order to `paid`, throws `QueryException` with SQLSTATE `23514`, in `PaidOrderHasACustomerTest`. An unowned `pending` or `cancelled` order is still accepted. The test was seen red before the migration existed.
    - Run against a database already holding a violating row, the migration refuses with a message naming each such order id and changes nothing.

## Contract changes

### Frontend contract, `API_CONTRACT.md`

**No request or response shape changes.** Three kinds of prose change, written by the documenter in the step that causes each. The frontend partner gets one informational note after step 3 and one after step 17. **No frontend build work is required**, which is why the contract document is not edited ahead of the code: it describes behaviour this repository owns, and it changes with that behaviour.

1. **Hand settlement no longer exists** (step 3). Lines that change:
   - **Line 13**, already stale since phase B: becomes "Payments are collected through Stripe. An order is created pending and becomes paid when Stripe confirms the payment."
   - **Line 91**: struck and dated. There is no hand settlement.
   - **Line 445**: "until a payment succeeds ~~or somebody here settles it by hand~~".
   - **Line 473**: `nothing_to_pay` is "what an order already settled answers" only. The hand-settled clause is struck and dated.
   - **Line 506**: struck and dated.
   - **Line 771**: the caller list becomes "an order that is already paid".
   - `nothing_to_pay` itself stays, because `PayOrderController.php:110-112` answers it for any non-pending order.
2. ~~**If open question 1 is answered yes**~~ **Retiring `manual`, decided 22 October 2026** (step 4): `POST /pay` with `method: manual` becomes a 422, like any unknown method. The frontend never sends it (`/payment-methods` has never listed it), and line 91 already says it is "never in this list".
3. **Hosted Session life, from 24 hours to 30 minutes** (step 16). Line 471, "A Session expires after 24 hours", becomes 30 minutes. The frontend's behaviour does not change, because it already calls `/pay` again for a fresh Session.
4. `/orders/status` (step 17) keeps its contract. It now behaves as line 503-505 already describes on the hosted road.
5. **Step 5a: no change, prose or shape** (checked 23 October 2026). `customer_id` is filled at the same moment as before, the settling save, rather than a few milliseconds earlier. `/pay` and `/orders/status` already treat an unowned order as the pay token's (`PayOrderController.php:76-99`, `OrderPaymentStatusController.php:72-90`), so the one observable difference, an order whose settlement threw staying unowned, falls inside behaviour the contract already describes.

### Stripe (outbound, ours to define)

From step 13:

```
PaymentIntent metadata.order_id          string   unchanged
PaymentIntent metadata.order_payment_id  string   new: order_payments.id of the attempt that created it
Checkout Session expires_at              integer  new, step 16: now + 1800 seconds (Stripe's minimum)
```

On the hosted road the metadata travels through `payment_intent_data.metadata`, as `order_id` already does.

### Database

Step 1, `database/migrations/2026_10_22_000000_every_foreign_key_has_an_index.php`, additive:

```
orders_customer_id_index          ON orders (customer_id)
order_lines_order_id_index        ON order_lines (order_id)
order_lines_product_id_index      ON order_lines (product_id)
readings_order_line_id_index      ON readings (order_line_id)
readings_gift_id_index            ON readings (gift_id)
```

~~Step 12, first migration (added 23 October 2026, user decision), `database/migrations/2026_10_22_005000_a_paid_order_has_a_customer.php`:~~ **Step 12a** (added 23 October 2026, user decision; its own step since 24 October 2026), `database/migrations/2026_10_22_015000_a_paid_order_has_a_customer.php`. **Renamed 24 October 2026** from `..._005000_...` so it sorts after step 12's migration, which it now follows, and before step 19's. If the user moves step 12a ahead of step 12, the `005000` name is right again.

```
ALTER TABLE orders ADD CONSTRAINT orders_paid_has_a_customer
  CHECK (status <> 'paid' OR customer_id IS NOT NULL)
```

- `orders.status` is a plain `varchar` (`2026_08_09_000000_create_orders_tables.php:33`) holding the `OrderStatus` backed values `pending`, `paid` and `cancelled` (`app/Enums/OrderStatus.php:19-25`). The literal `'paid'` is `OrderStatus::Paid->value`, interpolated from the enum in the migration rather than typed twice.
- `up()` first runs `SELECT id FROM orders WHERE status = 'paid' AND customer_id IS NULL`. Any row found makes it throw a `RuntimeException` naming every id and saying "repair these by hand, this migration does not backfill", before the `ALTER`. No row is changed. Postgres would refuse the `ALTER` anyway (`23514`); the pre-check exists so the deploy log names the orders.
- `down()`: `ALTER TABLE orders DROP CONSTRAINT orders_paid_has_a_customer`.
- Kept in its own file, not the `order_payments` one, so a refusal here does not also roll back the attempt table, and the two round-trip separately. Since 24 October 2026 it is also its own step and commit.

Step 12, ~~second migration~~ (the only migration since 24 October 2026), `database/migrations/2026_10_22_010000_a_payment_attempt_is_a_row.php`:

```
order_payments
  id                          bigint       primary key
  order_id                    bigint       not null, FK orders(id) ON DELETE RESTRICT, indexed
  stripe_checkout_session_id  varchar      null, unique
  stripe_payment_intent_id    varchar      null, unique
  amount                      integer      unsigned, not null, minor units
  currency                    varchar(3)   not null
  state                       varchar      not null, default 'started'
  created_at, updated_at      timestamp
```

`state` values:
- `started`: begun, or succeeded but refused as `BuyerUnknown` or `AmountMismatch`
- `settled`: its intent settled the order
- `duplicate`: its intent succeeded after another intent had settled the order (`PaidTwice`)
- `expired`: a Session Stripe reports expired with no successful intent

A row may hold neither id: between its insert and Stripe's answer, or permanently when Stripe's create call failed or timed out (step 13). Nothing sweeps or reads such a row unless a later webhook links to it through `metadata.order_payment_id`.

Step 19, `database/migrations/2026_10_22_020000_an_order_names_only_the_payment_that_settled_it.php`, data only: `UPDATE orders SET stripe_payment_intent_id = NULL WHERE status = 'pending'`. Production has never been live, so this touches staging and local test data only. `down()` is a no-op.

~~`2026_10_22_020000_every_payment_already_started_has_its_attempt.php`, the backfill~~ **Deleted 22 October 2026**, see "Revision record".

Migration dates are the day each is built, keeping this order.

### Shared PHP types (internal)

```php
// app/Payments/PaidBy.php, step 5a (changed 23 October 2026). All three REQUIRED.
final class PaidBy {
    public function __construct(
        public readonly string $intentId,
        public readonly int $amount,
        public readonly int $customerId,   // who this payment is attributed to if it settles:
                                           // the order's owner when it had one when read,
                                           // else the buyer Stripe named for this intent
    ) {}
}
//   ~~step 5: __construct(public readonly string $intentId, public readonly int $amount)~~
//   superseded 23 October 2026 by step 5a. Pending open question 5, option B would make it ?int $customerId.

// app/Actions/RecordStripePayment.php, step 5a: private identify(Order, StripePayment): ?int
//   null means BuyerUnknown. It writes nothing to the order.
//   was: identify(Order, StripePayment): bool, which wrote customer_id outside the lock

// app/Enums/Settlement.php, step 5. Not backed.
enum Settlement { case Settled; case AlreadyPaid; case PaidTwice; case Gone; }

// app/Actions/MarkOrderPaid.php, step 5. PaidBy is REQUIRED.
public function __invoke(Order $order, PaidBy $paidBy): Settlement
//   was: __invoke(Order $order): bool
//   ~~__invoke(Order $order, ?PaidBy $paidBy = null)~~ first draft, withdrawn 22 October 2026

// app/Enums/Recording.php, step 5: new case PaidTwice, fault() === true

// app/Payments/StripePayment.php, step 14: new property
public readonly ?int $attemptId   // metadata.order_payment_id, null when absent or unreadable

// app/Enums/PaymentAttemptState.php, step 12
enum PaymentAttemptState: string { case Started = 'started'; case Settled = 'settled';
                                   case Duplicate = 'duplicate'; case Expired = 'expired'; }

// app/Models/OrderPayment.php and Order::payments(): HasMany, step 12

// app/Jobs/SendCardVideoToBunny.php, step 8
public function __construct(public readonly int $cardId, public readonly string $masterPath) {}
//   was: __construct(public Card $card)
```

`Settlement` rules, inside `MarkOrderPaid`'s row lock:

- **Row gone**: `Gone`.
- ~~**Pending, with a customer**: one save writes `status = paid`, `paid_at`, `charged_amount = $paidBy->amount` and `stripe_payment_intent_id = $paidBy->intentId`. Answers `Settled`.~~ (An unowned pending order threw `OrderBelongsToNobody`, which this list did not say.) **Replaced 23 October 2026 by step 5a:**
- **Pending**: one save writes `status = paid`, `paid_at`, `charged_amount = $paidBy->amount`, `stripe_payment_intent_id = $paidBy->intentId` and, **only when `customer_id` is null**, `customer_id = $paidBy->customerId`. An existing owner is never changed. Answers `Settled`. (Under open question 5 option B, a null `customerId` on an unowned order still throws `OrderBelongsToNobody`.)
- **Not pending, same intent** (`$paidBy->intentId === stripe_payment_intent_id`): `AlreadyPaid`. Nothing is written, owner included.
- **Not pending, different intent**: `PaidTwice`, and nothing is written, owner included.

With no hand road, a non-pending order's stored intent is always the intent that settled it, so `PaidTwice` means exactly two distinct succeeded Stripe intents (critique B1). `cancelled` is written by nothing. Hand-settled orders made on staging before step 3 hold a null intent and would read as `PaidTwice`. That is test data only, and the docblock says so.

## Steps

**Ordering.**
- Steps 1 to 5 are cheap, need no schema, and come first. The hand road goes before the R1 guard, so the guard has no hand branch. Step 5a (added 23 October 2026) follows step 5, also with no schema, and nothing before step 14 depends on it.
- Step 6 is the user's capture and can start at once.
- The video steps (7 to 11) do not wait for it. **24 October 2026:** step 7 is redesigned for the 90 second visibility ceiling; the order 7, 8, 9, 10, 11 is unchanged.
- The payment-table steps (12 onwards) start once step 6 is done. **Except step 12a** (24 October 2026): it touches only `orders`, so it waits for step 5a and nothing else. It sits after step 12 by the user's decision.
- ~~Step 4 is conditional on open question 1, and nothing after it depends on it.~~ **22 October 2026:** step 4 is part of the plan. Nothing after it depends on it.

Everything lives in `theworldtarot-backend`. Steps 6 and 15 are performed by the user against Stripe test mode and committed as fixtures here.

---

### Step 1. Every foreign key has an index (R7)

**Built.** The step 1 migration. `tests/Feature/ForeignKeysAreIndexedTest.php` queries `pg_constraint` (`contype = 'f'`) joined to `pg_index` and fails, listing `table.column`, for any foreign key column in `public` without an index whose first key column it is.

**Tests that prove it.**
- Red first, listing exactly R7's five columns. If it lists more, stop and report.
- Green after the migration.
- Denial: a temporary probe migration adding an unindexed FK makes it fail and name the column. ~~Recorded in the step report, never committed.~~ **22 October 2026:** the probe was run once and recorded in the step report, and the test file also commits self-tests that build an unindexed probe FK (plus second-column, partial, BRIN and reversed-composite cases) inside the test transaction, so the denial stays proved in every run.
- The migration rolls back and re-applies cleanly.

**What could go wrong.** `CREATE INDEX` without `CONCURRENTLY` takes a write lock. These tables hold tens of rows, and the migration comment says so, so nobody copies it onto a large table.

**Commit subject.** `Every foreign key has an index to find its rows by`

---

### Step 2. The staging Viewing Room walk buys its pass through Stripe

**Built.** `tests/Staging/ViewingRoomWalkTest.php`'s interactive purchase stops asking a person to "use Mark as paid" (`:176-180`). It pays the way `WalletPaymentWalkTest` already does headlessly:
1. `POST /orders/{payToken}/pay` with `method: stripe_wallet`.
2. Confirm the returned client secret with a test payment method and the `pk_test_` key, reusing that file's helpers and its refusal of `pk_live_`.
3. Wait for `/api/v1/me` to show the pass.

The header docblock (`:11-29`) describing hand settlement as "the product" is struck and dated. It still needs `STAGING_INTERACTIVE` or the wallet gate (`STAGING_WALLET=1`), whichever the existing gating convention calls for. `tests/Staging/README.md` gains the new invocation. Its historical record at `:132` is left as history.

**Tests that prove it.** This is the staging suite, so a person runs it: `STAGING_URL=... STAGING_WALLET=1 composer test:staging --filter=ViewingRoomWalk`. The pass appears and the film plays down to a segment. Unconfigured, it still skips with its message, and the ordinary suite is untouched.

**What could go wrong.** The webhook must be registered on staging (it is, since 26 August). The person running it must clear the rows it leaves behind, per `tests/Staging/README.md`.

**Commit subject.** `The Viewing Room walk buys its pass through Stripe, as a customer would`

---

### Step 3. Orders can no longer be marked paid in the panel

**Built.**
- `OrdersTable`: `markAsPaid()` (`:116-153`), its registration (`:110`) and any import left unused are deleted.
- Comments claiming a person settles orders in the panel are struck and dated:
  - `MarkOrderPaid.php:22-25`
  - `RecordStripePayment.php:17-21`
  - `ReadPaymentStatus.php:15-18,41-44`
  - `app/Contracts/PaymentGateway.php:20`
  - `GiftWalkTest.php:672-689`
- To find any others, grep `Mark as paid|by hand|in the panel|hand.settle` across `app/`, `config/`, `tests/`. `CommentIntegrityTest` flags comments that cite the removed method.
- `ManualSettlement`'s docblock and `config/payments.php:45-51` now describe a road that cannot finish. ~~**If open question 1 is no**, both are rewritten in this step to say so, struck and dated. If yes, step 4 deletes them.~~ **22 October 2026:** step 4 deletes them, so this step leaves them alone.

**Tests that prove it.**
- `OrderResourceTest.php:72-123` ("grants the pass when Jennifer marks a pass order paid", "does not grant twice when it is marked paid twice", "offers marking as paid only while an order is waiting for money") is replaced by one denial test: "offers no way to mark an order paid", `assertTableActionDoesNotExist('markAsPaid', record: $pending)`. Red first. The idempotence those tests proved through the panel is already proved in `MarkOrderPaidTest:130-156`.
- `OrderResourceTest.php:180-202` keeps calling `MarkOrderPaid` directly (updated for `PaidBy` in step 5).
- `OrderPaymentColumnsTest.php:18-35` ("settles an order by hand with nothing written in the payment columns") is deleted because its road no longer exists. Step 5 adds the replacement. Removing a test for a removed feature is not weakening one, and the commit message body says so.
- Full suite green.

**Proof type.** Our own panel, so feature tests.

**What could go wrong.**
- Support loses its tool for "the customer who pays some other way". That is decided, and CHECKPOINT gets a reversal row at close-out.
- Local development cannot reach a paid order without Stripe test keys (finding 14).
- A QA walk following `QA_CHECKLIST.md` section 12 fails at "Mark as paid" until the documenter rewrites it (close-out list).

**Commit subject.** `Orders can no longer be marked paid in the panel, so Stripe is the only road to paid`

---

### Step 4 (~~conditional on open question 1~~ decided 22 October 2026). The `manual` payment method is retired

~~**Built, only if approved.**~~ **Built.** The user decided on 22 October 2026 to retire it.
- Delete `App\Services\Payments\ManualSettlement`, `PaymentMethod::Manual` and its `config/payments.php` entry.
- `default` becomes `env('PAYMENT_METHOD', 'stripe')`. Decide here, and record beside the code, what an environment with no Stripe keys offers: an empty registry, with `/pay` answering the existing unknown-method refusal. `config/payments.php`'s "never removed" comment is struck and dated.
- `PaymentMethods::offeredToCustomers()` loses its `manual` filter (`:69-83`).
- `StripeBoundaryTest.php:128`'s comment mentions `ManualSettlement`.
- **The comment-claim fixtures name `ManualSettlement` as "a real class elsewhere"**: `tests/Fixtures/Claims/Unresolved/WrongCases.php:15`, `tests/Fixtures/Claims/Resolved/EveryClaimShape.php:31`, `CommentIntegrityTest.php:130-135`. They need another real class, or their expectations change meaning.

**Tests that prove it.**
- `PaymentsConfigTest`, `PaymentMethodsTest`, `PaymentMethodsEndpointTest`, `PayOrderTest:72,114` and `PlaceOrderTest:386` are rewritten around `stripe` and `stripe_wallet`, or around a test-only gateway bound in the test.
- Denial: `POST /pay` with `method: manual` answers 422.
- `WalletPaymentWalkTest:386-397`'s staging assertion becomes "manual is not a method at all".
- Red first on the 422.

**What could go wrong.** It is larger than it looks. **If review finds it is more than one idea, split it** into 4a (registry and config) and 4b (claim fixtures) before building.

**Commit subject.** `The manual payment method is retired, since nothing can complete it`

---

### Step 5. A payment for an already-paid order is PaidTwice and overwrites nothing (R1 guard, no schema)

**Built 23 October 2026, ~~not committed~~ committed as `8323747` on 23 October 2026.** Deviation (b) below is superseded by step 5a, and so is the test "an unowned order still throws `OrderBelongsToNobody`" if open question 5 is answered A. Deviations: (a) `StripeWallet::begin` and `StripeCheckout::begin` write the intent back only while the order is pending and, when that write matches no row, refuse with `OrderCannotBeCharged` rather than hand over a secret for a settled order (a deleted row is refused the same way). (b) `identify()` writes `customer_id` only while the order has no customer and is pending. (c) `/pay` answers `nothing_to_pay` when the order settles during the Stripe call, the same as for one paid before it; other refusals still throw. (d) `ReconcileOrders` prints `Order N was not settled.` without repeating the reason, which is on the detail line beneath it, because the test expects the two strings on two separate lines. The `PaidTwice` log reads the settling intent from a fresh read of the order after `MarkOrderPaid` answers; that is an implementation detail, not a deviation.

**Plan as written.**
- `PaidBy` (required) and `Settlement`. `MarkOrderPaid::__invoke(Order, PaidBy): Settlement` follows the rules in "Contract changes" inside the existing lock. `@return bool` is struck and dated.
- `RecordStripePayment`:
  - The unconditional write at `:97-103` is removed and its comment struck. The call becomes `($this->markPaid)($order, new PaidBy($payment->id, $payment->amount))`, mapping `Settled→Settled`, `AlreadyPaid→AlreadySettled`, `PaidTwice→PaidTwice` and `Gone→NotOurs`.
  - `PaidTwice` logs at error: "A second Stripe payment succeeded for an order that was already paid. Refund it by hand in Stripe.", with `order_id`, `settling_intent` and `duplicate_intent`.
  - `:118-132` ("`MarkOrderPaid` keeps its bool") is struck and dated.
  - `identify()` uses `$payment->id` (finding 2).
  - On `BuyerUnknown` only: `Order::whereKey($order->id)->where('status', 'pending')->update(['stripe_payment_intent_id' => $payment->id])` (finding 3), with a comment naming steps 14 and 19.
- `Recording::PaidTwice`, `fault()` true. `ReconcileOrders`'s `match` gets the arm `'Refused, this order was already paid by another payment. Refund this one by hand in Stripe'`.
- `SettleStripePayment` is unchanged: `PaidTwice` returns rather than throws.
- A `paidBy(Order $order, string $intent = 'pi_test')` helper in `tests/Pest.php` builds a `PaidBy` at `total_amount` for tests that call the action directly.

**Tests that prove it.**
- Mechanical updates: `toBeTrue()`/`toBeFalse()` become `Settlement::Settled`/`AlreadyPaid`, with `paidBy($order)` passed, in `MarkOrderPaidTest`, `AskForReadingTest`, `SettleAGiftOrderTest`, `OrderResourceTest:196` and `SettleAnUnownedOrderTest:226`.
- New in `MarkOrderPaidTest`:
  - settling writes `charged_amount === total_amount` and the intent in the same save as `status = paid`. This replaces `OrderPaymentColumnsTest:18-35`.
  - the same intent again answers `AlreadyPaid` and writes nothing.
  - a different intent answers `PaidTwice`, keeps the first intent, amount and `paid_at`, sends no mail, leaves the pass grant date unchanged and mints no gift code again.
  - a deleted row answers `Gone`.
  - an unowned order still throws `OrderBelongsToNobody` and writes neither column.
- `StripeWebhookTest`, end to end: two signed events with different intents for one order. Both answer 200, the order holds the first intent, one `Log::error` names both, and one `OrderPaid` mail is sent. **This is R1.** Red first.
- `SettleAnUnownedOrderTest`: with the order column null (R2 shape) and with a different wallet intent on it, the resolvers are asked about the **paying** intent.
- `BuyerUnknown` on a hosted order leaves the paying intent on the column and `orders:reconcile` picks it up.
- `PaidTwice->fault()` is true, and the sweep prints the new arm.

**Stripe proof.** No new Stripe format. Feature tests are the proof. Re-running the staging card walk after deploy is a regression check.

**What could go wrong.** An enum case is always truthy, so any leftover `if ($changed)` is silently wrong. Grep `MarkOrderPaid` before committing. With `PaidBy` required, PHP rejects a forgotten caller outright, which is the point of making it required.

**Ships as its own deploy, with no schema step** (deploy order).

**Commit subject.** `A payment that arrives for a paid order is PaidTwice and overwrites nothing`

---

### Step 5a. An unowned order belongs to the buyer whose payment settled it (step 5 security follow-up, no schema)

**Added 23 October 2026**, after step 5 was committed as `8323747`. Named 5a so no later step is renumbered. Source: security's re-check of step 5, handoff brief "Open with the user" item 2, low severity. The user approved the suggested fix the same day. ~~**Pending open question 5** on one detail; the step is written for the recommended option A, and says what changes under B.~~ Open question 5 answered 23 October 2026: **option A**, `customerId` is a required `int`. The option B notes below are history only.

**Built 24 October 2026, ~~not yet committed~~ committed and pushed as `c2bb0b0`.** Red before implementation: 6 failing, 5 on their assertions and the unowned-settles case on the baseline `OrderBelongsToNobody` throw; the three guards green. After: full suite 1522 passed, 0 failed, 3 skipped. Reviewer approved after one fix round (comments, the `CHECKPOINT.md:44` strike, one duplicate assertion); security found nothing. No deviations. The only production `new PaidBy(` caller is `RecordStripePayment`; wallet, hosted checkout, the sweep and gift orders build none.

**The finding, as the code stands at `8323747`.** `identify()` writes `customer_id` outside the row lock (`RecordStripePayment.php:254-257`, only where null and pending), and `MarkOrderPaid` settles for whichever intent takes the lock first (`MarkOrderPaid.php:90-124`). Buyer X pays with intent A and buyer Y with intent B, on one order with no customer:
1. Both deliveries read the order unowned.
2. A's `identify()` writes X. B's resolves Y, and its write matches no row, so B carries on with the order owned by X.
3. B takes the lock first and settles: X owns an order paid for with Y's money.
4. A answers `PaidTwice`. The hand refund of intent A returns X's money, so X keeps the pass for nothing, and Y has paid and is neither served nor refunded.

**Built.**
- `PaidBy` gains a third required property, `public readonly int $customerId` (see "Shared PHP types"). Add the property first, so the new tests run and go red on their assertions rather than on a missing argument.
- `MarkOrderPaid`, inside the existing lock:
  - pending and `customer_id` null: the settling `forceFill` also writes `customer_id = $paidBy->customerId`, in the same save as `status = paid`.
  - pending and owned: the owner is kept and `$paidBy->customerId` is ignored. "An identity the order already carries is never overwritten" moves here from `identify()`'s docblock (`RecordStripePayment.php:159-162`), with its reasoning. **(Critique, 23 October 2026.)** `identify()`'s docblock keeps one sentence pointing at it, for example: "An identity the order already carries is answered as is, and it is never overwritten: `MarkOrderPaid` writes the owner only where `customer_id` is null." So a reader of either file finds the write-side rule.
  - not pending: unchanged. Nothing is written, owner included.
  - **Option A (recommended):** the `OrderBelongsToNobody` throw (`MarkOrderPaid.php:104-115`) can no longer be reached, because a `PaidBy` always names somebody. Its comment is struck and dated, the throw is deleted, `app/Payments/OrderBelongsToNobody.php` is deleted, then `composer dump-autoload`. **Option B:** `customerId` is `?int`, and the throw stays for a null `customerId` on an unowned order.
  - The `@return` docblock's `Settled` line says the owner is filled here.
- `RecordStripePayment`:
  - `identify()` returns `?int`. Its `@return bool` is struck and dated. It returns the order's `customer_id` when the order already has one, with no Stripe call (unchanged). Otherwise it resolves the buyer and `Customer::firstOrCreate`s them as today, and returns that customer's id.
  - **It writes nothing to the order.** The conditional update and `refresh()` at `:249-260` are deleted and their comment struck and dated, pointing at `MarkOrderPaid`. The comment at `:107-111` becomes "resolved before the transition, written inside it".
  - A null answer takes the `BuyerUnknown` path unchanged, narrow intent write included.
  - The call becomes `new PaidBy($payment->id, $payment->amount, $customerId)`.
  - The `PaidTwice` log gains `owner_customer_id`, the settled order's `customer_id`, and `duplicate_buyer_customer_id`, `$paidBy->customerId`. **(Critique, 23 October 2026.)** `RecordStripePayment.php:143` calls `$order->fresh()` inline inside the context array today. It becomes one assignment above the `Log::error`, `$settled = $order->fresh();`, and both `settling_intent` and `owner_customer_id` read from `$settled?->`, so the two fields describe the same read of the row. ~~taken from the same single fresh read that already supplies `settling_intent`, and~~ (wording replaced 23 October 2026: there was no single read to share, only an inline call). It logs ids, not email addresses: the Stripe dashboard shows each intent's payer. A comment says that two different ids mean two people paid for one order, and that refunding the duplicate intent now returns the money to the one without the pass.
- `tests/Pest.php`'s `paidBy()` gains `?Customer $buyer = null`. The default is the order's own customer. With neither, it throws a `LogicException` saying the test must name a buyer, so a test about an unowned order says who paid.
- **Docs in the same step** (CLAUDE.md 0.6):
  - `docs/backend-components.md:313` (`PaidBy` holds the intent, amount and customer, and the owner is filled in the settling save) and `:319`.
  - `docs/CHECKPOINT.md:44` and `:168`: "before `MarkOrderPaid` runs" is struck and dated. It now reads "resolved before `MarkOrderPaid` and written inside its lock". The invariant "a paid order always has a customer" is unchanged.
  - This plan's banner and this step's note. The handoff brief's open item 2 and progress table.
  - `OrderBelongsToNobody`'s docblock goes with the class (option A) or is rewritten to name `PaidBy` (option B).
  - `CLAUDE.md` section 4 says the same thing as CHECKPOINT:44. It is not edited here; it is on the close-out list for the user's wording.
  - `API_CONTRACT.md`: none (see "Contract changes" item 5).

**Tests that prove it.**
- `MarkOrderPaidTest`:
  - An unowned pending order settled with a `PaidBy` naming Y answers `Settled`. `customer_id` is Y, the pass is granted to Y and the `OrderPaid` receipt is queued to Y's address. Red first.
  - **Denial:** a pending order owned by X, settled with a `PaidBy` naming Y, answers `Settled` with the owner still X. The pass and the receipt go to X, and Y holds no pass.
  - **Denial:** a paid order owned by X, given a different intent naming Y, answers `PaidTwice`. The owner is still X, nothing is written, and Y holds no pass.
  - A paid order given the same intent naming Y answers `AlreadyPaid`, with the owner still X.
  - **These three are guards by design (critique, 23 October 2026).** They pass before any implementation once the property exists, because `8323747` ignores the third argument and never touches the owner. That is expected, not a section 0.5 warning sign: they pin behaviour that already holds so the new write cannot break it. Since they were never seen red, each gets the section 0.5 audit instead, recorded in the commit body:
    - In the new `MarkOrderPaid` code, drop the `customer_id === null` condition so the settling `forceFill` writes `customer_id` unconditionally. The first denial test (pending, owned by X) must go red. Restore it.
    - Move the owner write above the not-pending return. The `PaidTwice` and `AlreadyPaid` tests must go red. Restore it.
  - **The section 4 invariant:** after each case above, `Order::where('status', 'paid')->whereNull('customer_id')->doesntExist()`.
  - **Failure:** an unowned reading order with a throwing `AskForReading` bound (the pattern at `SettleAGiftOrderTest.php:380-386`) throws, stays pending and stays unowned, and no mail is queued. ~~(as first written, asserting only that it throws)~~ **Corrected after critique, 23 October 2026 (blocker):** as first written this was green at `8323747` for the wrong reason, because the baseline throws `OrderBelongsToNobody` at `MarkOrderPaid.php:113-115`, before `AskForReading` is reached, and `OrderBelongsToNobody` extends `RuntimeException` (`app/Payments/OrderBelongsToNobody.php:24`), so `toThrow(RuntimeException::class)` alone matches either. The test therefore:
    - asserts the fake's own exception by class **and** message, `toThrow(RuntimeException::class, 'the asking failed')`, which `OrderBelongsToNobody`'s message cannot match;
    - asserts the buyer named in the `PaidBy` exists as a `Customer` row (`Customer::whereKey($buyer->id)->exists()`), so the case is about a resolved buyer whose settlement failed and not about a missing one;
    - is red at `8323747` because the wrong exception is thrown, which is the behaviour this step changes.
    - **User decision, 24 October 2026:** the "Customer row exists" assertion stays in this test, even though it proves little here (the test builds the buyer itself).
- `SettleAnUnownedOrderTest`:
  - **The race, scripted** (like step 9's B2 race). Bind `BuyerFromCheckoutSession` to a fake answering X for `pi_a` and Y for `pi_b`. Bind a `MarkOrderPaid` subclass whose `__invoke`, the first time it is asked about `pi_a`, runs the whole of `RecordStripePayment` for `pi_b` before delegating to `parent::__invoke`. Then record `pi_a`. Expected:
    - `pi_b` is `Settled` and `pi_a` is `PaidTwice`.
    - The order holds `pi_b` and is owned by Y.
    - X exists as a customer with no pass.
    - One `Log::error` carries `owner_customer_id` Y and `duplicate_buyer_customer_id` X.
    - One `OrderPaid`, to Y.
    Red first: at `8323747` the order ends up owned by X.
    - **The test's comment describes the path this script exercises (critique, 23 October 2026)**, which is not quite the finding's step 2 above. At `8323747`, A's `identify()` writes X before `MarkOrderPaid` is called. B's whole `RecordStripePayment` then runs inside A's `__invoke`, reads the order, finds X already written, and returns from `identify()` with no Stripe call at all, so the resolver's Y answer for `pi_b` is never asked for. B settles with X as owner. Same end state as the finding (X owns an order paid with Y's money), reached by B skipping identification rather than by B's write matching no row. After this step A's `identify()` writes nothing, so B finds the order unowned, resolves Y and settles with Y.
    - **Binding (critique, 23 October 2026).** The subclass is bound with plain `app()->bind(MarkOrderPaid::class, fn ($app) => $app->make(...))`, not as a singleton, because `MarkOrderPaid` has constructor dependencies (`MarkOrderFulfilled`, `AskForReading`) the container should fill. B's `RecordStripePayment`, resolved inside the hook, therefore gets a fresh instance whose "already intercepted" flag is unset. That is harmless: the hook fires only for `pi_a`, B only ever asks about `pi_b`, so B's instance delegates straight to `parent::__invoke` and the flag is never consulted for it. A singleton would behave identically; plain `bind` is chosen so nothing outlives the test's container.
  - **Failure, no write outside the lock:** a payment for an unowned reading order with a throwing `AskForReading` bound leaves the order pending with `customer_id` null, ~~though the resolved `Customer` row may exist~~ **and the resolved `Customer` row exists** (asserted, critique 23 October 2026: it proves the run got past `identify()` and failed inside the settlement, rather than stopping at a buyer nobody could name). It asserts the fake's exception by class and message, as in `MarkOrderPaidTest`. Red first: at `8323747`, `identify()` wrote the owner before the lock.
  - An owned order paid twice by its owner logs the same id under both keys and makes no Stripe identity call.
  - Existing cases stay green, notably "settles an unowned order exactly once however many times it is redelivered" (`:209-225`, one customer, one receipt) and the `BuyerUnknown` recovery (`:195-207`).
- **Changed by decision under option A**, each comment struck and dated in the test:
  - `MarkOrderPaidTest.php:315-330` and `SettleAnUnownedOrderTest.php:227-236` assert `OrderBelongsToNobody`. A caller can no longer express "nobody", so each is replaced by the case where an unowned order settles to the named buyer and writes the owner, the intent and the amount in one save.
  - `SettleAGiftOrderTest.php:397-410` uses the throw as "the one failure reachable inside that transaction". It is rewritten to fail through a throwing `AskForReading` on an order that has a reading line, or it is deleted as a duplicate of the case ending at `:395`, which already fails that way. The reviewer decides which, and the commit body records it.
  - Removing a test for an exception that can no longer exist does not weaken a test.
- `StripeWebhookTest`'s R1 case and `ReconcileOrdersTest`: if either asserts the `PaidTwice` log context exactly, it gains the two keys.
- Full suite green, with passed, failed and skipped counts reported.

**Proof type.** Our own lock and our own writes, so feature tests. The race is scripted rather than forked: the bug is the order in which the lock is taken, and the script fixes that order deterministically. The row lock is Postgres's. This step only moves the owner write under it.

**Stripe proof.** No new Stripe call or format. The resolvers are unchanged.

**What could go wrong.**
- The `Customer` row `identify()` creates for a payment that loses is left with no orders. This is already accepted at `RecordStripePayment.php:243-247` and is now somewhat more likely. It is harmless, because the row is keyed by email.
- A settlement that throws after the buyer is resolved now leaves the order unowned rather than owned. That is what null means (CLAUDE.md section 4). `/pay` and `/orders/status` already treat an unowned order as the token's, and the retry resolves the buyer again at the cost of one more Stripe read.
- Under option A no runtime check is left on the customer: the type is the guard. A caller passing the wrong id is caught only by the foreign key (`orders.customer_id`, `restrictOnDelete`), which refuses an id that does not exist and nothing else. **From ~~step 12~~ step 12a (user decision, 23 October 2026; its own step since 24 October 2026)** Postgres also refuses a paid order with a null `customer_id` (`orders_paid_has_a_customer`, criterion 19), so the invariant stops resting on the PHP type alone. It still does not catch a wrong but existing id. ~~`new PaidBy(` has two callers, `RecordStripePayment` and `tests/Pest.php`.~~ **Corrected after critique, 23 October 2026:** `new PaidBy(` has three callers at `8323747`: `app/Actions/RecordStripePayment.php:135`, the `paidBy()` helper at `tests/Pest.php:298`, and `tests/Feature/MarkOrderPaidTest.php:263`, which builds `new PaidBy('pi_second', 1)` by hand for its `PaidTwice` case. That one gains the order owner's id, `new PaidBy('pi_second', 1, $order->customer_id)`, so it stays a test of a second intent and not of a different buyer. Grep before committing.
- An existing test that builds a `PaidBy` by hand with two arguments now fails with `ArgumentCountError`. That is the point of making the property required. At `8323747` that is `MarkOrderPaidTest.php:263`, above, and it is updated in the same commit as the property so the red run fails on assertions.

**Knock-on for later steps** (notes added in those steps, 23 October 2026):
- Step 14 wraps `MarkOrderPaid` and the attempt write in one transaction. The owner fill is now inside it too, so its rollback test also asserts the order is still unowned. Its fallback paths build `PaidBy` with the customer id as above.
- Step 16's "two succeeded attempts" case asserts the two buyer keys on the `PaidTwice` line.
- ~~Step 12~~ Step 12a (added 23 October 2026, user decision; its own step since 24 October 2026) adds `orders_paid_has_a_customer`, the database half of the invariant this step keeps in PHP.
- Steps 12, 13, 15, 17, 18 and 19 do not build a `PaidBy` or call `identify()`, so nothing changes in them on that account. Step 19 deletes only the `BuyerUnknown` intent write, which this step leaves alone.

**Deploy.** No schema step and no data change. It may go out in the same deploy as step 5 or any later one.

**Commit subject.** `An unowned order belongs to the buyer whose payment settled it`

---

### Step 6. Stripe's own shapes are captured and pinned (R4, performed by the user)

**Built 24 October 2026 by the lead agent at the user's request, not yet committed.** Test mode only, sandbox account `acct_1U7gKrAYP1QPDkCk` (the local `.env` account). Full suite after: 1533 passed, 0 failed, 0 skipped (the three `StripeWebhookVectorTest` skips are gone). Reviewer: one docs fix round; security: no findings (fixtures carry test-mode ids only; the paid PaymentIntent's `client_secret` is inert, the committed `whsec_` is the per-device `stripe listen` secret). Deviations: (a) the Sessions were created by a scratch script with `StripeCheckout::begin`'s parameters copied, not in tinker, with the test key passed through the environment because local `config/services.php` reads `STRIPE_SECRET` while `.env` names it `STRIPE_SECRET_KEY`; (b) the Session was paid through `/v1/payment_pages/{id}/confirm`, the call the Stripe CLI's own `checkout.session.completed` fixture makes, not in a browser; (c) the webhook body and signature were recorded by a scratch server that `stripe listen` forwarded to, verified with `Stripe\Webhook::constructEvent` and copied byte-identical, so the controller was not changed. The user confirmed 24 October 2026 that staging and production do not use this `whsec_`; (d) the account is the local sandbox, not Jennifer's or staging's, which does not affect the signature format, and the API version matched (`2026-07-29.dahlia`); (e) `StripeSessionShapeTest` compares the version file with `Stripe\Util\ApiVersion::CURRENT`, so an SDK upgrade turns it red until recaptured. The optional dispute body was captured too. Local orders 4 to 10 in the dev database are left over from the capture.

~~**Built by the user, against Stripe test mode only.**~~ (Reversed 24 October 2026: the user asked the lead to do it.) Staging test mode or a local app on test keys are equally valid. Never live mode, never a live secret. Each file goes in `tests/Fixtures/stripe/`, byte for byte, with no trailing newline added.

1. **The signed webhook**, per `tests/Fixtures/stripe/README.md`. Use a real hosted checkout order paid with `4242 4242 4242 4242`, so the payload carries a real `metadata.order_id`.
   - `payment-intent-succeeded.json`: the raw body.
   - `payment-intent-succeeded.sig`: the `Stripe-Signature` header, verbatim.
   - `payment-intent-succeeded.secret`: the test-mode `whsec_`.
   - The README's `stripe listen --forward-to localhost:8000/api/v1/webhooks/stripe` route is the easy one. Capturing from deployed staging needs a raw-body log in the controller, which this plan does not add.
2. **A Checkout Session create response, made through our own SDK**, so the API version is the one production uses. In `php artisan tinker` with test keys, call `app(\Stripe\StripeClient::class)->checkout->sessions->create([...])` with `StripeCheckout::begin`'s parameters for a real pending order.
   - Save `json_encode($session->toArray(), JSON_PRETTY_PRINT)` as `checkout-session-created.json`.
   - Save `$session->getLastResponse()->headers['Stripe-Version']` (expected `2026-07-29.dahlia`) and the date in `checkout-session-created.version`.
   - **Confirm `payment_intent` is `null`.** If it is not, stop and tell the architect. Do not use the Stripe CLI for this file, because its API version can differ from the SDK's.
3. **The same Session after payment.** Pay it, then save `->checkout->sessions->retrieve($id, ['expand' => ['payment_intent']])` as `checkout-session-paid.json`.
4. **An expired Session.** Create one, call `->checkout->sessions->expire($id)`, retrieve it with the same expand, and save it as `checkout-session-expired.json`.
5. Optional, for step 18: a `charge.dispute.created` body from test card `4000 0000 0000 0259`, saved as `charge-dispute-created.json`.

**Then (agent, same commit).**
- Record the capture date in `StripeWebhookVectorTest`'s header.
- Strike and date line 3 of `tests/Fixtures/stripe/README.md` and list the new files.
- Update `docs/CHECKPOINT.md` "Built is not proved".
- Add `tests/Feature/StripeSessionShapeTest.php`, asserting on the captured files: `payment_intent === null` at creation, and a `pi_` intent with numeric `metadata.order_id` after payment. Each file's header says it may only be regenerated by capturing again from Stripe.

**Tests that prove it.** `StripeWebhookVectorTest` runs and passes, and the skip count drops by three. `StripeSessionShapeTest` passes.

**Proof type.** Captured vectors. This step is the proof later steps cite.

**What could go wrong.** Pretty-printing the webhook body breaks its signature, so only the Session files may be pretty-printed. Check the commit for any `sk_live`, `pk_live` or live `whsec_` before pushing.

**Commit subject.** `Stripe's own signature and Session shapes are pinned as captured vectors`

---

### Step 7. SendCardVideoToBunny finishes inside the queue's visibility timeout (R3, config)

> ~~**Paused 24 October 2026.** The managed queue's visibility timeout cannot exceed 90 seconds (user, 24 October 2026; set to 90 on both environments). The ordering below assumed 420 and is no longer achievable. A redesign is being chosen with the user; do not build this step as written.~~ **Resolved 24 October 2026.** The user chose to keep the push upload and fit it inside 90 seconds, with masters capped at 50 MB. Bunny pull and TUS were considered and not chosen ("Revision record"). The step below is buildable.

~~**Built (as first planned, 22 October 2026).**~~ Withdrawn 24 October 2026, kept for the record:
- ~~`$timeout` changes from 600 to 360, with a comment giving the arithmetic.~~
- ~~`config/queue.php:43` default `retry_after` changes from 90 to 420.~~
- ~~`.env.example` gains `DB_QUEUE_RETRY_AFTER=420`.~~
- ~~`docs/deploy/laravel-cloud-setup.md`: `:161`'s 60 seconds is struck and dated, now 420 on both environments, with the date the user set it. `:174`'s "when the job exists" is struck and dated.~~
- ~~`docs/CHECKPOINT.md` gains a state row for the visibility timeout.~~
- ~~Test: `upload_timeout < timeout < config('queue.connections.database.retry_after')`.~~
- ~~What could go wrong: a worker that dies mid-`SettleStripePayment` waits 7 minutes rather than 1 before redelivery.~~

**The window, and why each number (24 October 2026).** The platform redelivers any message not finished 90 seconds after a worker received it. Everything below is arranged so that, in normal operation, the job is over, successfully or not, before then:

| | Was | Now | Where |
|---|---|---|---|
| Bunny PUT, `services.bunny.upload_timeout` default | 300 | **60** | `config/services.php:49` |
| Job, `SendCardVideoToBunny::$timeout` | 600 | **75** | `app/Jobs/SendCardVideoToBunny.php:48` |
| Local `queue.connections.database.retry_after` default | 90 | **90**, pinned | `config/queue.php:43` |
| Managed queue visibility timeout, staging and production | 60 (setup doc) | **90**, the platform maximum, set by the user 24 October 2026 | Laravel Cloud dashboard |
| Card video field, largest file | 200 MB (`maxSize(204800)`) | **50 MB** (`51200` KB) | `app/Filament/Resources/Cards/Schemas/CardForm.php:94` |

- **60 to 75, fifteen seconds for everything around the PUT.** The bucket `exists()` check, opening the read stream, the Bunny create call and two row writes. The PUT timing out at 60 throws `BunnyRequestFailed`, an ordinary failure that is retried with the job's backoff (30, then 120 seconds) and reaches `failed()` after three tries, rather than a worker kill.
- **75 to 90, fifteen seconds for the kill to finish.** At 75 Laravel's worker raises the job timeout (pcntl alarm), records the attempt and exits. The message reappears at 90, after that process is gone, so no second run overlaps the first. The margin also absorbs the gap between the platform starting its clock at receipt and the worker starting the job's clock after decoding the payload.
- **Local `retry_after` 90 equals the platform ceiling on purpose**, so the database driver locally redelivers on the same clock as staging and production. It is already 90 at `config/queue.php:43`; this step changes no value there, it adds a comment saying why 90 and that it must not rise above the platform's maximum, and the test pins it.
- **50 MB in 60 seconds needs about 7 Mbps** sustained from the worker to Bunny (50 MB is 400 megabits; 400 / 60 is 6.7). The master streams from the bucket into the PUT, so the bucket read must keep up too. Masters ran 20 to 40 MB when this was written (`config/services.php:47`), and the user says they will never exceed 50 MB.

**Built.**
- `config/services.php:49`: `(int) env('BUNNY_UPLOAD_TIMEOUT', 60)`, from 300. The comment above it (`:47-48`, "A master runs 20 to 40MB") is struck and dated and rewritten: the card form caps a master at 50 MB, 50 MB in 60 seconds is about 7 Mbps, and the number sits fifteen seconds under `SendCardVideoToBunny::$timeout`, which sits fifteen under the queue's 90, citing `QueueTimeoutsTest`.
- `app/Jobs/SendCardVideoToBunny.php:48`: `$timeout = 75`, from 600. The docblock at `:41-47` ("Long enough for a 40MB upload on a poor connection", and "If a real upload ever exceeds this, the queue redelivers the job while the first one is still uploading") is struck and dated. It now says: the queue redelivers anything unfinished at 90 seconds and 90 is the platform's maximum; 75 lets a timed-out run be killed before that; the PUT gets 60 of the 75; a master is at most 50 MB because the card form refuses more. The class docblock's "may redeliver a job that is still running" (`:17-19`) stays true of an at-least-once queue and is kept, with one dated sentence that in normal operation the run is over before redelivery, and the create-before-upload rule remains for the abnormal case.
- `config/queue.php:43`: value unchanged at `(int) env('DB_QUEUE_RETRY_AFTER', 90)`, a comment added as above. `.env.example` gains nothing (the 22 October `DB_QUEUE_RETRY_AFTER=420` line is withdrawn).
- `app/Filament/Resources/Cards/Schemas/CardForm.php`:
  - `public const VIDEO_MAX_KILOBYTES = 51200;` with a comment: the upload job has 60 seconds to send the file to Bunny (`config/services.php`), and 50 MB is what fits; raising this means revisiting the queue arithmetic or moving to TUS ("Revision record", 24 October 2026).
  - `:94` `->maxSize(self::VIDEO_MAX_KILOBYTES)`, from `204800`.
  - `:97` the message becomes `'That file is too large. Videos should be under 50MB.'`. The old "under 200MB" goes, since it is copy and not a decision; the decision is in the constant's comment and here.
  - `:89` the helper text gains "Up to 50MB." at its start, so she learns the limit before choosing a file rather than after.
- `docs/deploy/laravel-cloud-setup.md`:
  - `:161` "visibility timeout at 60 seconds" is struck and dated: **90 seconds on staging and production, set by the user 24 October 2026, the platform's maximum.**
  - `:174` "Set it above the worst case for that queue when the job exists" is struck and dated: 90 is the ceiling, so a slow job is made to fit inside it (`SendCardVideoToBunny`: 75 second timeout, 60 second upload, 50 MB masters) rather than the timeout raised to fit the job.
- `docs/CHECKPOINT.md` "State" table gains a row: `Managed queue visibility timeout | 90 seconds on staging and production, set 24 October 2026, the platform's maximum. SendCardVideoToBunny fits inside it: 60 s upload, 75 s job, 50 MB masters. Pinned by tests/Unit/QueueTimeoutsTest.php`.
- This plan's banner and the handoff brief's progress table say step 7 is built.

**Not changed, checked 24 October 2026.**
- `services.bunny.upload_timeout` has one reader, `BunnyStream::uploadVideo()` (`app/Services/Bunny/BunnyStream.php:53`). Nothing else relies on 300.
- Five test files set the whole `services.bunny` array with `'upload_timeout' => 300` (`BunnyStreamTest.php:21`, `SendCardVideoToBunnyTest.php:30`, `Api/BunnyWebhookTest.php:21`, `Api/ViewingRoomTest.php:26`, `ReconcileCardVideosTest.php:21`). Each fakes HTTP and none asserts the number, so they are left alone rather than adding five files to a config step. The builder may align them to 60 in the same commit if the reviewer asks; it changes no assertion.
- `BUNNY_UPLOAD_TIMEOUT` is not in `.env.example`. Whether it is set on staging or production cannot be read from the repository; see "Dependencies".
- Other jobs already fit: `SettleStripePayment::$timeout` 60, `SubscribeToNewsletter::$timeout` 30. `ForgetCardVideo` and queued mail declare none and take the worker's default.
- `BunnyStream::createVideo()` sets no timeout, so it takes the HTTP client's default (30 seconds in Laravel). See "What could go wrong".

**Tests that prove it.**
- `tests/Unit/QueueTimeoutsTest.php`, new:
  - reads `SendCardVideoToBunny::$timeout`'s **default by reflection** (`(new ReflectionProperty(SendCardVideoToBunny::class, 'timeout'))->getDefaultValue()`), so it does not depend on the constructor step 8 changes;
  - asserts `config('services.bunny.upload_timeout') < $timeout`, `$timeout < config('queue.connections.database.retry_after')`, and `config('queue.connections.database.retry_after') <= 90`, the last with a message naming the platform's maximum;
  - asserts `CardForm::VIDEO_MAX_KILOBYTES <= 51200`. **Read without rendering** by reading the class constant. Building the schema off screen and asking the field for `getMaxSize()` was considered and not chosen: the field sits inside sections whose `visible()` closures take the Livewire component, so finding it needs one anyway. The constant proves the number; the feature test below proves the field uses it.
  - Reads config values as the app resolves them, never `.env.example` (CHECKPOINT trap).
  - **Red first**: at today's code the first assertion holds (300 < 600) but `600 < 90` fails, and the constant does not exist. Get past the missing constant first (add it at 204800), so the red run fails on the assertions, not on an undefined constant.
- `tests/Feature/CardResourceTest.php`, beside "uploads a video to the private disk" (`:223-247`) and using its fakes:
  - **Denial:** a `UploadedFile::fake()->create('the-fool.mp4', 51201, 'video/mp4')` on `EditCard` with `save` gives a form error on `video_master_path`, leaves `video_master_path` and `video_status` unchanged on the row, stores nothing on the uploads disk and pushes no `SendCardVideoToBunny` (`Queue::assertNothingPushed()`). Red first: at `204800` it saves.
  - A 51200 KB fake saves with no form errors and queues the job, so the cap is not off by one against her.
- Full suite green, passed, failed and skipped counts reported.

**Proof type.** The managed queue's 90 cannot be read from code; the user's statement of 24 October 2026, recorded in CHECKPOINT and the setup doc, is the evidence. The ordering and the cap are ours, so unit and feature tests. **Staging check after deploy:** upload a master of 40 to 50 MB through the panel and see it reach the Viewing Room with no failed `SendCardVideoToBunny` in the Queues tab; then try one above 50 MB and see the refusal.

**What could go wrong.**
- **Redelivery after 90 seconds now applies only to a dead worker**, one killed by the platform or by the 75 second timeout, not to a slow upload still running. The rest of the video steps (8 to 11) still guard the at-least-once case, see the note at the head of step 8.
- **Visibility applies to every job on the one default queue.** A worker that dies mid-`SettleStripePayment` is redelivered after 90 seconds, 30 later than the 60 the setup doc first recorded. The sweep covers lost settlements.
- **A slow link fails the upload.** Below about 7 Mbps a 50 MB master does not finish in 60 seconds. Each attempt throws, retries after 30 then 120 seconds, and after three the card shows Failed and she uploads again. If this is seen on staging, the answer is TUS ("Revision record"), not a longer timeout, which the 90 second ceiling does not allow.
- **The 75 second kill needs `pcntl` in the worker.** Laravel enforces a job's `$timeout` with a pcntl alarm; without the extension the job is never killed and only the 60 second PUT timeout bounds the run. That Laravel Cloud's managed workers load `pcntl` is assumed, not verified here. The staging check above does not prove it either; if it matters, a throwaway job that sleeps 80 seconds on staging shows whether it is killed at 75.
- **A slow create eats the margin.** `createVideo()` has the client default of 30 seconds. A create taking 30 followed by a full 60 second PUT passes 75, and the worker kills the run at 75; the message returns at 90 with no overlap, and the attempt counts against the three. A normal create is well under a second, so this is recorded, not engineered for. Giving `createVideo()` its own short timeout is a one-line follow-up if it is ever seen.
- **Jennifer sees a refusal above 50 MB**, in the field, before saving: "That file is too large. Videos should be under 50MB." Today's masters are 20 to 40 MB. A film exported at a higher bitrate has to be re-exported smaller, which she should be told when this deploys (see "Dependencies").
- **Livewire's own upload limit may sit below 50 MB.** There is no `config/livewire.php`, so Livewire's default temporary upload rule applies, which its shipped config documents as `max:12288` (12 MB, `vendor/livewire/livewire/config/livewire.php:68`). Whether it bites depends on whether the temporary upload goes direct to the bucket. If 20 to 40 MB masters already upload on staging it does not; if they do not, the cap she meets is 12 MB, not 50. Open question 6.
- **A `BUNNY_UPLOAD_TIMEOUT` set on a deployed environment overrides the new default.** If it is 300 there, the upload outlives the visibility timeout and is redelivered while running, which is the bug this step closes. The test cannot see it. "Dependencies".

**Commit subject.** ~~`SendCardVideoToBunny finishes inside the queue's visibility timeout`~~ `SendCardVideoToBunny finishes inside the queue's ninety seconds, and a card video is at most 50MB` (changed 24 October 2026, because the step now also caps the upload Jennifer can make).

---

### Step 8. SendCardVideoToBunny carries the upload it was sent for, and stands down when superseded (R3, R5)

**Steps 8 to 11 stand unchanged after step 7's redesign (checked 24 October 2026).** None of them cites 420, 360 or the old ordering. Step 7 makes "redelivered while still running" something that should not happen in normal operation, but the queue is still at-least-once: a worker killed by the platform or by the 75 second timeout is redelivered at 90 seconds, a job can be dispatched twice, and two saves can race. Steps 8 to 11 do not lean on the timeout at all. They are the guard for at-least-once delivery and for racing saves and promotions (R5), and they stay as written: step 8 stands a superseded run down, step 9 refuses to delete a held film, step 10 refuses stale promotion, and step 11's atomic claim is what makes two overlapping runs safe however they came to overlap.

**Built.**
- Constructor `(int $cardId, string $masterPath)`. `Card::booted` dispatches `SendCardVideoToBunny::dispatch($card->id, $card->video_master_path)->afterCommit()`.
- `handle()`:
  - Loads `Card::withTrashed()->find($cardId)` (finding 10) and returns quietly if the card is gone or the path differs.
  - The missing-in-bucket branch writes Failed only where the path matches.
  - The path is re-read just before upload.
  - The final write is `Card::withTrashed()->whereKey($id)->where('video_master_path', $path)->where('bunny_incoming_video_id', $videoId)->update(['video_status' => 'processing'])`.
  - The create-once guard is unchanged here. Step 11 replaces it.
- `failed()`: `Card::withTrashed()->whereKey($cardId)->where('video_master_path', $masterPath)->where('video_status', '!=', 'ready')->update(['video_status' => 'failed'])`, logging the path.
- The docblock cites `ForgetCardVideo` and the CHECKPOINT trap at line 221.

**Tests that prove it** (`SendCardVideoToBunnyTest`, constructors updated):
- the job serialises to id and path, and `serialize($job)` contains no `App\Models\Card`.
- a superseded path sends nothing (`Http::assertNothingSent()`).
- a replacement during the PUT (an `Http::fake` callback changes the path) leaves the new upload `pending`.
- a promotion during the PUT leaves `ready`.
- `failed()` for a superseded path, and after promotion, writes nothing.
- a missing master for a superseded path is not marked Failed.
- a soft-deleted card is still processed.
- Red first on every superseded-path case.

**Bunny proof.** No request changes shape, and `BunnyStreamTest`'s vector stands. Staging check after deploy: upload one film and watch it reach the Viewing Room.

**What could go wrong.** Payloads already queued, **and failed-job entries**, were serialised with a `card` property. Unserialised into the new class, `cardId` is uninitialised and both `handle()` and `failed()` throw. A failed entry of the old shape cannot be retried. **Drain the queue and flush those failed entries before deploying** (deploy order, critique S6).

**Commit subject.** `SendCardVideoToBunny carries the upload it was sent for, and stands down once it is superseded`

---

### Step 9. ForgetCardVideo never deletes a film a card still holds (R5, critique B2)

**Built.** `ForgetCardVideo::handle()` checks each id before deleting it: `Card::withTrashed()->where('bunny_video_id', $id)->orWhere('bunny_incoming_video_id', $id)->first()`. When a card holds the id, it skips the delete and logs a warning naming the id and the card. The master path delete is unchanged. The docblock says this is the last line of defence against a racing save or promotion, and that it makes the job safe whoever dispatched it. The incoming column is checked as well as the live one. That is a superset of the critique's fix, and it is safe for every existing caller, since each nulls or replaces the column before its job runs.

Callers checked, all safe:
- `forgetVideoEntirely` nulls both columns before its after-commit dispatch.
- `promoteIncomingVideo` dispatches the superseded live id after the row points at the new one.
- Step 10's superseded incoming id is nulled by the same save.
- Step 11's lost candidate was never stored.

**Tests that prove it** (`ForgetCardVideoTest`):
- an id held as `bunny_video_id` is not deleted (`Http::assertNotSent` DELETE for it) and a warning is logged. Red first.
- the same for `bunny_incoming_video_id`.
- the same for a soft-deleted card holding it.
- an id nobody holds is deleted, so every existing case stays green.
- the master is still deleted when a video id is refused.
- **The B2 race, scripted:** load card A with incoming X, promote X through another instance (live = X), then save a new master on stale A, which queues forget(X). X is not deleted and the card plays X.

**Bunny proof.** No new call shapes. The protection is ours.

**What could go wrong.** One extra query per id. A legitimately orphaned id that some card still references by mistake would be kept and logged rather than deleted, which is the right failure.

**Commit subject.** `ForgetCardVideo never deletes a film a card still holds`

---

### Step 10. A replaced master gets a fresh incoming video, and stale promotion is refused (R5)

**Depends on steps 8 and 9.**

**Built.**
- `Card::forgetSupersededMaster()` returns `new ForgetCardVideo(videoIds: [$this->getOriginal('bunny_incoming_video_id')], masterPath: ..., cardId: ...)` and sets `bunny_incoming_video_id = null`. The live id is never touched. The docblock "video ids are deliberately untouched" is struck and dated.
- `promoteIncomingVideo(): bool` is compare-and-set. In `DB::transaction` with `lockForUpdate`:
  - If the row's incoming id is null or differs from the instance's, return false.
  - Otherwise read the superseded live id from the locked row, save, refresh `$this`, dispatch `ForgetCardVideo` after commit, and return true.
- `SyncCardVideo`'s `Failed` and default arms write `Card::whereKey($card->id)->where('bunny_incoming_video_id', $videoId)->update([...])` (finding 9).

**Tests that prove it.**
- `ForgetCardVideoTest:171` ("throws away the master a replacement supersedes, and neither video") **changes expectation by decision**: it now expects the master and the in-flight incoming video to be queued, never the live one. Its comment strikes and dates the old sentence.
- a replacement mid-transcode leaves incoming null, `pending`, live untouched and the card viewable.
- `BunnyWebhookTest`: "ready" for the superseded video finds no card.
- a stale model's `promoteIncomingVideo()` returns false and queues nothing.
- a stale `SyncCardVideo` does not overwrite `pending`.
- existing promotion tests (`CardVideoTest:83`, `ForgetCardVideoTest:191,212`, `ReconcileCardVideosTest`) stay green.
- Red first on the stale-promote and fresh-incoming cases.

**Bunny proof.** Staging check: replace a film still transcoding. The old film keeps playing, the new one arrives, and Bunny's library holds one film for the card.

**What could go wrong.** The old job's PUT to a just-deleted video fails, and its retry stands down on the path check (step 8).

**Commit subject.** `A replaced master gets a fresh video at Bunny, and stale promotion is refused`

---

### Step 11. Only one run can claim a card's incoming video (R3)

**Precondition.** A green CI run of `tests/Feature/RedeemAGiftRaceTest.php`, which hard-asserts `extension_loaded('pcntl')` and `posix_kill` at `:142-143`. CI runs only on pull requests and pushes to `main` (finding 15), so open a pull request. If it is red on `pcntl`, add `pcntl, posix` to `extensions:` in `.github/workflows/tests.yml` as its own commit first, and say so.

**Built.** In `handle()`, after the path checks:
1. Re-read. If the path matches and an incoming id is set, adopt it with no create.
2. Otherwise `$candidate = $bunny->createVideo(...)`. Then, **inside `try`**, claim:
   ```sql
   UPDATE cards SET bunny_incoming_video_id = :candidate, video_status = 'processing', updated_at = now()
   WHERE id = :id AND video_master_path = :path AND bunny_incoming_video_id IS NULL AND video_status = 'pending'
   ```
   On any throw between the create and the end of the claim, `catch` queues `ForgetCardVideo([$candidate])` and rethrows (critique S1). Doing this as an extracted `protected claim()` method lets a test subclass make it throw.
3. Claimed: upload to the candidate.
4. Not claimed: re-read. If the path matches and an incoming id is set, adopt it, forget the candidate and upload. Otherwise forget the candidate and return.

**Tests that prove it.**
- `tests/Feature/SendCardVideoToBunnyRaceTest.php`, after `RedeemAGiftRaceTest.php:69-128`: `pcntl_fork`, a per-child `racer` connection, a wall-clock start, and `SIGKILL` exit.
  - Faked create answers `video-{getmypid()}` after a 300 ms sleep. That sleep and the shared start make overlap the expected case.
  - DELETE ids are logged to a temp file.
  - **Each child writes an outcome file** (critique S2, like `RedeemAGiftRaceTest:117`): `claimed:<id>`, `adopted:<id> forgot:<candidate>`, `stood-down forgot:<candidate>` or `threw <class>`. A missing file is `no answer`.
  - Per round, over several rounds: both children answered, **exactly two creates**, exactly one `claimed`, one `adopted ... forgot`, the card's incoming id equals the claimed id, and the forgotten id was DELETEd. A round with one create fails as "did not race" rather than passing.
- **Proved by deleting the rule**: with the claim reverted to `if (! hasIncomingVideo()) update(...)`, the test goes red. Recorded.
- Uses `DatabaseTruncation`, with allowlist trait `Tests\Support\TruncatesWhatACardTouches` and `afterEach` truncation.
- Single-process cases in `SendCardVideoToBunnyTest`:
  - S1: the claim throws, so the candidate is queued for deletion and the exception propagates. Red first.
  - A late redelivery after promotion (`ready`, incoming null, same path) creates, fails the claim, forgets the candidate and leaves the live film alone.
  - A claim lost to a replaced path forgets the candidate.
  - "uploads to the same video when the queue runs it twice" (`:100`) still makes one create.
- `.claude/testing.md` "The second carve-out" goes from one file to two, naming this one, dated. That file is gitignored, so the change is local and noted in the report.

**Bunny proof.** Same calls. The race is ours, so the forked test is the proof.

**What could go wrong.**
- The forked socket, handled by the `racer` pattern.
- `locales` must not be truncated, hence an allowlist.
- The queue runs inline in tests, so `ForgetCardVideo` runs in the child. Step 9's guard checks that the candidate is not held, which it is not.

**Commit subject.** `Only one run of SendCardVideoToBunny can claim a card's incoming video`

---

### Step 12. A payment attempt is a row of its own (R1, R2, schema)

**Depends on step 6.**

**Built.**
- The `order_payments` migration (with `->index()` on `order_id`, which step 1's check enforces). The `PaymentAttemptState` enum. `OrderPayment` (casts, `belongsTo(Order)`), `OrderPaymentFactory` and `Order::payments()`. No writer yet.
- ~~**Added 23 October 2026 (user decision): a paid order has a customer, in Postgres.**~~ **Moved 24 October 2026 to step 12a** (user decision), its own commit right after this one. The bullet below is kept struck as history; step 12a carries it, with the migration renamed.
- ~~`2026_10_22_005000_a_paid_order_has_a_customer.php`, exactly as under "Database": the pre-check, then `ALTER TABLE orders ADD CONSTRAINT orders_paid_has_a_customer CHECK (status <> 'paid' OR customer_id IS NOT NULL)`, and a `down()` that drops it. A comment above the `ALTER` cites CLAUDE.md section 4 ("a paid order always has a customer"), says step 5a made `MarkOrderPaid` the only writer of the owner and that this is the database's copy of the rule, and says why there is no backfill. `docs/CHECKPOINT.md`'s schema notes and `docs/backend-components.md`'s orders entry gain one line naming the constraint, in this step (CLAUDE.md 0.6). The comment in `2026_08_29_000000_an_order_may_wait_for_its_customer.php` is not edited: a migration is history.~~

**Tests that prove it.**
- A schema test: columns, nullability, both ids unique (a duplicate throws `UniqueConstraintViolationException`), and an order with an attempt cannot be deleted. `ForeignKeysAreIndexedTest` stays green. The migration round-trips.
- ~~**`tests/Feature/PaidOrderHasACustomerTest.php`** (added 23 October 2026), with its cases, its audit, the `MarkOrderPaidTest` invariant note and the existing-tests inventory.~~ **Moved 24 October 2026 to step 12a**, unchanged except for the migration path.

**What could go wrong.**
- A writerless table for one commit, kept separate because the next step is its own idea.
- ~~**An existing row that violates the constraint makes the migration fail** (added 23 October 2026)~~, ~~the brief `ACCESS EXCLUSIVE` lock the `CHECK` takes~~, and ~~**Two ideas in one step**~~: **moved 24 October 2026 to step 12a.** The last is resolved by the split it proposed.

**Commit subject.** `A payment attempt is a row of its own`

---

### Step 12a. A paid order without a customer is refused by the database (CLAUDE.md section 4, schema)

**Added 24 October 2026** (user decision), split out of step 12, where the user placed the constraint on 23 October 2026. Named 12a so no later step is renumbered. Everything below was written into step 12 on 23 October 2026 and moved here unchanged, except the migration's file name and this dependency note.

**Depends on step 5a, and on nothing else.**
- **Not on step 12.** It touches only `orders.status` and `orders.customer_id`. It reads, writes and references nothing in `order_payments`, and its test builds no attempt.
- **Not on step 6.** No Stripe call or shape is involved.
- **Step 5a,** because the migration comment says step 5a made `MarkOrderPaid` the only writer of the owner, which is false before it, and because the test inventory below assumes 5a's changes (its invariant cases, and `SettleAGiftOrderTest.php:399` rewritten or deleted). Postgres itself needs nothing from 5a: at `8323747` no road to paid can leave `customer_id` null.
- **Placement.** It sits right after step 12 by the user's decision of 24 October 2026. Nothing technical stops it landing earlier, any time after step 5a, including before step 6 is captured. Moving it is the user's call; if it moves ahead of step 12, the migration goes back to the `005000` name (see "Database").

**Built.**
- `2026_10_22_015000_a_paid_order_has_a_customer.php` (~~`2026_10_22_005000_...`~~, renamed 24 October 2026 so it sorts after step 12's migration), exactly as under "Database": the pre-check, then `ALTER TABLE orders ADD CONSTRAINT orders_paid_has_a_customer CHECK (status <> 'paid' OR customer_id IS NOT NULL)`, and a `down()` that drops it.
- A comment above the `ALTER` cites CLAUDE.md section 4 ("a paid order always has a customer"), says step 5a made `MarkOrderPaid` the only writer of the owner and that this is the database's copy of the rule, and says why there is no backfill.
- `docs/CHECKPOINT.md`'s schema notes and `docs/backend-components.md`'s orders entry gain one line naming the constraint, in this step (CLAUDE.md 0.6). This plan's banner and the handoff brief's progress table say step 12a is built.
- The comment in `2026_08_29_000000_an_order_may_wait_for_its_customer.php` is not edited: a migration is history.

**Tests that prove it.**
- **`tests/Feature/PaidOrderHasACustomerTest.php`** (added 23 October 2026). Written and run **red first**, before the migration file exists, when every insert below succeeds:
  - `Order::factory()->paid()->create(['customer_id' => null])` throws `QueryException` whose SQLSTATE is `23514` (`check_violation`), and no order row exists afterwards.
  - An unowned pending order (`unownedOrderWorth()`, `tests/Pest.php:251`) updated with `forceFill(['status' => OrderStatus::Paid])->save()` throws the same, and the row is still pending.
  - Each refused write runs inside its own `DB::transaction(fn () => ...)`, a savepoint under `RefreshDatabase`. Without it the failed statement aborts the test's outer Postgres transaction and the follow-up reads fail with "current transaction is aborted" rather than asserting anything.
  - **Allowed:** an unowned `pending` order and an unowned `cancelled` order save without error, so the constraint does not refuse the window CLAUDE.md section 4 says null covers.
  - **The pre-check (failure path):** inside the test, drop the constraint, insert a paid order with a null customer through `DB::table('orders')`, then call the migration's `up()` (`(require database_path('migrations/2026_10_22_015000_a_paid_order_has_a_customer.php'))->up()`, ~~`..._005000_...`~~ path renamed 24 October 2026). It throws `RuntimeException` naming that order's id, and the constraint still does not exist. Postgres DDL is transactional, so this runs inside `RefreshDatabase`'s transaction and leaves nothing behind.
  - Its audit: delete the `ALTER` from `up()` and the first two cases go red.
- `MarkOrderPaidTest`'s section 4 invariant cases from step 5a stay green, now also held by Postgres.
- The migration round-trips (`down()` then `up()`).
- Every existing test stays green. Read at `8323747`: `OrderFactory::definition()` always makes a customer (`database/factories/OrderFactory.php:22`) and `paid()` does not remove it, and the tests that null `customer_id` (`tests/Pest.php:260`, `OrderTest.php:30`, `SettleAnUnownedOrderTest.php:104`, `PayOrderTest.php:234`, `ReadPaymentStatusTest.php:262`) all leave the order pending. `SettleAGiftOrderTest.php:399` nulls the owner and then expects settlement to fail; step 5a rewrites or deletes that case before this step. Any test still failing on `23514` is a test that built an impossible order, and it is fixed by giving the order a customer, never by loosening the constraint (hard rule 13).
- Full suite green, with passed, failed and skipped counts reported.

**What could go wrong.**
- **An existing row that violates the constraint makes the migration fail** (added 23 October 2026). No backfill, per the plan's decision of 22 October 2026: the migration neither assigns an owner nor deletes the row, it refuses with the ids, and `migrate --force` stops the deploy with Laravel's migration transaction rolled back. Such a row should not exist on any environment: before 29 August 2026 `customer_id` was `NOT NULL`, and since then every road to paid went through `MarkOrderPaid`, which threw `OrderBelongsToNobody` for an unowned order (`MarkOrderPaid.php:113-115` at `8323747`), the panel's "Mark as paid" included. Only a hand SQL edit could make one. To find out before the deploy rather than during it, the user runs `select id from orders where status = 'paid' and customer_id is null` on staging and production (production has never been live) through the Laravel Cloud command runner, and repairs any row by hand. That pre-check is in "Deploy order" and "Dependencies".
- If step 12a deploys together with step 12 and refuses, step 12's migration has already run in its own transaction and stays. The deploy stops; the row is repaired and the deploy re-run, which applies only step 12a's migration. This is why the two were separate files before they were separate steps.
- Adding a `CHECK` takes a brief `ACCESS EXCLUSIVE` lock on `orders` while Postgres scans it. On a table of test data this is milliseconds.

**Deploy.** After the user's paid-without-customer query on that environment returns no rows (see "Deploy order").

**Commit subject.** `A paid order without a customer is refused by the database`

---

### Step 13. Both gateways record each attempt before handing it to the customer (R1, R2)

**Built.** In `StripeCheckout::begin` and `StripeWallet::begin`, after `RefuseAnythingButAPendingOrder`:
1. `DB::transaction`: lock the order. If it is not pending, throw `OrderCannotBeCharged`. Insert an `OrderPayment` (`total_amount`, `currency`, `started`, no ids).
2. Create the Session or intent with `metadata.order_payment_id = (string) $attempt->id`.
3. Write the returned ids:
   - hosted: `stripe_checkout_session_id`, plus the intent when non-null
   - wallet: `stripe_payment_intent_id`
4. **If step 2 throws, keep the id-less `started` attempt** (critique S3). A timeout may mean Stripe did create the object. Its eventual webhook links back through `order_payment_id` (step 14), and the sweep (step 16) ignores rows with no id.

**Dual write.** `orders.stripe_payment_intent_id` is still written as today until step 19.

**Tests that prove it** (`StripeCheckoutTest`, `StripeWalletTest`):
- one attempt per `begin()`, two calls make two, and the metadata sent names the attempt.
- `stripeAccepting()`'s fake Session is **rebuilt from `checkout-session-created.json`**. The hosted attempt holds the session id and a null intent.
- `:156-172` and `:178-193` change to assert what the captured shape makes true. Each comment strikes and dates the old expectation and cites the vector. The false sentence at `:195-200` is struck, citing R1.
- a non-pending order raises and creates no attempt and no Stripe object.
- Stripe throwing leaves one id-less `started` attempt, and the exception reaches the caller as today.
- Red first.

**Stripe proof.** That `order_payment_id` reaches the PaymentIntent and returns in `payment_intent.succeeded` is Stripe's to confirm. The proof is the **staging card and wallet walks** with steps 13 and 14 deployed, then `select * from order_payments` showing the intent linked, plus the **mandatory recapture in step 15**.

**What could go wrong.** The order lock covers only the insert, never the Stripe call. If review prefers, split into 13a (hosted) and 13b (wallet).

**Commit subject.** `Both gateways record each payment attempt before handing it to the customer`

---

### Step 14. A settled or duplicate payment is recorded on its attempt (R1, durable PaidTwice)

**Built.**
- `StripePayment::$attemptId` from `metadata.order_payment_id`.
- `RecordStripePayment`, past the amount check:
  - Find the attempt: by `attemptId` when it belongs to this order, else by `stripe_payment_intent_id`, else `firstOrCreate` on the intent. The last two cover intents begun before step 13, which exist only in staging or local test data.
  - Write the intent onto it.
  - Wrap `MarkOrderPaid` and the state write in one `DB::transaction`: `Settled`/`AlreadyPaid` give `settled`, `PaidTwice` gives `duplicate`. Safe because `MarkOrderPaid`'s mail waits on `DB::afterCommit` (`MarkOrderPaid.php:44-53`), which the code comment cites.
  - `BuyerUnknown`/`AmountMismatch` leave the attempt `started`, holding the intent.
- Step 5's pending-only column write stays until step 19.

**Tests that prove it.**
- R1 end to end: B's attempt is `duplicate`, A's is `settled`. Red first.
- a redelivery keeps one `settled` attempt.
- an intent with no `order_payment_id` gets its attempt.
- an `attemptId` belonging to another order is ignored and lookup falls back to the intent.
- an id-less attempt left by a Stripe timeout (step 13) is linked when its intent's webhook arrives.
- `BuyerUnknown` stays `started`, holding the intent.
- a throwing attempt write rolls back the settlement and sends no mail. **From step 5a (note added 23 October 2026):** on an unowned order it also leaves `customer_id` null, since the owner is written in the settling save that rolls back.

**Step 5a knock-on (23 October 2026).** Every `PaidBy` built here carries the customer id from `identify()`. The attempt row does not record the buyer. The `PaidTwice` log already names both.

**Stripe proof.** Staging walks, then step 15's recapture.

**What could go wrong.** Two deliveries racing a fallback insert, handled by `firstOrCreate`'s savepoint re-read (the mechanism `identify()` already uses).

**Commit subject.** `A settled or duplicate payment is recorded on its attempt`

---

### Step 15. The webhook vector is recaptured carrying its attempt (R4, performed by the user, mandatory)

**Built by the user** once steps 13 and 14 run, locally or on staging. Repeat step 6 item 1 against an order paid through the new `begin()`, and replace the three `payment-intent-succeeded.*` files. **Prove the new event is accepted live before replacing a byte** (fixture README "Regenerating this"). Record the new date.

**Then (agent, same commit).** `StripeWebhookVectorTest` "reads a real payload" additionally asserts `StripePayment::from(...)->attemptId` is a positive integer (critique S4). This step's whole value is that assertion: the only proof from Stripe itself that `metadata.order_payment_id` survives the round trip.

**Tests that prove it.** The three vector cases pass. Before the recapture, the new assertion is seen red against the old payload.

**What could go wrong.** Capturing from an order placed by pre-step-13 code yields a null `attemptId`. Check that the order has an attempt row before paying.

**Commit subject.** `The captured Stripe vector carries the attempt that created it`

---

### Step 16. orders:reconcile asks Stripe about attempts, hosted Sessions included, and Sessions expire in 30 minutes (R2)

**Built.**
- `App\Services\Payments\AttemptAtStripe`, taking `StripeClient` and answering a small readonly result (`?PaymentIntent $intent`, `bool $expired`):
  - intent present: `paymentIntents->retrieve`
  - session only: `checkout->sessions->retrieve($id, ['expand' => ['payment_intent']])`
  - `expired` when the Session is `expired` and its intent is absent or not succeeded
- `ReconcileOrders` sweeps `started` attempts holding at least one id, on pending orders, whose **attempt** `created_at` is at least 15 minutes old:
  - an intent goes to `RecordStripePayment`
  - an expired Session becomes `expired` and is never asked about again
  - an open Session prints "Still open at Stripe"
  - lines read "Order N, attempt M"
  - The old query's comment is struck and dated.
- `StripeCheckout::begin` sends `'expires_at' => now()->addMinutes(30)->getTimestamp()` (critique S5) with a comment: 30 minutes is Stripe's minimum, chosen so abandoned Sessions stop being swept within the hour, and a returning customer calls `/pay` again. `StripeCheckout.php:106-108`'s "expires after 24 hours" is struck and dated.
- `StripeBoundaryTest` gains `app/Services/Payments/AttemptAtStripe.php` in both lists (12→13, 7→8), as decided.
- `SettleStripePayment.php:31-36`, untrue on the hosted road, is struck and dated.
- **Pre-step-13 pending orders have no attempt and are no longer swept.** Production has never been live, so these are staging and local test orders only, and the command's docblock says so.

**Tests that prove it** (`ReconcileOrdersTest`, `StripeCheckoutTest`):
- fakes return `checkout-session-paid.json` and `checkout-session-expired.json`.
- a hosted pending order with no webhook is settled. Red first.
- two succeeded attempts give one `settled`, one `duplicate` and the `PaidTwice` error line, which from step 5a (note added 23 October 2026) names both buyers' customer ids.
- an expired Session becomes `expired`, and a second sweep makes no call for it.
- an attempt under 15 minutes old is skipped.
- leftover attempts on a paid order and id-less attempts are skipped.
- one `ApiErrorException` does not stop the rest.
- the Session create sends `expires_at` 1800 seconds from a frozen `now()`.

**Stripe proof.** Captured Session vectors back the fakes. That Stripe accepts `expires_at` at exactly 30 minutes is Stripe's to say, so the staging card walk must succeed with this step deployed.

**Staging walk**, recorded in `tests/Staging/README.md`:
1. **First, note the endpoint so you can re-enable it.**
2. Disable the test-mode webhook endpoint.
3. Pay a hosted checkout.
4. After 15 minutes run `php artisan orders:reconcile` in Laravel Cloud's command runner and see the order paid, with its attempt `settled`.
5. **Re-enable the endpoint.**
6. Separately, leave a Session unpaid for 30 minutes and see the next sweep mark it `expired`.

**What could go wrong.** A customer who takes more than 30 minutes on Stripe's page meets an expired page and starts again. That is a customer-facing consequence of S5, and the contract prose changes accordingly.

**Commit subject.** `orders:reconcile asks Stripe about each payment attempt, and an abandoned Session expires in thirty minutes`

---

### Step 17. /orders/status reads the newest attempt (R2, finding 1)

**Built.** `ReadPaymentStatus`:
- A paid order answers `settled()` first.
- Otherwise it takes the newest attempt holding an id. With none, `nothingCollected()`.
- Otherwise `AttemptAtStripe`: an intent gives `atStripe(StripePayment::from($intent))`, no intent gives `nothingCollected()`.
- The exceptions are the same, so the 503 path is unchanged.
- Pre-step-13 pending test orders now answer `requires_payment_method`. Said in the docblock, and acceptable with no production data.

**Tests that prove it** (`ReadPaymentStatusTest`):
- a session-only hosted attempt whose captured paid Session carries a succeeded intent answers `succeeded` before settlement. Red first.
- a wallet attempt is read by intent.
- the newest of two attempts wins.
- an unconfirmed Session answers `requires_payment_method`.
- a paid order makes no Stripe call.
- Stripe unreachable answers 503.
- an unknown token answers 404.

**Stripe proof.** Captured vectors back the fakes. **Staging walk**: the hosted round trip, polling `/orders/status` right after paying.

**What could go wrong.** The frontend sees `succeeded` sooner, which is what the contract already says. They get an informational note.

**Commit subject.** `/orders/status reads the newest payment attempt, so a hosted payment is seen before its webhook`

---

### Step 18. A dispute finds its order through any attempt (R1)

**Built.** `StripeWebhookController::disputed()` (`:144`) resolves through `OrderPayment::where('stripe_payment_intent_id', ...)->first()?->order` ~~, falling back to the order column. **The fallback is the only route for an order paid before step 13**, which with no production data means a staging test order at most. It stays until open question 2's cleanup decides otherwise.~~ **and nothing else, decided 22 October 2026.** There is no fallback to the order column, because production was never live. A dispute on an old staging test order with no attempt row emails without an order, which is accepted. The log adds the attempt's `state`.

**Tests that prove it** (`StripeWebhookTest:469-503`):
- a duplicate attempt's intent mails with its order. Red first.
- the settling intent still finds its order.
- ~~an order known only through the column (pre-step-13 shape) is found by the fallback.~~ Withdrawn 22 October 2026 with the fallback. In its place: an intent held only on the order column, with no attempt row, mails with no order. This proves the column is not consulted.
- an unknown intent mails with no order and answers 200.
- the event comes from `charge-dispute-created.json` if captured.

**Stripe proof.** `$dispute->payment_intent` is already read today. The optional captured payload.

**Commit subject.** `A dispute finds its order through any payment attempt, the losing one included`

---

### Step 19. An order names only the payment that settled it (R1, R2, close)

**Built.**
- Both `begin()` methods stop writing the order column. `StripeCheckout.php:101-104` and `StripeWallet.php:114-123` are struck and dated.
- Step 5's `BuyerUnknown` column write is deleted.
- The data migration nulls pending orders' column (staging and local test data only).
- `Order`'s casts block comments the column's meaning.
- `docs/CHECKPOINT.md:51` ("holds an intent id from the moment the payment starts") is struck and dated.

**Tests that prove it.**
- after `begin()` the order column is null and the attempt holds the id.
- `BuyerUnknown` leaves the column null and the attempt `started`, and the sweep settles it once a buyer is readable.
- every Stripe-paid order's column equals its single `settled` attempt's intent, and `charged_amount === total_amount`.
- the migration nulls pending orders only.
- full suite green, and both staging walks re-run after deploy.

**What could go wrong.** Deploying before steps 16 to 18 are deployed blinds the sweep and `/orders/status` to wallet orders. Deploy order prevents it. Afterwards, grep `stripe_payment_intent_id` in `app/` and expect only `MarkOrderPaid` ~~, the dispute fallback~~ and `OrderPayment` (the dispute fallback was dropped 22 October 2026), plus the attempt lookups in `RecordStripePayment` and `StripeWebhookController`, which query `order_payments`, not `orders`.

**Commit subject.** `An order names only the payment that settled it`

---

### Step 20 (optional, blocked on Jennifer). Starting checkout again retires the attempt it replaces

**Built, only if approved.** At the end of `begin()`, best effort and never blocking the new attempt:
- expire the previous `started` hosted Sessions
- cancel the previous cancellable wallet intents
- log failures and carry on

**Why it waits for Jennifer.** It changes what a customer with two tabs experiences, and it cannot remove `PaidTwice`, only reduce it.

**Tests.** The second `begin()` expires the first Session and cancels the first intent. A Stripe error still returns the new instruction. A succeeded intent is never cancelled. **Stripe proof**: a staging walk.

**Commit subject.** `Starting checkout again retires the payment attempt it replaces`

## Deploy order

1. **Step 1** any time.
2. **Step 2** before step 3 is deployed, so the Viewing Room walk keeps working. **Steps 3 and 4** may deploy before or with step 5. **Step 5 deploys with no schema step.** **Step 5a** (added 23 October 2026) has no schema step either, and may deploy with step 5 or after it.
3. ~~**Step 7** with or after the user sets the managed queue visibility timeout to 420 seconds on staging and production.~~ **Step 7** (rewritten 24 October 2026) any time: the visibility timeout is already 90 on staging and production. **Before it reaches an environment**, the user checks that `BUNNY_UPLOAD_TIMEOUT` is unset there, or at most 60, and removes or lowers it if not. Ask Jennifer not to upload a film during the deploy, and tell her the new 50 MB limit.
4. **Step 8: drain first, and flush the old failed jobs.**
   - Confirm in the Queues tab that no `SendCardVideoToBunny` is waiting or running.
   - Delete any failed `SendCardVideoToBunny` entries there (or `php artisan queue:forget` / `queue:flush` locally). An old-shape payload cannot be retried once the constructor changes.
   - Ask Jennifer not to upload a film during the deploy.
   Then steps 9, 10 and 11 in order. **Step 9 before step 10**, because step 10 dispatches deletes for incoming ids.
5. ~~**Steps 12, 13 and 14** may deploy together.~~ **Steps 12, 12a, 13 and 14** may deploy together (12a added 24 October 2026). ~~**Before step 12 reaches an environment**~~ **Before step 12a reaches an environment** (added 23 October 2026, moved to step 12a 24 October 2026), the user runs `select id from orders where status = 'paid' and customer_id is null` there and repairs any row by hand; the migration refuses rather than backfills. Walk staging (card and wallet), then do **step 15**'s recapture. ~~Step 11 before 12 to 14~~ (deleted with the backfill, 22 October 2026).
6. **Steps 16, 17 and 18** next, with the webhook-disabled sweep walk after step 16.
7. **Step 19 last** of the required steps, after steps 16 to 18 are deployed and walked on staging.
8. Production follows staging for every step. `migrate --force` runs on deploy.

## Dependencies

- **The user**:
  - captures step 6 and recaptures step 15
  - ~~sets the 420 second visibility timeout and reports the date. **Done: the user set it on staging and production on 23 October 2026.**~~ **Reversed 24 October 2026: 90 seconds is the platform's maximum**, and the user has set staging and production to 90. Step 7's 300 < 360 < 420 ordering cannot hold. ~~Steps 7 to 11 are paused for a redesign (options with the user).~~ **Resolved 24 October 2026:** the user chose to keep the push upload inside 90 seconds with 50 MB masters (step 7 rewritten); steps 8 to 11 stand unchanged.
  - **checks `BUNNY_UPLOAD_TIMEOUT` in the environment variables of staging and production** (added 24 October 2026) before step 7 deploys. It is not in `.env.example`, so nothing in the repository says whether it was ever set. If it is set above 60, remove it (the default becomes 60) or set it to 60. Left at 300 it overrides step 7 and the upload outlives the visibility timeout. `DB_QUEUE_RETRY_AFTER` does not matter there (the deployed connection is `cloud`, not `database`), but if present it should be removed so nobody reads it as the queue's timeout.
  - answers open question 6 (Livewire's upload limit) before step 7 is built, ideally by saying whether a 20 to 40 MB master has been uploaded through the panel on staging
  - drains and flushes the queue before step 8
  - runs the paid-without-customer query on staging and production before ~~step 12~~ step 12a deploys (added 23 October 2026, step renamed 24 October 2026)
  - opens a pull request so CI runs `RedeemAGiftRaceTest` before step 11
  - runs the staging walks (steps 2, 13, 14, 16, 17, 19), including disabling and re-enabling the test-mode webhook endpoint
  - ~~answers open question 1~~ (answered 22 October 2026)
- **Jennifer**: step 20 only. She should also be told that support can no longer mark an order paid, and that a hosted payment page now expires after 30 minutes. **From step 7 (24 October 2026):** a card video must be under 50 MB, and the panel refuses a larger file.
- **Frontend partner**: no build work. Informational notes after steps 3, 4 ~~(if built)~~, 16 and 17.
- **External access**: Stripe test mode (Developer role, `sk_test_`, `pk_test_`). The Laravel Cloud dashboard for the queue setting, environment variables, failed jobs and the command runner. GitHub Actions.

## Documentation the documenter must update at close-out

Struck through and dated, never deleted.

- `QA_CHECKLIST.md`:
  - line 237 (a pass holder is made by settling in the panel)
  - section "Settling it by hand, as Jennifer would", lines 388-397
  - gifting rows 466 and 473 ("Mark as paid on that order", "Mark as paid a second time by any route")
  All are rewritten to settle through a Stripe test payment, plus a row: "Orders offers no way to mark an order paid".
- `docs/backend-components.md`:
  - lines 447 and 452 (Orders screen "mark as paid", "Marking an order paid in the panel runs `MarkOrderPaid`")
  - lines 336, 359 and 361 ~~if step 4 is built~~ (step 4 decided 22 October 2026)
  - add `order_payments` and `AttemptAtStripe`
- `docs/specs/milestone-5-phase-b-spec.md`:
  - line 36 ("She still has mark as paid in the panel")
  - user stories 29 and 30 (lines 93-94)
  - line 147 ("Null on a hand-settled order, and that stays true after launch")
  - lines 140, 159, 233 and 258 ~~if step 4 is built~~ (step 4 decided 22 October 2026)
- `API_CONTRACT.md`: lines 13, 91, 445, 471, 473, 506 and 771, as in "Contract changes".
- `docs/adr/0006-one-screen-per-question.md` lines 18 and 45-46, and `docs/adr/0004-a-reading-is-a-row-of-its-own.md` line 197: an "Amended 22 October 2026" note. An ADR is a record and is not rewritten.
- `docs/CHECKPOINT.md`:
  - reversal rows: "Hand settlement in the panel → Stripe is the only road to paid", "Session life 24 hours → 30 minutes", and ~~, if step 4 is built,~~ "`manual` method → retired"
  - line 61
  - the "No refunds" row's residual
  - the visibility timeout row (step 7). ~~Written at close-out.~~ Written by step 7 itself (CLAUDE.md 0.6); close-out checks it says 90 seconds, set 24 October 2026
  - the vector row (steps 6 and 15)
- `docs/plans/gifting-tickets.md:106` and `docs/plans/milestone-7-plan.md:44,292`: no edit. Plans are history, and the CHECKPOINT reversal row is the authority.
- `tests/Staging/README.md`: the new Viewing Room and sweep walks. Line 132 stays as history.
- ~~`CLAUDE.md` section 4, "a refund is the only thing that can take a pass away", against "no refunds ever": the user decides the wording at close-out.~~ Done 24 October 2026 (open question 4).
- `CLAUDE.md` section 4, "identity is filled at settlement before `MarkOrderPaid` runs" (added 23 October 2026, step 5a): after step 5a the buyer is resolved before `MarkOrderPaid` and written inside its lock. The invariant "a paid order always has a customer" holds. The user decides the wording. `docs/CHECKPOINT.md:44,168` are corrected in step 5a itself.

## Open questions

1. ~~**Retire the `manual` payment method and `ManualSettlement` (step 4)?** Recommended: **yes**.~~ **Answered YES, 22 October 2026.** Step 4 is part of the plan. The original question is kept below for the record. After step 3, `manual` is a method nothing can complete. A `POST /pay` choosing it leaves an order pending forever, and its own docblock and config comment become false. The costs:
   - local development has no road to paid without Stripe test keys
   - several config and registry tests need a new fixture gateway
   - the comment-claim fixtures that use `ManualSettlement` as "a real class elsewhere" need another class
   If **no**, step 3 rewrites those two comments and `manual` stays as a dead option.
2. ~~**After step 19, should the dispute lookup's fallback to the order column be removed?** With no production data it only serves staging test orders. Recommended: leave it until launch, then delete it with a note, since it costs nothing.~~ **Answered 22 October 2026: drop it**, because production was never live. Step 18 resolves only through `order_payments`. A dispute on an old staging test order emails without an order, which is accepted.
3. **Step 20: does Jennifer want an earlier checkout page to stop working once the customer starts again?** Blocked until she answers.
4. ~~**For close-out:** the `CLAUDE.md` section 4 refund sentence (documenter, user's wording), and since step 5a its "identity is filled at settlement before `MarkOrderPaid` runs" sentence.~~ **Done 24 October 2026**, the user approved the wording: the refund sentence now says nothing in this system takes a pass away and a hand refund in Stripe leaves it in place; the identity sentence says it is resolved before `MarkOrderPaid` and written inside its lock, with step 12a's database check named. `CLAUDE.md` is gitignored, so the change is local to this machine.
5. ~~**Step 5a, added 23 October 2026: is `PaidBy::$customerId` required `int` (A) or `?int` (B)?** Answer before step 5a starts.~~ **Answered 23 October 2026: A, required `int`.** `OrderBelongsToNobody` is deleted in step 5a and the three tests below change expectation by decision. Option B is kept below as history.
   - **A, recommended.** Every `PaidBy` names a customer, so PHP rejects a caller that names nobody, the same reasoning that made `PaidBy` itself required. `OrderBelongsToNobody` becomes unreachable and is deleted. Three tests that assert it change expectation by decision (`MarkOrderPaidTest.php:315-330`, `SettleAnUnownedOrderTest.php:227-236`, `SettleAGiftOrderTest.php:397-410`). Cost: no runtime check remains beyond the foreign key, and a deliberately defensive exception goes away.
   - **B.** `null` means "nobody resolved", and `MarkOrderPaid` keeps throwing `OrderBelongsToNobody` for a null `customerId` on an unowned order. The three tests stay as they are. Cost: a guard that no production caller can trigger, and a nullable field on the type the plan made strict so that mistakes fail loudly.
6. **Step 7, added 24 October 2026: does Livewire's own upload limit sit below 50 MB?** There is no `config/livewire.php`, so Livewire's default temporary upload rule applies, documented in its shipped config as `max:12288`, 12 MB. Filament's `maxSize` is checked after Livewire has accepted the temporary file. If the temporary upload goes straight to the bucket the rule may not bite; if it goes through the app, any master over 12 MB is already refused today. Answer before step 7 is built:
   - **A, recommended: find out first.** The user says whether a 20 to 40 MB master has been uploaded through the panel on staging (masters were described as that size in `config/services.php:47`, so it likely has). If yes, nothing changes and step 7 is built as written. If no, take B.
   - **B: publish the limit.** Step 7 also adds `config/livewire.php` setting `temporary_file_upload.rules` to `['required', 'file', 'max:51200']`, citing `CardForm::VIDEO_MAX_KILOBYTES`, so the two limits are one number. It is global: the 10 MB card picture field (`CardForm.php:67`) keeps its own `maxSize`, so nothing else loosens in practice. One more file in step 7, still one idea (the 50 MB cap).
   - Not recommended: leave it unknown. Jennifer would meet a 12 MB refusal with Livewire's generic wording, and acceptance criterion 8's 51200 KB "saves" case may not show it, because Livewire's test upload path may skip the temporary upload rule.

## Critique resolved

- **B1** (hand settlement then Stripe success read as `PaidTwice`): **Resolved** by removing "Mark as paid" (step 3) and making `PaidBy` required (step 5). `PaidTwice` now means exactly two distinct succeeded Stripe intents, stated in the `Settlement` rules and acceptance criterion 4. The first draft's hand branch and finding 4 are struck and dated.
- **B2** (a replacement save racing a promotion makes `ForgetCardVideo` delete the live film): **Resolved** by new step 9, placed before step 10. `ForgetCardVideo` refuses any id a card holds, soft-deleted included, with the race scripted as a test. It extends the critic's fix to the incoming column as well. That is safe for every existing caller and is listed.
- **B3** (steps 14, 15 and deploy order leaned on the backfill): **Resolved.** The backfill is deleted. Steps 16 and 17 state that pre-attempt pending orders are staging test data and are no longer swept or read. ~~Step 18's fallback is named as the sole route for pre-step-13 orders.~~ Step 18 has no fallback (decided 22 October 2026), so a pre-step-13 test order's dispute emails without an order. Step 19's nulling touches test data only. The deploy order no longer mentions it.
- **S1** (a throw between create and claim orphans the candidate): **Resolved** in step 11 with `try`/`catch`, a forget and a rethrow, tested through an extracted `claim()`.
- **S2** (the race test could pass vacuously): **Resolved** in step 11. Each child writes an outcome file, and every round must show two creates, one claim and one adopt-and-forget. A round with one create fails as "did not race".
- **S3** (keep the id-less attempt when Stripe throws): **Resolved** in step 13, with a test that a later webhook links it (step 14).
- **S4** (recapture after step 14 is mandatory, with `attemptId` asserted): **Resolved** as user step 15, red before the recapture and green after.
- **S5** (Session `expires_at` 30 minutes): **Resolved** in step 16, with the param tested and the expired sweep tested. Its contract prose effect (`API_CONTRACT.md:471`) and the customer-facing consequence are recorded.
- **S6** (failed jobs of the old shape cannot be retried): **Resolved** in step 8's "what could go wrong" and the deploy order.
- **Rejected:** none.

### Step 5a critique (23 October 2026)

Baseline `8323747`. All six points accepted; none rejected.

- **Blocker, the `MarkOrderPaidTest` failure case was green for the wrong reason.** At `8323747` `MarkOrderPaid.php:113-115` throws `OrderBelongsToNobody` (a `RuntimeException`) before `AskForReading` runs. **Resolved:** the test asserts the fake's exception by class and message (`'the asking failed'`) and that the named buyer's `Customer` row exists. The matching `SettleAnUnownedOrderTest` case now asserts the resolved `Customer` exists rather than "may exist". The first wording is struck in the step.
- **`new PaidBy(` has three callers, not two.** **Resolved:** `tests/Feature/MarkOrderPaidTest.php:263` is named, and it gets the order owner's id as its third argument. The two-caller sentence is struck.
- **The denial tests pass before implementation.** **Resolved:** the step states they are guards by design and names their audits: drop the `customer_id === null` condition and the pending-owned test goes red; move the owner write above the not-pending return and the `PaidTwice` and `AlreadyPaid` tests go red.
- **"The same single fresh read" did not exist.** **Resolved:** `$settled = $order->fresh()` once above the log, with both fields read from it. Old wording struck.
- **`identify()`'s docblock loses the "never overwritten" rule entirely.** **Resolved:** it keeps one sentence pointing at `MarkOrderPaid`.
- **Nits on the race test.** **Resolved:** its comment describes the scripted path (B finds X already written and skips Stripe identification), not the finding's step 2. The subclass is bound with plain `bind`, which is harmless because the hook is keyed on `pi_a` and B only asks about `pi_b`.
