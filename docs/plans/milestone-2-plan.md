# Milestone 2, products, translatable content and per-currency pricing

> **Historical record. Parts of this were later reversed.**
> Read `docs/CHECKPOINT.md` for what is actually true now.

> Shipped. Its slug work, coverage widget deferral and `?locale=` assumptions were replaced
> by the translation rebuild days later.

Originally a draft for revision. Per `CLAUDE.md` section 0.2 and hard rule 10, implementation starts only once this is approved.

Rewritten 25 July 2026 after the design discussion that replaced per-type product tables and the polymorphic `prices` table with a single `products` table carrying its prices as columns. The reasoning is recorded in `.claude/database.md`, not repeated here.

---

## Current state, read from the code as it is

- **Translation pattern proven** on `Page`: `jsonb` columns, `spatie/laravel-translatable`, Filament tabs driven by `config/localization.php`. No migration needed to add a language
- **Locale registry** in place, three states, config always winning over database state
- **Request context middleware** resolves locale and currency once per request and binds them
- **Two public endpoints** live with exact-shape tests, plus generated API docs at `/docs/api`
- **`prices` table, `Price` model and `HasPrices` trait exist and are now obsolete.** Built in M1, never attached to a real model, superseded by this design. Step 2 removes them
- 74 tests passing, 8 migrations, no deploy yet

Deferred from M1 and now due: translated slugs with `slug_history`, and prices visible to Jennifer for the first time. Still deferred: the coverage widget, see open question 2.

## Goal

Give the four reading products a real content model that Jennifer owns end to end: translatable name and copy, a translated public slug, and a fixed price per currency she types herself, with no conversion anywhere. Build it as one `products` table that everything sellable will use, so the viewing room pass in M4 and the rush add-on in M5 are rows rather than new tables. Expose reading products to the frontend as locale-aware and currency-aware read endpoints returning exactly one price. Nothing in this milestone sells anything.

## Explicitly out of scope

| Not here | Owned by |
|---|---|
| Checkout, Stripe, orders, order line items, the customer's question at purchase | M5 |
| The viewing room pass row and the customer entitlement stamp | M4 seeds the row, M5 grants the stamp |
| The 24 hour rush add-on row | M5 |
| AI fulfilment of the One Card Reading | M7 |
| Cards, the Library, the Viewing Room | M3 and M4 |
| FAQ and legal content | M6 |
| Product imagery, settled as fixed design artwork | The frontend repo |
| Coverage widget and promotion-to-live guard | M3, see open question 2 |

## Steps

Each step is one commit, tests in the same step, reported before the next begins.

### Step 1, commit this plan
No code.

### Step 2, remove the superseded pricing scaffolding
- Drop the `prices` table, delete `App\Models\Price`, `App\Models\Concerns\HasPrices`, `tests/Feature/PriceTest.php` and the `PriceableThing` fixture
- **Tests:** the suite stays green with those removed, `migrate:fresh` clean
- **Risk:** none. Nothing references them. Doing this first keeps the next step from being read against a design that no longer applies

### Step 3, the `products` table, model and enums
- Migration: `key` (unique), `type`, `fulfilment`, `allows_question`, `slug`/`name`/`short_description`/`long_description` as `jsonb`, `price_usd`/`price_eur`/`price_gbp` as `NOT NULL` integers, `is_published`, `sort_order`, timestamps, soft deletes
- Soft deletes because an order will reference a product from M5, and deleting one must never orphan a purchase
- `ProductType` enum: `Reading`, `Pass`, `Addon`. String column with a PHP enum cast, per `.claude/database.md`. **Part of the frontend contract**, so the site maps presentation to it
- `Fulfilment` enum: `ManualPdf`, `AccessGrant`. Internal, never exposed. `Ai` is added in M7 by adding a case, which needs no migration because the column is a string
- `allows_question`, not `requires_question`. A question is always optional when buying a reading
- **Tests:** translatable columns are `jsonb`, per-locale values round trip, a product cannot be inserted without all three prices, enums cast both ways, soft delete keeps the row
- **Risk:** the `NOT NULL` price columns mean every factory must supply prices. Handled in the factory defaults once, rather than in each test

### Step 4, translated slugs, uniqueness and history
- A Postgres expression index per configured locale over `(slug->>'en')` and so on. The concrete reason the columns are `jsonb`
- The index set covers currently configured locales. Adding a locale later needs an index added, which is a developer act on a deploy. Hard rule 4 forbids a migration to *enable* a language, not to index one
- `slug_history`: product id, locale, old slug, timestamp. Written on change. **No redirect handling is built.** The point is having the data when redirects arrive
- Slugs are nullable, since add-ons have no page. The uniqueness rule must tolerate that
- **Tests:** two products cannot share a slug in one locale, the same string is allowed in two locales, changing a slug writes history, lookup by locale finds the right row and misses across locales, two products with no slug do not collide
- **Risk:** the expression index is the fiddliest thing here. If it fights us I will say so rather than quietly falling back to an application-level check

### Step 5, the Filament resource, edit only
- Products are seeded, so the resource has **no create and no delete**. Jennifer edits name, descriptions, slug, prices, published state and order
- `key`, `type` and `fulfilment` are never shown to her. They are structure, not content
- Prices entered in major units, stored in minor units. She types `19.00`, the database holds `1900`. Conversion at the form boundary only
- Plain language labels and helper text per `.claude/filament.md`
- **Tests:** editing persists, translations persist per language tab, `19.00` stores `1900`, the create and delete routes are absent, prices survive a round trip through the form unchanged
- **Risk:** the major to minor conversion is the classic place a rounding bug hides. Tests cover `19.00`, `19.99`, `0.05`, `100.00` and a trailing zero case

### Step 6, seed the four reading products
- `key` values `one-card`, `three-card`, `month-ahead`, `in-depth`, all `type: reading`, `fulfilment: manual_pdf`, `allows_question: true`
- English names, placeholder prices, unpublished. Real copy and prices are Jennifer's to enter
- Idempotent on `key`, so re-running the seeder never duplicates or overwrites her edits
- **Tests:** running the seeder twice leaves four rows and does not overwrite edited copy

### Step 7, the read endpoints
- `GET /api/v1/products`, published reading products only, ordered by `sort_order`
- `GET /api/v1/products/{slug}`, resolved against the requested locale
- Each returns exactly one price, in the resolved currency, as integer minor units. Never all three
- Includes `type` so the frontend can map presentation. Never includes `fulfilment` or `key`
- Unpublished is a 404, not a 403. Existence is not public information
- **Tests:** exact shapes, unpublished absent from the index and 404 on show, a slug from another locale 404s, `?currency=` and `CF-IPCountry` both reflected, `?locale=` resolves the right slug, output is an API Resource
- **Risk:** endpoint naming. `/products` covers everything sellable, `/reading-products` reads better today but lies from M4. Chose `/products` with a `type` filter, flagged in open question 1

### Step 8, documentation
- Add the endpoints, the `type` enum values and the price rules to `docs/api/frontend-contract.md`
- Write `docs/architecture/content-model.md`, referenced by `.claude/translation.md` and currently missing. The first real content model is the right moment
- Update `.claude/api.md` with the new shapes

## Open questions, all resolved 25 July 2026

1. **Endpoint naming: `/api/v1/products`.** The pass and the add-on live in the same table from M4, so a slightly broad name now beats a rename after the frontend has built against it.
2. **Coverage widget: M3.** After this milestone there are two translatable models, which is still too thin for coverage to mean anything. M3 adds a third plus 22 card rows.
3. **Manual fulfilment needs nothing customer-facing.** No turnaround time or fulfilment copy on the product page. It stays operational and waits for M5.

## Dependencies

- **You:** the three questions above. None block step 2 or step 3
- **Jennifer:** final product names, copy per product, and price per currency. Not blocking the build, since the seeder uses placeholders, but blocking anything on staging looking finished
- **Frontend partner:** endpoint shapes and the `type` enum values, added to the contract in step 8. Still no acknowledged contract, so these stay cheap to change
- **Carried from M1:** confirm PHP 8.4 on the platform, real CORS origins, the cold start measurement. None block this milestone

## Verification

- `migrate:fresh` clean. `products` and `slug_history` present, `prices` gone, translatable columns `jsonb`, expression indexes present per configured locale
- Full suite green, including the denial paths: unpublished 404, cross-locale slug 404, a product row rejected by the database when a price is missing
- Through the panel: edit a product, fill both language tabs with a second locale enabled in config, change prices, publish, and confirm no create or delete action exists
- Against the API: index and show return one price in the resolved currency, `?currency=` honoured without re-detection, `?locale=` resolving the right slug, `type` present and `fulfilment` absent
