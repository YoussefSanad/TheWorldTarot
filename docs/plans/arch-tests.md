> **Status: approved by the user 10 October 2026. All 7 steps built 10 October 2026, awaiting close-out.** ~~In progress: step 1 under way.~~ ~~Drafted 10 October 2026, awaiting approval.~~ Open questions decided 10 October 2026.

# Standing rules as machine checks

## Goal

Several rules in `CLAUDE.md` hold today only because people and agents remember them: Stripe stays behind the payment seam (section 4), Cashier is gone (section 2), controllers render nothing (section 1, hard rule 1), money is never a float (hard rule 5), and there are no em dashes (section 7, hard rule 8). This plan turns each of those into a test in the ordinary suite, so breaking one goes red in `./vendor/bin/pest` and in CI instead of waiting for a reviewer to notice. It also fixes the 13 em dashes that are in `app/` today, because the check cannot land green while they are there. No payment code is refactored. Every allowlist below is written to pass against the code as it stands on 10 October 2026.

**No API contract change and no frontend impact.** `API_CONTRACT.md` is not touched. No route, response shape, schema or shared type changes. The one runtime string that changes is the seeded English default for the Month Ahead long description (step 6). That is copy, not contract, and it only reaches a database row that has no copy yet (see `ProductSeeder::fillMissingCopy`).

## Out of scope

| Excluded | Why, and where it belongs |
|---|---|
| Rules in `.claude/*.md` (testing, translation, database, external-services) | Gitignored and not available to this plan. A follow-up plan once someone can read them |
| Judgement-only rules: section 0 in full, hard rules 9 to 15 (12 to 14 especially) | They are about decisions and process, not code shape. Nothing static can check them |
| Hard rule 2 (signed video URLs), 4 (language without migration), 6 (signature before state change) as *behaviour* | Already proven by feature tests (`ViewingRoomTest`, `LocalePublishGateTest`, `StripeWebhookTest`, `StripeWebhookVectorTest`). Step 1 only fixes the *shape* of the webhook controller |
| Hard rule 7 (AI gate) | M7 is not built. Its plan owns the check |
| Refactoring any payment code so the allowlists can shrink | Separate decision. The allowlists here record today's shape so it cannot widen |
| Em dashes in `resources/`, `database/`, `tests/`, `docs/` | Deliberately out of scope (decision 2, 10 October 2026). The check covers `app/`, `config/`, `routes/` only. A separate decision if ever widened |
| The live database value of the Month Ahead long description | It is Jennifer's value once seeded, and `fillMissingCopy` does not overwrite it. Not decided on 10 October 2026, see decision 3. She can edit it in the panel |
| ~~`routes/web.php` returning `view('welcome')`. Open question 4~~ | Struck 10 October 2026: now exempt by decision, with the reason written beside the rule in step 4 |
| ~~`config/database.php:20` defaulting to SQLite. Step 7 only if option A~~ | Struck 10 October 2026: option A chosen, now step 7 |

## Acceptance criteria

1. `./vendor/bin/pest tests/Unit/Architecture` passes, with the skip count reported as 0.
2. A new controller under `App\Http\Controllers` that imports or references any `Stripe\` class makes `./vendor/bin/pest --filter="Stripe"` fail, and the failure names the offending class. Shown by a probe class during step 1, recorded in the step report.
3. `StripeWebhookController` referencing any Stripe class outside `Dispute`, `Event`, `PaymentIntent`, `Exception\SignatureVerificationException`, `Exception\UnexpectedValueException` (for example `Stripe\Webhook`) fails the suite. `OrderPaymentStatusController` referencing anything but `Stripe\Exception\ApiErrorException` fails the suite. Shown by temporary edits during step 1.
4. Any class in `App` outside the twelve listed in step 2 that references `Stripe\` fails the suite. Any class outside the seven listed in step 2 that references `Stripe\StripeClient` fails the suite. Shown by a probe during step 2.
5. Any reference to `Laravel\Cashier` in `App`, or `laravel/cashier` in `composer.json`, fails the suite. Shown by a probe during step 3.
6. A controller that uses the `View` or `Blade` facade, a view contract or factory, or the `view()` helper fails the suite. Shown by a probe during step 4.
7. Against the migrated test database, no column in the `public` schema has `data_type` `real`, `double precision`, `numeric` or `money`. A temporary migration adding a `float` column makes the test fail and name the table and column. Shown during step 5.
8. `grep -rn "—" app config routes` returns nothing. A test fails, listing `file:line`, for any em dash (U+2014, or the entities `&mdash;` / `&#8212;`) under `app/`, `config/` or `routes/`. The test was seen red listing exactly the 13 known hits before they were fixed.
9. After step 6, `ProductKey::MonthAhead->defaultLongDescription()` contains no em dash and says the same thing it said before (wording approved 10 October 2026, in step 6).
12. With `DB_CONNECTION` unset, `config('database.default')`, `config('queue.batching.database')` and `config('queue.failed.database')` resolve to `pgsql`. Shown by step 7's test, seen red against today's `'sqlite'` defaults first.
10. The full suite `./vendor/bin/pest` passes after every step, and `./vendor/bin/pint --test` passes. Each step report states passed, failed and **skipped** counts, and any skip count that differs from the previous step is explained. A skipped test looks exactly like a pass.
11. `git status` is clean of probe files at every commit. No probe is ever committed.

## Contract changes

None. No API, schema, event or shared type changes. `API_CONTRACT.md` is unchanged and the frontend repo needs nothing.

## How every step proves its check bites

Steps 1 to 5 add checks against code that already complies, so they **pass the first time they run**. Under section 0.5 that proves nothing. The red run for these steps is a **probe**:

1. Write the test. Run it green against today's code (expected, since the code complies).
2. Add a throwaway violation in the namespace the rule guards, e.g. `app/Http/Controllers/Api/V1/ArchProbeController.php` with `use Stripe\StripeClient;` and a method that names it.
3. Run the single test. It must fail, **for the right reason**: the message names the probe and the rule. A failure because the probe does not autoload, or a syntax error, does not count.
4. Delete the probe, run green again, confirm `git status` shows no probe.
5. Paste the red output into the step report (and the issue, per hard rule 15).

Step 6 needs no probe: the 13 existing hits are its red run.

Test command: `./vendor/bin/pest` for everything; a single test by `./vendor/bin/pest --filter="<description fragment>"` or by path. **Step 1 confirms `--filter` actually selects `arch()` tests** before any later step relies on it (it was not verifiable while drafting).

## Steps

All steps live in the backend repo (`theworldtarot-backend`). Arch tests go under `tests/Unit/Architecture/`, which `phpunit.xml`'s existing `Unit` suite already picks up, so no config change is needed. They do not need the application booted; `tests/Pest.php` only extends `TestCase` in `Feature`, which is fine. Each rule carries a comment naming the `CLAUDE.md` section it enforces and why each allowlist entry is there, per section 0.6.

### Step 1. Stripe in controllers

**Commit:** `Only the webhook and the payment status controller may name a Stripe class, and only the ones they already use`

**Builds** `tests/Unit/Architecture/StripeBoundaryTest.php` with three `arch()` rules:

- `App\Http\Controllers` does not use `Stripe`, ignoring exactly `App\Http\Controllers\Api\V1\StripeWebhookController` and `App\Http\Controllers\Api\V1\OrderPaymentStatusController`.
- `StripeWebhookController` does not use `Stripe`, ignoring exactly `Stripe\Dispute`, `Stripe\Event`, `Stripe\PaymentIntent`, `Stripe\Exception\SignatureVerificationException`, `Stripe\Exception\UnexpectedValueException`. Comment: these are type checks and the exceptions `StripeSignature::verify` throws; verification itself lives in `App\Payments\StripeSignature`, which is why `Stripe\Webhook` is not on the list.
- `OrderPaymentStatusController` does not use `Stripe`, ignoring exactly `Stripe\Exception\ApiErrorException`.

**As built (10 October 2026):** the second and third rules are the exact-match source scan in the resolution note under "What could go wrong". The first rule's `ignoring()` still prefix-matches the two controller class names, a known small gap.

**Tests that prove it:** the three rules green on today's code; then three probes, each seen red: a new controller using `Stripe\StripeClient`; `use Stripe\Webhook;` plus a reference added to `StripeWebhookController`; `use Stripe\StripeClient;` plus a reference added to `OrderPaymentStatusController`. Also confirm `--filter="Stripe"` runs these tests and only these plus any other test whose name matches (report what it selected).

**What could go wrong:**
- **`ignoring()` may match by prefix.** If it does, ignoring `Stripe\Event` also ignores `Stripe\Events\...` and `Stripe\EventData\...`, both real namespaces in stripe-php 21. Add a fourth probe referencing a class under `Stripe\Events\` from the webhook controller. If it slips through, replace the per-controller rules with a test that collects every `Stripe\` name the controller references (parse `use` lines and fully qualified names) and asserts set equality with the allowlist, and say so in the report.
  - **Resolved 10 October 2026: `ignoring()` matches by prefix**, verified by probe. The per-controller `ignoring()` rules are therefore ~~used~~ replaced by the exact-match source-scan test described above, which asserts set equality between the Stripe names each of the two controllers references and its allowlist.
- `ignoring()` applies to targets and dependencies alike in pest-plugin-arch; the first rule relies on the target side, the other two on the dependency side. The probes are what confirm both.
- Pest's arch parser may not see a name used only in a `catch` clause. The `ApiErrorException` probe direction covers this: if removing the allowlist entry does not turn the rule red, the parser is blind there and the report must say so.

### Step 2. Stripe elsewhere in the application

**Commit:** `Outside the two controllers, Stripe is reachable from ten named classes and no others`

**Builds** two more rules in `StripeBoundaryTest.php`. The tightest allowlist that passes today is exact classes, not namespaces:

- `App` does not use `Stripe`, ignoring the two controllers from step 1 and:
  - `App\Providers\AppServiceProvider` (binds the one `StripeClient`; `AdminPanelProvider` does not need Stripe, so the namespace `App\Providers` is not allowed)
  - `App\Services\Payments\StripeCheckout`, `StripeWallet`, `BuyerFromCheckoutSession`, `BuyerFromLatestCharge` (not `PaymentMethods`, `ManualSettlement`, `UnknownPaymentMethod`)
  - `App\Payments\StripeSignature`, `StripePayment`, `PaymentStatus` (not the other six classes in `App\Payments`)
  - `App\Actions\ReadPaymentStatus`
  - `App\Console\Commands\ReconcileOrders`
- `App` does not use `Stripe\StripeClient`, ignoring `AppServiceProvider`, `StripeCheckout`, `StripeWallet`, `BuyerFromCheckoutSession`, `BuyerFromLatestCharge`, `ReadPaymentStatus`, `ReconcileOrders`. This is tighter than the line above: `StripeSignature`, `StripePayment` and `PaymentStatus` may name Stripe types but may not hold the client.

This also covers `App\Contracts`: `PaymentGateway` naming any Stripe concept fails, which is section 4's "a vendor's concepts appearing in the interface means the seam is in the wrong place".

**The candidate "`StripeClient` only in `App\Providers`" fails today**: six classes take it by injection. "Only *constructed* in the provider" is what is true, and arch cannot tell construction from a type hint. Not added; noted here so nobody re-proposes it as written.

**Tests that prove it:** green on today's code; probes seen red: `App\Models\Order` gaining a `Stripe\PaymentIntent` reference; `App\Payments\StripePayment` gaining a `StripeClient` reference; a new `App\Services\Payments\ArchProbe` naming `Stripe\Charge` (proves the list is exact classes, not the namespace).

**What could go wrong:** the exact-class list means the next legitimate Stripe adapter needs a one-line allowlist edit. That is intended: the edit is visible in review. Exact classes rather than the `App\Services\Payments` namespace was decided 10 October 2026 (decision 5).

### Step 3. Cashier cannot return

**Commit:** `Cashier cannot come back without a test failing`

**Builds** `tests/Unit/Architecture/SupersededPackagesTest.php`: ~~an `arch()` rule that `Laravel\Cashier` is not used in `App`, and~~ a plain test that `composer.json`'s `require` and `require-dev` have no `laravel/cashier` key. Comment cites section 2's "superseded, treat as bugs".

**As built (10 October 2026):** following the step 1 finding that `ignoring()` matches by prefix, the `arch()` rule was replaced by a case-insensitive source scan for `Laravel\Cashier` across `app/`, `config/`, `routes/` and `bootstrap/`, alongside the `composer.json` guard above.

**Tests that prove it:** green today; probes seen red: ~~a class in `App` with `use Laravel\Cashier\Billable;` referenced (the package is not installed, so this also confirms arch flags names that do not autoload)~~ a probe file in `app/` referencing `Laravel\Cashier`; a temporary `"laravel/cashier": "*"` line in `composer.json` (do not run composer). Both were seen red on 10 October 2026. The `arch()` probe for a package that is not installed was not run, because the scan is at least as strong and does not depend on autoloading.

**What could go wrong:** if arch ignores names that do not resolve, the first probe stays green. Then the rule is decoration and is replaced by the `composer.json` check plus a source scan for `Laravel\Cashier`, reported as such.

### Step 4. Controllers render nothing

**Commit:** `A controller cannot render a view`

**Builds** `tests/Unit/Architecture/NoRenderedPagesTest.php`: ~~`App\Http\Controllers` does not use `Illuminate\Support\Facades\View`, `Illuminate\Support\Facades\Blade`, `Illuminate\View`, `Illuminate\Contracts\View`, or the function `view`.~~ a case-insensitive source scan of `app/Http/Controllers` for those same names (`Illuminate\Support\Facades\View`, `Illuminate\Support\Facades\Blade`, `Illuminate\View`, `Illuminate\Contracts\View`) and the `view()` helper, with self-tests proving each pattern matches what it is meant to catch.

**As built (10 October 2026):** a source scan rather than an `arch()` rule, because `arch()`/`not->toUse` matches by prefix and cannot see the `view()` helper. The `routes/web.php` welcome exemption below is kept as a comment, and the scan never reads `routes/`.

Comment cites section 1 and hard rule 1, and says why mail views are allowed (Mailables are not controllers; `app/Mail` renders `resources/views/mail`).

**The welcome page is exempt, and the rule says so in a comment** (decision 4, 10 October 2026). The comment sits above the rule and states: `routes/web.php` returns `view('welcome')` at `/`; it is the stock Laravel skeleton page, not something the brand's customers are sent to; it is a route closure rather than a controller, so this rule does not see it; it is left in place deliberately, decided 10 October 2026; and moving it into a controller would make this rule fail, which is the intended prompt to revisit. No test asserts anything about `/` either way.

**Tests that prove it:** green today (no controller uses any of them; checked while drafting). Probes seen red: a controller returning `view('welcome')`; a controller using the `View` facade.

**What could go wrong:** arch support for *functions* as dependencies is the uncertain part. If the `view()` probe stays green, drop `view` from the rule, say so, and add the helper to step 6's text scan scoped to `app/Http/Controllers` instead (`/\bview\(/`). Route closures are not classes and are not covered; the one that exists today, the welcome page, is exempt by decision (above). If the `view()` fallback scan is used, it is scoped to `app/Http/Controllers` and so leaves `routes/web.php` alone too.

### Step 5. Money is never a float

**Commit:** `No database column can hold money as a float`

**Builds** `tests/Feature/NoFloatingPointColumnsTest.php` with `RefreshDatabase`: query `information_schema.columns` for the `public` schema where `data_type` is in `real`, `double precision`, `numeric`, `money`, and assert the list is empty; on failure print `table.column (type)`. Checking the migrated schema rather than migration text catches raw `DB::statement` SQL too. Pattern follows the existing `information_schema` checks in `ProductTest` and `CardTest`. Comment cites hard rule 5 and `App\Support\Money` (integer minor units). No migration today uses `float`, `double` or `decimal` (checked while drafting), so no column is excluded.

**Tests that prove it:** green today; probe seen red: a temporary migration adding `$table->float('arch_probe')->nullable()` to `orders`. Delete the migration before committing.

**What could go wrong:** a future non-money `numeric` column (a ratio, say) trips the test. That is acceptable: whoever adds it must add a commented exclusion, which is the review moment wanted. PHP-level floats (`(float)` casts, `'float'` Eloquent casts) are not checked; arch cannot read casts. `grep float app` finds only a comment in `Money.php` today.

### Step 6. No em dashes in app, config, routes

**Commit:** `No em dash survives in app, config or routes, and a test keeps it that way`

**Builds** ~~`tests/Unit/NoEmDashTest.php`~~ `tests/Unit/Architecture/NoEmDashTest.php` (path corrected 10 October 2026): walks `app/`, `config/`, `routes/` recursively (all files, not only `.php`), and fails listing `path:line` for every line containing U+2014, `&mdash;` or `&#8212;`. Arch cannot read text, which is why this is a plain test. Comment cites section 7 and hard rule 8. Then rewrites the 13 hits with commas or periods, keeping the meaning.

**Red run:** write the test first and run it. It must fail listing exactly these 13 lines. If it lists more or fewer, stop and find out why before fixing anything.

| File:line | Kind | User-facing? |
|---|---|---|
| `app/Enums/ProductKey.php:127, 155, 287` | Docblock | No |
| `app/Enums/ProductKey.php:311` | Seeded English long description for Month Ahead | **Yes**, product page copy via `ProductSeeder` and the products API |
| `app/Mail/GiftToRedeem.php:45, 103, 114` | Docblock | No (not mail copy; the mail body is in the Blade views) |
| `app/Mail/ReadingOnItsWay.php:24, 25` | Docblock | No |
| `app/Services/Payments/StripeWallet.php:95, 105` | `//` comment | No |
| `app/Http/Controllers/Api/V1/ProductController.php:32, 33` | Docblock | No |

**Scope is `app/`, `config/`, `routes/` and nothing else** (decision 2, 10 October 2026). Known hits in `resources/` (Blade comments), `database/` (migration and seeder comments), `tests/` and `docs/` are deliberately left alone and the test must not scan those directories. Its comment says so, so nobody widens it in passing.

So **one of the 13 is user-facing**. Rewrite of line 311, period instead of the dash, **approved as written 10 October 2026** (decision 3):

> Five cards for the month in front of you. Its shape, its turning point, and what it is asking of you. A forecast to keep and return to as the weeks unfold, rather than an answer to a single question.

`GiftToRedeem.php:103` sits next to struck-through text (`~~...~~`). The rewrite keeps the strike-through intact and only replaces the dash, per section 0.6.

**Tests that prove it:** red with the 13; green after; `ProductSeederTest` and `ProductsEndpointTest` still pass ~~(they read `defaultLongDescription()` rather than a literal, checked while drafting)~~. `database/data/translations/es-products.php` has no em dash and its Spanish still matches the meaning.

**As built (10 October 2026):**
- **The drafting claim above was wrong.** `ProductSeederTest` and `ProductsEndpointTest` use fixtures and do not read `defaultLongDescription()`. No test asserts the Month Ahead text, so the approved rewrite is held by review, not by a test.
- 12 of the 13 em dashes were in comments. Only `ProductKey.php:311` was user-facing, as the table says.
- A few of the comment rewrites use parentheses or colons rather than commas or periods. Accepted: section 7 forbids the dash, and the meaning is unchanged.

**What could go wrong:** a rewrite that changes meaning, especially in the docblocks that explain reversed decisions. Read each sentence whole before and after. Existing database rows seeded with the old Month Ahead copy keep the dash; this plan does not touch them (see decision 3).

### Step 7. Postgres by default

~~Only if open question 1 is answered A.~~ Struck 10 October 2026: option A chosen (decision 1), so this step is in the plan unconditionally.

**Commit:** `The database defaults to Postgres when nothing says otherwise`

**Builds:** three defaults change from `'sqlite'` to `'pgsql'`: `config/database.php:20` (`default`), `config/queue.php:106` (`batching.database`) and `config/queue.php:125` (`failed.database`). Each gets a one-line comment citing section 6. The comment in `.github/workflows/tests.yml` (lines 68 to 71) about falling back to SQLite is updated to say what now happens. A test in `tests/Unit/DatabaseDefaultsTest.php` unsets `DB_CONNECTION` (in `$_ENV`, `$_SERVER` and `putenv`), requires each config file, asserts all three values are `pgsql`, and restores the variable. ~~A Feature assertion that `DB::connection()->getDriverName()` is `pgsql`.~~

**As built (10 October 2026):** the test is `tests/Unit/DatabaseDefaultsTest.php` and boots `Tests\TestCase`. The `getDriverName()` assertion is in the same Unit file, not a Feature test. It only reflects `phpunit.xml`'s `DB_CONNECTION`, not the config default. The defaults changed in `config/database.php` and `config/queue.php`, and the CI workflow comment was updated.

**Red run:** the Unit test fails against today's `'sqlite'` default before the config edit. This one has a real red.

**What could go wrong:** Laravel's `Env` repository may cache. If unsetting does not change what `env()` returns, the test is asserting nothing; prove it by watching it fail on the old default. CI's "Prepare the environment" step must stay, because `.env.example` sets credentials the default cannot.

## Decisions, 10 October 2026

There are no open questions left. The five questions from the 10 October draft were answered on 10 October 2026 ("your recommendations"). Each one is recorded below with what it replaced, per section 0.6.

1. **SQLite default in `config/database.php:20`: option A.** The defaults change to `pgsql` and a config test is added. That is step 7, which is now unconditional. It also covers `config/queue.php:106` and `:125`. ~~Option B, leave the defaults and assert only the test driver~~, rejected.
2. **Em dash scope: `app/`, `config/`, `routes/` only.** `resources/`, `database/`, `tests/` and `docs/` are deliberately out of scope. ~~The draft recommended a follow-up widening to `resources/` and `database/`~~. That follow-up is not part of this plan. The known hits there, for whoever reopens it: `resources/views/mail/html/frame.blade.php:36`, `gift-to-redeem.blade.php:70, 153, 175` (Blade comments, not rendered); `database/migrations/2026_08_20_000000_record_when_product_defaults_were_seeded.php:18`; `database/seeders/ProductSeeder.php:33, 51, 64, 65`; `tests/Feature/MailBodyTest.php:581, 595, 608` at least; and many in `docs/`.
3. **Month Ahead rewrite: approved as proposed** in step 6. ~~The draft also recommended a conditional data migration for rows already seeded with the old text~~. The answer recorded on 10 October 2026 does not include that, so it is **not in this plan**. Existing rows keep the dash until somebody edits them in the panel.
4. **`routes/web.php` welcome page: exempt.** Step 4 carries a comment beside the rule that explains the exemption. ~~Remove it, or leave it~~. It is left, and the reason is written down.
5. **Step 2 allowlist: exact classes**, not the `App\Services\Payments` namespace.
