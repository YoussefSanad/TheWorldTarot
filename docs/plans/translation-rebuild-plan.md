# Translation rebuild, merged implementation plan

**Delivered 28 July 2026.** Every step is built and tested. Step 3 changed during the work:
readiness flags were replaced by calculated completeness.

Approved 27 July 2026. Supersedes and merges:

- `docs/plans/product-keys-and-locale-paths.md`
- the implementation steps in `docs/plans/translation-design.md`, whose requirements and reasoning still stand

Both moved the same routes, so they are executed once, together.

Rules this implements: `.claude/translation.md`. Read it first.

**Scope status:** this is outside the approved milestone 2 plan, and milestone 3 has no plan yet. Treated as approved work in its own right, per the decision on 27 July 2026. Milestone 3 gets planned after this lands.

---

## Decisions this plan encodes

1. **Product keys are URLs.** A string-backed enum, never editable, no translated slugs, no slug history
2. **Locale is a path segment**, `/api/v1/es/products`, English prefixed too. A locale that is not servable is a 404, never a fallback
3. **The locale registry is one table**, no config file. `draft`, `preview`, `live`
4. **The default locale is `config('app.locale')`**
5. **Readiness is a manual flag per record per locale**, and the default locale needs no flag
6. **Launching a language is gated per language. Content added afterwards is gated per record**, so a record appears only in the languages it is finished in
7. **Storage stays `spatie/laravel-translatable` with jsonb columns.** Revisit only if translations gain a reviewer, an audit trail, or hundreds of records

---

## Steps

Each is one commit, tests written in the same commit, results reported before the next begins.

### Step 1, product keys and the end of slugs

- `app/Enums/ProductKey.php`, string backed, **only the four readings that exist**. The pass joins in M4 and the rush in M5, since the enum drives the generated OpenAPI union type
- Cast `key` to it on `Product`
- Migration dropping `products.slug` and the `slug_history` table. Dropping the column takes its expression indexes with it
- **Gotcha:** `2026_07_25_150000_add_product_slug_unique_indexes.php` and `2026_07_26_000000_add_spanish_locale.php` both call `App\Support\SlugIndex`, which this step deletes. A fresh `migrate:fresh` would fatal on the missing class. Delete the first migration outright and strip the `SlugIndex::createFor` call from the Spanish one, leaving its locale row intact
- Delete `SlugIndex`, `SlugHistory`, `HasTranslatedSlug`, `tests/Feature/ProductSlugTest.php`
- Seeder and factory use the enum
- **Tests:** `key` casts both ways, duplicate keys are refused, no `slug` column, no `slug_history` table, `migrate:fresh` clean

### Step 2, the locale registry

- Migration: add `english_name` and `native_name`, rename `state` to `status`, convert `available` to `draft`, seed `en` live and `es` draft
- Delete `config/localization.php`. Update every reference: `LocaleResolver`, `Locale::scopeConfigured` (deleted outright), `AdminPanelProvider`'s tab list, `LanguageResource`'s label lookup, and the tests that set the config
- Default locale comes from `config('app.locale')`
- Panel language tabs read the table, cached per request
- **Tests:** the tab list follows the table, adding a row exposes a tab with no migration and no code change, `/languages` lists live locales only, the label comes from the table

### Step 3, readiness and per-record visibility

- `translation_readiness` table: `translatable_type`, `translatable_id`, `locale`, `is_ready`, unique on the three
- A `HasTranslationReadiness` concern: `markReady($locale)`, `isReadyIn($locale)`, and a `readyIn($locale)` query scope that is a no-op for the default locale
- `config/translatable.php` listing the models the gate walks. `Product` now, `Card` in M3
- A `LanguageReadiness` service: is this locale complete, and what is outstanding
- **Tests:** flags are per record and per locale, the default locale needs no flag, `readyIn` filters correctly, an unflagged record makes a locale incomplete, flagging everything makes it complete, publishing a new record after a language is live makes that locale incomplete without affecting the default locale

### Step 4, the panel

- Readiness toggle inside each non-default language tab
- Badge column on the products list showing finished languages
- Summary line: "Spanish: 3 of 4 products ready"
- `LocaleResource`: statuses visible, promotion to `live` refused with a message naming what is outstanding, demotion always allowed
- Remove the web address field, replace it with a read-only page link
- **Tests:** the toggle writes the flag for the active locale only, no toggle on the default tab, promotion refused with the missing records named, promotion allowed when everything is flagged, demotion never blocked, the web address field is gone

### Step 5, routes and the API

- Content routes under `{locale}`. `/languages` and `/currencies` stay outside it
- `ResolveRequestContext` reads the route parameter, aborts 404 when the locale is not servable, and keeps currency resolution unchanged
- `LocaleResolver::resolve()` returns `?string`, null meaning not servable
- Product endpoints resolve by key, list only products ready in the requested locale, and return `key` rather than `slug`
- **Tests:** live serves everyone, preview serves an admin and 404s the public, draft and unknown 404 for everyone, a product unfinished in Spanish is absent from the Spanish list and 404s on its own URL while remaining fine in English, prices and locale stay independent, `Accept-Language` is still ignored

### Step 6, documentation

- `API_CONTRACT.md`: locale path shape, the 404 rules, `key` instead of `slug`, per-record availability per language, the requirement to build the language switcher from `/languages`, and to hide languages a page has no translation for
- `.claude/api.md`: new shapes
- Regenerate `QA_CHECKLIST.md`, which was deleted, from the new behaviour
- `README.md`: no change expected

---

## Risks

- **Removing `config/localization.php` touches a lot**: the resolver, the panel provider, an API resource, two migrations and several tests. Expect step 2 to be wider than it reads
- **`RequestContextTest` and `ProductsEndpointTest` will fail loudly** in steps 2 and 5. They encode the fallback behaviour being deliberately replaced. Rewrite them rather than nudging assertions until they pass
- **Spanish becomes a 404** rather than falling back to English. Correct, and it will look like a bug in QA
- **The kill switch depends on the frontend** reading `/languages` at runtime. Without that it is decorative
- **The readiness gate is only as honest as whoever ticks the boxes.** Deliberate, and worth saying to them

---

## Verification when done

- `migrate:fresh` clean. No `slug` column, no `slug_history`, `locales` carries names and a status, `translation_readiness` exists
- Full suite green
- `/api/v1/en/products` lists published readings, `/api/v1/en/products/one-card` returns one
- `/api/v1/es/products` 404s while Spanish is draft, serves an admin at preview, serves everyone at live
- A product unfinished in Spanish is absent from the Spanish list and present in English
- `/api/v1/xx/products` 404s, `/api/v1/languages` and `/api/v1/currencies` work with no locale
- The panel has no web address field, shows a page link, and refuses to publish Spanish while anything is outstanding
- `/docs/api.json` lists the four product keys as an enum
