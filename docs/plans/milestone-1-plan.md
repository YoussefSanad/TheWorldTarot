# Milestone 1: Project Setup, Auth, Locale/Currency Foundation, and Contract Endpoints

> **Historical record. Parts of this were later reversed.**
> Read `docs/CHECKPOINT.md` for what is actually true now.

> Shipped, then partly superseded. The polymorphic `prices` table, the config-driven locale
> list, the `Page` model and the translated slugs described here have all been removed.

## Context

The World of Tarot backend is a fresh Laravel 13 skeleton. The hygiene items from the earlier architecture review are done and pushed (git, Pest, Postgres, Mailchimp queuing note). Per `CLAUDE.md` section 0.2 and hard rule 10, no milestone code starts without an approved written plan committed to the repo at `docs/plans/milestone-N-plan.md`. This document is that plan for **Milestone 1**.

This plan incorporates `.claude/translation.md`, which specifies the multilingual architecture in far more detail than the `CLAUDE.md`/`database.md` summaries, and corrects several earlier assumptions.

**Goal:** Stand up the foundation the rest of the project builds on. Filament admin at `/admin` behind its own isolated auth, Sanctum configured for both cookie and token auth, the two-part locale registry (config declares capability, DB holds state) with a unified locale and currency request middleware, the config-driven translation pattern proven end to end on one real Filament resource using `jsonb` columns and the `lara-zeus/spatie-translatable` plugin, the polymorphic `prices` table and currency config, and the two frontend-contract endpoints (`GET /api/v1/languages`, `GET /api/v1/currencies`) deployed. Prove push-to-deploy to Laravel Cloud staging and production against an essentially empty app, so environment problems (cross-subdomain cookies, CORS, queue workers, migrations) surface now.

**Explicitly out of scope (owning milestone):** `cards` (M3), `reading_products` (M2), Stripe/Cashier (M5), Bunny/video (M4), OpenAI/AI (M7), full FAQ/legal content and versioning (M6). From `translation.md`, these are foundation-now, defer-later: the **coverage widget, 100 percent coverage promotion guard, slug translation and slug_history, orders.locale and localized email, and the legal-document fallback exception are all deferred** to the milestones that own the models they operate on. Coverage is meaningless with only one translatable model. The homepage card reveal is client-side only (`CLAUDE.md` section 5), no endpoint.

## Decisions settled with the client (this session)

1. **Translation proof resource is a minimal `Page` model**, borrowed deliberately from M6. `translation.md` section 12 flags "pages in DB vs frontend repo" as still unconfirmed. We keep Page as the proof vehicle regardless. If M6 later moves pages to the frontend, the pattern is already proven and transfers to cards/products with no loss. Flagged as an intentional scope-borrow per hard rule 9.
2. **Separate `admins` table and dedicated Filament guard**, not a role flag on the customer table. Structurally removes the "customer accidentally becomes admin" failure mode.
3. **Rename default `users` to `customers`** (`User` model to `Customer`). Done now while the app is near-stock and tables are empty.
4. **Locale registry uses three states** (`available`/`preview`/`live`), per `translation.md` section 4, resolving its section 12 open decision.
5. **Foundation now, coverage later.** Build the locale registry, unified middleware, jsonb, plugin, and Page proof in M1. Defer coverage widget and promotion guard to M2/M3.
6. **Laravel Cloud setup is the client's action item.** Prepare the app to be deploy-ready and write an exact dashboard runbook. The client creates the account/environments and connects push-to-deploy.

## Key conventions locked by translation.md (apply throughout)

- **`jsonb`, never `json`** for translatable columns. Laravel's `$table->json()` emits un-indexable `json` on Postgres. jsonb is required for the slug expression indexes that arrive in later milestones. Use a raw `jsonb` column definition.
- **Filament plugin: `lara-zeus/spatie-translatable`** (the `filament/spatie-laravel-translatable-plugin` is v3-only and unmaintained). **No repeater fields on translatable resources.**
- **Locale reaches the API via `?locale=` query param**, never headers (Cloudflare cache-key cleanliness). The backend never guesses a locale: explicit param, validate, safe fallback to `config('localization.default')`.
- **One middleware resolves both locale and currency** (`translation.md` section 6, "Do not build two"). Same shape, per-request context from an explicit parameter with a safe fallback.
- Config declares capability, the DB holds state. A `locales` row whose `code` is absent from `config.available` is treated as nonexistent regardless of state. Config always wins.

## Implementation steps

Each step is one coherent commit with tests written in the same step (`CLAUDE.md` section 0.4). Report actual results after each. Wait for go-ahead before continuing unless told to run through several.

### Step 1: Commit this plan
Write this document to `docs/plans/milestone-1-plan.md` and commit. No code.

### Step 2: Rename `users` to `customers`
- Rename migration `0001_01_01_000000_create_users_table.php`: table `users` to `customers`. Leave the `sessions.user_id` column as-is (Laravel session schema, not the auth table) unless renaming is trivial.
- `app/Models/User.php` to `Customer.php` (class `User` to `Customer`).
- `config/auth.php`: providers model to `App\Models\Customer::class` (rename provider key to `customers`).
- `UserFactory` to `CustomerFactory`. Update `DatabaseSeeder`. Grep `app/`, `tests/`, `database/` for `User::` and `use App\Models\User`.
- **Tests:** `Customer` factory persists. `migrate:fresh` clean on Postgres.

### Step 3: Install Sanctum, configure dual auth and CORS
- `php artisan install:api` (scaffolds `routes/api.php`, installs Sanctum v4.3). `Customer` gets `HasApiTokens`.
- `config/cors.php`: allow frontend origin **with `supports_credentials: true`** (cookie auth breaks silently otherwise). `config/sanctum.php`: stateful domains include frontend and staging origins.
- `.env.example`: document `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN=.theworldtarot.com` (prod), frontend URL vars.
- **Tests:** a protected `/api/v1` route returns 200 with a valid token, 401 without.

### Step 4: Install Filament with a dedicated `admins` guard
- Install Filament v4, panel at `/admin`.
- Migration `create_admins_table` (name, email, password, timestamps). `App\Models\Admin` implements `FilamentUser` with `canAccessPanel()`.
- `config/auth.php`: add `admin` guard (session driver) and `admins` provider. Point the panel at the `admin` guard, isolated from the Sanctum/customer surface.
- Artisan command to create the first admin (prompt/generate credentials, never hardcode).
- **Tests:** an `Admin` reaches `/admin`. A `Customer` cannot. `canAccessPanel()` denial path covered.

### Step 5: Locale registry and translation pattern proven on `Page`
- **`config/localization.php`**: `default => 'en'`, `available => ['en' => ['label' => 'English', 'native' => 'English']]`. Adding a locale here is the developer gate, never schema-driven (hard rule 4).
- Migration `create_locales_table`: `code` (unique), `state` (string plus PHP enum cast `available|preview|live`, per `database.md` enum convention), `sort_order`, timestamps. `App\Models\Locale` with a `LocaleState` enum. Seed `en` as `live`.
- Migration `create_pages_table`: `id`, `slug` (unique, non-translatable), `title` and `body` as **`jsonb`** translatable columns, timestamps, soft deletes. `App\Models\Page` uses `Spatie\Translatable\HasTranslations`, `$translatable = ['title','body']`.
- Filament `PageResource` via `lara-zeus/spatie-translatable`: one language tab per **enabled** locale, driven by config so a second language's tab appears with no code change or migration. Plain-language labels and helper text (`filament.md`). No repeaters.
- **Deferred and noted in plan:** coverage widget, promotion-to-live guard, slug translation and `slug_history`. These land when multiple translatable models exist (M2/M3).
- **Tests:** `Page` stores/returns per-locale values with default fallback. A `locales` row whose `code` is absent from `config.available` is treated as nonexistent. The Filament resource creates/edits/persists translations per language. Flipping config to add a second language exposes a second tab **without a migration**. Translatable columns are `jsonb` (assert column type).

### Step 6: Unified locale and currency middleware, `prices` table, currency config
- Migration `create_prices_table`: `id`, `priceable_type`, `priceable_id`, `currency_code` (string), `amount` (**integer cents**, hard rule 5), timestamps. Unique index on (`priceable_type`, `priceable_id`, `currency_code`). `App\Models\Price` plus a `Priceable`/`HasPrices` morph trait (proven via a test-only bound model, no real priceable exists in M1).
- **`config/currencies.php`**: available `[USD $, EUR €, GBP £]`, country-to-currency map (EU to EUR, UK to GBP, else USD). No exchange rates anywhere.
- **One middleware** (for example `ResolveRequestContext`) resolving both locale and currency for the request:
  - Locale: `?locale=`, if `live` use it, if `preview` use it **only for an authenticated admin** else treat as absent, if absent/invalid/`available`/unknown use `config('localization.default')`. Never infer from headers.
  - Currency: explicit supplied currency (query/cookie) honored **without re-detecting**, otherwise derive from `CF-IPCountry` to currency, fallback USD.
- **Tests (non-negotiable, `testing.md`):** country-to-currency including USD fallback. Explicit currency not re-detected. `preview` locale served to admin, rejected for public (falls back to default). Unknown/empty locale falls back without error. Price stored/read as integer cents. Per-currency price resolution for a priceable.

### Step 7: The two contract endpoints
- `app/Http/Controllers/Api/V1/`, thin controllers, API Resources (never raw models), under `/api/v1/`.
- `GET /api/v1/languages` returns `[{code,label}]` from **config intersected with live `locales`** state. Single-item array (`en`) until a second locale goes live. Built as `[{code,label,enabled}]` per the original contract, then `enabled` was dropped on 25 July 2026: every entry is live by definition, so the flag was always true and implied the list could contain non-public languages.
- `GET /api/v1/currencies` returns `{available:[{code,symbol}], detected}` where `detected` comes from the middleware-resolved currency. `available` is cacheable, `detected` is per-visitor, see the Cloudflare cache key note in step 8.
- Shapes match `.claude/api.md` byte-for-byte (frontend contract).
- **Tests:** each endpoint's status, exact shape, and the fields the frontend depends on. `detected` respects `CF-IPCountry` and the explicit-override path. Output is an API Resource.

### Step 8: Laravel Cloud deploy-readiness (my prep) and dashboard runbook (client action)
- Prepare: `composer.json` runtime declarations (PHP version, open item Q1, extensions), Node version note for Filament assets, `.env.example` completeness, `queue:work` (database driver) and migration notes, and `docs/deploy/laravel-cloud-setup.md` with exact dashboard steps for staging and production, per-environment env vars, `SESSION_DOMAIN`/CORS values, and how to prove push-to-deploy against the empty app.
- Client executes: create the Laravel Cloud account/project and both environments, connect GitHub push-to-deploy, run the first deploy, measure Scale-to-Zero cold start (open item Q3).

## Open questions needing a decision

1. **Final PHP version target** on Laravel Cloud (`CLAUDE.md` section 8): **PHP 8.4**, decided 25 July 2026. `composer.json` requires `^8.4.1` (the real floor, since Symfony 8.1 requires 8.4.1) and pins `config.platform.php` to `8.4.1` so dependency resolution matches production rather than the local interpreter. Local remains 8.5.8, so the platform pin is what prevents drift. Two follow-ups at step 8: confirm Laravel Cloud offers 8.4, and consider installing 8.4 locally for true parity. (Resolved.)
2. **Exact frontend origins** for CORS and Sanctum stateful domains. Resolved for now: production domains are known from `CLAUDE.md` (`theworldtarot.com`, `staging-api.theworldtarot.com`). The Cloudflare Pages preview URL and confirmed local dev origin are not yet available, so step 3 makes CORS/Sanctum fully env-driven with `localhost:3000` as the local dev default and env placeholders for the preview/production origins to fill in later. (Unblocked.)
3. **Scale-to-Zero vs. always-warm replica.** Resolved by measuring cold start after step 8's first deploy. Not a blocker for steps 1 through 7. (Open, non-blocking.)
4. **First-admin email** for the step 4 command: `jennifersemail6064@gmail.com`. (Resolved.)

## Dependencies

- **Client:** Laravel Cloud dashboard execution (step 8), PHP version (Q1), frontend origins (Q2), admin email (Q4).
- **Frontend partner:** courtesy confirmation of the two endpoint shapes and the `?locale=`/`?currency=` param contract before they are frozen.
- **External:** none blocking M1 code. GoDaddy DNS/MX is a launch-time concern.

## Verification (end to end)

- `php artisan migrate:fresh` clean on local Postgres. All new tables present (`customers`, `admins`, `locales`, `pages`, `prices`). Translatable columns are `jsonb`. No SQLite anywhere.
- `./vendor/bin/pest` green. Every step's tests, denial paths covered (auth, admin access, preview-locale gating, currency fallback).
- `curl` locally: `GET /api/v1/languages` and `GET /api/v1/currencies` return exact `.claude/api.md` shapes. `CF-IPCountry: GB` yields `detected: "GBP"`. Explicit `?currency=USD` honored without re-detection. `?locale=` unknown falls back to `en`.
- Log into `/admin` as the seeded admin. Create and edit a `Page`, English tab persists. Flip the locale config to add a second language and confirm a second tab appears with **no migration**.
- A customer token authenticates a protected `/api/v1` route. Same route 401s without a token. A customer cannot reach `/admin`.
- After step 8: push-to-deploy lands on staging, migrations apply, a queue worker runs, endpoints respond over `staging-api.theworldtarot.com` with cookies/CORS behaving across the subdomain.

---

# Close-out, 25 July 2026

## What shipped

Steps 1 to 7 complete, step 8 complete on the code side and pending on the dashboard side.

| Step | State |
|---|---|
| 1. Plan committed | Done |
| 2. `users` renamed to `customers` | Done |
| 3. Sanctum dual auth and CORS | Done |
| 4. Filament with an isolated `admins` guard | Done |
| 5. Locale registry and translation proven on `Page` | Done |
| 6. Unified locale and currency middleware, `prices`, currency config | Done |
| 7. The two contract endpoints | Done |
| 8. Deploy readiness and runbook | Code and docs done. Dashboard execution outstanding |

Final local state: 8 migrations, tables `customers`, `admins`, `locales`, `pages`, `prices` plus framework tables. 74 tests, 149 assertions, all passing. Translatable columns confirmed `jsonb`.

## Deviations from the plan, and why

1. **Steps 3 and 4 landed in a single commit.** Against section 0.3. No functional consequence, noted so the habit does not repeat.
2. **The queue decision changed after the plan was written.** The plan specified the database driver and a `queue:work` process. Laravel Cloud sets `QUEUE_CONNECTION=cloud` when a managed queue is created, and its own documentation is explicit that a worker on the app cluster is killed mid-job when a Scale-to-Zero environment sleeps. Managed queues are now the decision, recorded in `CLAUDE.md` section 2 with the constraints they impose: at-least-once delivery, a 15 minute delay ceiling, and visibility timeouts that must exceed the slowest job.
3. **`enabled` was dropped from the `/languages` shape.** Every entry is live by definition, so the flag was always true and implied the list could contain non-public languages. `.claude/api.md` updated.
4. **No `LocaleResource` was built, and none will be.** Creating a locale row is meaningless without the config key and `lang/` files that only a deploy provides. Promotion to live is the one act that belongs to the client, and it lands as a guarded action on the coverage widget in M2 or M3. Reasoning in `docs/adding-a-language.md`.
5. **Scramble was added outside the plan.** Generated OpenAPI at `/docs/api`, gated behind an admin session. A scope addition, flagged and agreed at the time rather than slipped in.
6. **Two documents were written that the plan did not call for**, both because the frontend partner has no contract at all: `docs/api/frontend-contract.md` and `docs/adding-a-language.md`.
7. **`EncryptCookies` needed an exception for the `currency` cookie.** Found by a failing test. The frontend writes that cookie in plain text, so Laravel was discarding it as undecryptable and silently re-detecting over the customer's explicit choice. This would have shipped as a production-only bug.
8. **`statefulApi()` was missing from `bootstrap/app.php`.** Cookie auth could not have worked at all. Found while building the preview-locale admin path in step 6.

## Carried into milestone 2

- Confirm Laravel Cloud offered PHP 8.4, and consider matching it locally or adding CI on it. Nothing currently catches 8.5-only syntax before deploy
- Real frontend origins for CORS and Sanctum. Still placeholders
- Cold start measurement, which closes open question 3 and the always-warm decision
- The Cloudflare cache key rule for `/api/v1/currencies`, at DNS cutover only
- `docs/architecture/content-model.md` is referenced by `.claude/translation.md` and does not exist. Either write it in M2 alongside the first real content model, or remove the reference
- Coverage widget and the promotion-to-live action, deferred here because coverage is meaningless with one translatable model
