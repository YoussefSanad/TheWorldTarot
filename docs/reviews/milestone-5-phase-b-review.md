# Phase B review, findings to work

Review of `git diff a52656b...HEAD` on `spec/phase-b`, 26 August 2026. Two axes, standards and spec.

Read `docs/specs/milestone-5-phase-b-spec.md` for what was meant to be built. Do not re-derive it here.

## State

Branch `spec/phase-b`, clean, unmerged, undeployed. Suite **1006 tests, 1003 passing, 3 skipped** (`./vendor/bin/pest`). Staging suite **41 tests, 41 skipped** unconfigured (`composer test:staging`).

```
0ede574  6. staging walk, card path
57dcb9d  5. reconciliation sweep
b50bf56  4. webhook
6a0b9bf  3. StripeCheckout and pay endpoint
7500a0f  2. columns on orders
051c6a1  1. payment method registry
a52656b  merge base
```

## Findings

`V` = verified in this session. `R` = reported by review agent, not independently checked.

| # | Sev | V/R | Where | Problem | Fix |
|---|---|---|---|---|---|
| 1 | P1 | V | `config/payments.php:59` | `env('STRIPE_SECRET') === null` is false for `STRIPE_SECRET=` as `.env.example` ships it, so `stripe` is registered on a laptop with no keys. Contradicts the file's own comment and spec section 1 | Use `blank()` rather than `=== null` |
| 2 | P2 | V | `tests/Fixtures/stripe/` | No captured vector. All 3 `StripeWebhookVectorTest` tests skip. Binding rule in `.claude/external-services.md` | Human only. See below |
| 3 | P2 | V | `routes/console.php` | Spec section 5 required the scheduler tick be measured on staging as part of the step. Not done, `everyFiveMinutes()` is an assumption | Human only. See below |
| 4 | P2 | V | `tests/Staging/CardPaymentWalkTest.php` | 4/4 skip, never executed | Human only. See below |
| 5 | P2 | V | same file | `confirmAtStripe()` hits the live Stripe API. `.claude/testing.md` forbids reaching a real service from a test. `phpunit.staging.xml` carves out the deployed environment, never a vendor | Decide: doc carve-out, or redesign. Do not silently keep |
| 6 | P2 | R | `PayOrderController::answer()` | Returns `response()->json(...)`, not an API Resource. `.claude/api.md` says always return a Resource | Wrap in a Resource, or state the exception |
| 7 | P2 | R | `PayOrderController` -> `StripeCheckout::begin()` | Synchronous third party call in a request. `.claude/api.md` wants slow downstreams queued. Likely unavoidable for a client secret | Hard rule 14: state the departure in the file |
| 8 | P3 | V | `StripeCheckout` | `$order->status !== OrderStatus::Pending` duplicates `Order::isPending()`, added in this same diff | Use `isPending()` |
| 9 | P3 | V | `PaymentInstruction` | Raw string tags re-matched in `toArray()`. Repo convention is a PHP enum | Enum, or leave and note why |
| 10 | P3 | V | `PaymentInstruction::redirectTo()`, `PaymentMethods::offered()`/`offers()` | No production caller, tests only. Speculative generality | Delete, or justify |
| 11 | P3 | V | `ReconcileOrdersTest::stripeHolding()` vs `StripeCheckoutTest::stripeAccepting()` | Same Mockery `StripeClient` shape built twice | Extract one helper |
| 12 | P3 | V | `2026_08_25_..._add_payment_columns_to_orders.php` | `Order::withoutEvents()` wraps `DB::table()` writes, which never fire model events | Drop the wrapper |
| 13 | P3 | R | `API_CONTRACT.md` | Gained a published Gift codes section. Spec lists gifting as out of scope | Remove or confirm intended |
| 14 | P3 | V | `StripeCheckout::refuseAnythingButAPendingOrder` | Second refusal on `totalOfLines() !== total_amount` not in spec section 3 | Defensible. Confirm, do not silently drop |

### Correction to the review itself

Spec agent claimed `ManualSettlement` has no test coverage, which step 1's evidence depended on. **Overstated.** The specific test `resolves the gateway from config` is gone at HEAD, but `tests/Feature/PaymentMethodsTest.php` still exercises `ManualSettlement`. The evidence is weaker than the spec claims, not absent.

## Verified clean, do not re-check

Raw `getContent()` reaches the verifier. Endpoint passes no tolerance argument and there is no config knob, both pinned by tests. Missing signing secret refuses everything. Unknown order and amount mismatch both answer 200 and log. Sweep is 15 minutes, driven by our own table, non-cancelling, `everyFiveMinutes()->withoutOverlapping()`. `PlaceOrder` and `MarkOrderPaid` untouched.

## Blocked on a human, not on code

Findings 2, 3 and 4. None is fixable from a checkout.

1. **Capture the Stripe vector.** `stripe listen` against Jennifer's account in test mode. Procedure in `tests/Fixtures/stripe/README.md`. **Do not fabricate it.** Computing the expected signature from our own code is the `BunnyStreamTest` failure that cost five days in M4
2. **Run the card walk.** Needs a deployed environment with `STRIPE_SECRET`, a webhook endpoint registered at Stripe pointing at it, and that account's `pk_test_` key. See `tests/Staging/README.md`
3. **Measure the scheduler tick** on a deployed environment, then correct `routes/console.php`

Until 1 and 2 happen, no evidence exists that this system can take a payment.

## Settled, do not re-litigate

- `config/payments.php` carrying a `default` key. Something must answer a caller naming no method
- `POST /api/v1/orders` no longer starting a payment, returning `pay_token` not `payment_url`. Spec section 3 re-specifies that response itself, so "untouched" means the request contract, which is intact
- The sweep at five minutes. Chosen deliberately over daily, reasoning in `routes/console.php`

## Rules that bind

`CLAUDE.md` section 9, hard rules 1 to 14. Most relevant here: no em dash anywhere including comments (8), tests in the same step (11), present options and wait on anything hard to reverse (12), never weaken a test to make it pass (13). Report actual test results and read a skip as a skip. No Claude attribution in commit messages. Format with `./vendor/bin/pint <paths>` before committing.

## Suggested order

1 first, it is the only live defect. Then 5 and 7, which are decisions rather than typing. Then 6. Then the P3s as one cleanup commit. 2, 3 and 4 need a person with access.
