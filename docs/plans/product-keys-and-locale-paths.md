# Plan: product keys as URLs, locale as a path segment

**Superseded and implemented.** Merged into `docs/plans/translation-rebuild-plan.md` and
delivered on 28 July 2026. Kept for the reasoning behind product keys.

Agreed 26 July 2026. This document exists so the work can be picked up later without re-deriving the reasoning.

Applies on top of milestone 2 as completed. Milestone 3 has not started.

---

## The two decisions

### 1. A product's key is its URL, and it is never editable

`key` becomes a string-backed PHP enum and doubles as the URL segment. Editable per-locale slugs are removed entirely.

**Accepted trade-off:** `.claude/translation.md` section 9 chose translated slugs because SEO scored 50/100 on the old site and international is a paid deliverable. A Spanish page at `/es/lecturas/one-card` is weaker for Spanish search than `/es/lecturas/una-carta`. Judged worth it for **four commercial product pages**, where the slug is a small factor next to title and copy, and where a non-technical user editing a live URL is a real risk we have already seen.

**Does not extend to cards.** An enum works here because products are a fixed, developer-owned set. Jennifer creates cards herself, there are 22, and Library pages are exactly where slug wording earns its keep. Cards are decided on their own merits in M3.

**The frontend may still localise the path around the key**, for example `/en/readings/one-card` and `/es/lecturas/one-card`. Everything except the final segment belongs to them.

### 2. Locale becomes a path segment on the API

`/api/v1/{locale}/products` replaces `?locale=`. Chosen for symmetry with the website's `/en` and `/es` routing, against my recommendation of keeping the query parameter, which is recorded here so the reasoning is not lost: the query form was already built and tested, and the frontend's mapping is one line either way.

Unchanged: **never from a header.** `Accept-Language` is still ignored.

### 3. `slug_history` is dropped

Nothing to record. A URL that cannot change never needs a redirect.

---

## Design

### The enum

```php
enum ProductKey: string
{
    case OneCard = 'one-card';
    case ThreeCard = 'three-card';
    case MonthAhead = 'month-ahead';
    case InDepth = 'in-depth';
}
```

**Only cases that exist today.** The viewing room pass joins in M4 and the rush add-on in M5, when those rows are actually seeded. The enum drives the generated OpenAPI union type, so an unseeded case would advertise a product the API cannot serve.

The payoff for the frontend: their generated types become

```ts
type ProductKey = 'one-card' | 'three-card' | 'month-ahead' | 'in-depth'
```

giving them an exhaustive switch when mapping a product to its bespoke layout and artwork, checked at build time.

### Routes

Content routes are grouped under a locale segment. Everything else stays flat.

```php
Route::prefix('v1')->group(function () {
    // Not locale dependent. No prefix.
    Route::get('/languages', LanguageController::class);
    Route::get('/currencies', CurrencyController::class);

    Route::prefix('{locale}')->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/{key}', [ProductController::class, 'show'])->name('products.show');
    });
});
```

Auth routes, webhooks and anything else stay outside the group. They are not content and have no locale.

### Locale resolution changes meaning

This is the part to think about carefully, because it is a behaviour change, not a syntax change.

| Situation | Query form, today | Path form, after |
|---|---|---|
| Locale is live | Served | Served |
| Locale is in preview, admin | Served | Served |
| Locale is in preview, public | Falls back to English, 200 | **404** |
| Locale is `available` or unknown | Falls back to English, 200 | **404** |
| No locale given | Falls back to English, 200 | **404**, the segment is required |

A query parameter is a hint, so falling back is right. **A path segment is an address, so a wrong one is a 404.** That is better for SEO too, since it stops bogus prefixes serving duplicate English content at infinitely many URLs.

Consequence to state in the contract: the frontend must always send a locale, and must only send locales it got from `/languages`.

`LocaleResolver` keeps its rules but returns null instead of the default when a locale is not servable, and the middleware turns null into a 404.

### Response shape

`slug` is replaced by `key`. Same position, same purpose, now stable across languages.

```json
{
  "key": "one-card",
  "type": "reading",
  "name": "Lectura de Una Carta",
  "short_description": "...",
  "allows_question": true,
  "price": { "currency": "GBP", "amount": 1900 }
}
```

### The admin panel

The web address field disappears. In its place, a **read-only line showing the page link**, so Jennifer can see where a reading lives without being able to break it.

---

## Steps

Each is one commit, tests in the same commit.

### Step 1, the enum and the schema

- Add `app/Enums/ProductKey.php` with the four reading cases
- Cast `key` to it on `Product`
- Migration: drop the `slug` column from `products`, and drop the `slug_history` table. Dropping the column takes its expression indexes with it, so they need no separate statement
- **Gotcha, do not skip:** two existing migrations reference `App\Support\SlugIndex`, which this work deletes. `2026_07_25_150000_add_product_slug_unique_indexes.php` and `2026_07_26_000000_add_spanish_locale.php`. A fresh `migrate:fresh` would fatal on a missing class. Delete the first file outright and strip the `SlugIndex::createFor` call from the Spanish one, leaving its locale row insert intact
- Delete `app/Support/SlugIndex.php`, `app/Models/SlugHistory.php`, `app/Models/Concerns/HasTranslatedSlug.php`
- Delete `tests/Feature/ProductSlugTest.php`
- Update `ProductFactory` to use enum keys and drop `slug`
- **Tests:** `key` casts both ways, two products cannot share a key, `migrate:fresh` is clean, the products table has no `slug` column and `slug_history` does not exist

### Step 2, the seeder

- Iterate `ProductKey` cases rather than an inline array. Names stay in the seeder, since they are copy
- **Tests:** unchanged in spirit. Four rows, idempotent, structure re-applied, values never overwritten

### Step 3, the panel

- Remove the web address field
- Add a read-only display of the page link, for example `/readings/one-card`
- **Tests:** the web address field no longer exists, the link is shown, editing still saves, the publish guard no longer mentions a web address

### Step 4, routes and locale resolution

- Move product routes under `{locale}`
- `ResolveRequestContext` reads the route parameter instead of the query parameter, and aborts 404 when the locale is not servable
- `LocaleResolver::resolve()` returns `?string`, null meaning not servable
- Non-content routes keep working with no locale
- **Tests:** rewrite `RequestContextTest` for the new rules. A live locale serves, preview serves to an admin and 404s for the public, `available` and unknown 404 for everyone, a missing segment cannot be routed at all, currency resolution is unaffected, and `Accept-Language` is still ignored

### Step 5, the endpoints

- `show` resolves by key, not slug, and rejects a key outside the enum with a 404 rather than a 500
- `ProductResource` returns `key`
- **Tests:** update `ProductsEndpointTest` throughout. Key lookup works in every language, an unpublished product 404s, a nonsense key 404s, the same key resolves in English and Spanish, prices and locale stay independent

### Step 6, documentation

- `API_CONTRACT.md`: new URL shape, the 404 rules, `key` instead of `slug`, and a note that the frontend should only use locales it received from `/languages`
- `QA_CHECKLIST.md`: every curl example gains a locale segment, the slug history checks go, key checks arrive
- `.claude/translation.md` section 9: record that product slugs were replaced by keys, with the SEO trade-off stated, and that cards are decided separately in M3
- `.claude/database.md`: the products table shape loses `slug`
- `README.md`: no change expected, it only links out

---

## Risks and things that will bite

- **The two historical migrations referencing `SlugIndex`.** Covered in step 1, repeated here because a missing class in a migration breaks every fresh database rather than failing loudly at review time
- **Deployed environments have run those migrations**, so the drop migration must be additive rather than an edit to history. Editing the old files only affects databases built from scratch, which is why both are needed
- **`RequestContextTest` and `ProductsEndpointTest` will fail loudly** during step 4 and step 5, and should. They encode the old fallback behaviour, which is being deliberately replaced, not accidentally broken. Rewrite them rather than adjusting assertions until they pass
- **Spanish is seeded as `available`**, so after this change `/api/v1/es/products` is a 404 for everyone including admins until it moves to `preview`. That is correct and will look like a bug during QA. Add it to the "not bugs" section of the checklist

---

## Verification when done

- `migrate:fresh` clean, no `slug` column, no `slug_history` table
- Full suite green
- `/api/v1/en/products` lists published readings, `/api/v1/en/products/one-card` returns one
- `/api/v1/es/products` 404s while Spanish is `available`, serves an admin once it is `preview`, and serves everyone once `live`
- `/api/v1/xx/products` 404s
- `/api/v1/languages` and `/api/v1/currencies` still work with no locale segment
- The panel has no web address field, and shows the page link read only
- The generated spec at `/docs/api.json` lists the four keys as an enum
