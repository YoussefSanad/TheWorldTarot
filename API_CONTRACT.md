# API contract

For the frontend partner building the Next.js website against this API.

Everything here is live unless marked otherwise. Read it alongside the generated reference at `/docs/api`: that describes shapes and is generated from the code, this explains the rules behind them.

Status: draft, not yet acknowledged. Until you confirm it, these shapes can still change freely. After that, changes are coordinated.

---

## 1. Base URLs and versioning

| Environment | Base URL |
|---|---|
| Production | `https://api.theworldtarot.com` |
| Staging | `https://staging-api.theworldtarot.com` |
| Local | `http://localhost:8000` |

- Every route lives under `/api/v1/`. There is no unversioned route
- A future `/api/v2/` would run alongside `/api/v1/`, not replace it
- **Adding a field to a response is not a breaking change** and may happen without warning. Renaming or removing one is coordinated first

The API and the website share a root domain deliberately. Section 6 explains why.

## 2. Endpoints

### GET /api/v1/languages

```json
[
  { "code": "en", "label": "English" }
]
```

- A single-item array until a second language goes live. Use the length to decide whether to render a language selector at all
- **Only public languages appear.** A language being translated, or under internal review, is absent. There is no `enabled` flag, because presence in this list is what enabled means
- `code` is what you send back as `?locale=`

### GET /api/v1/currencies

```json
{
  "available": [
    { "code": "USD", "symbol": "$" },
    { "code": "EUR", "symbol": "€" },
    { "code": "GBP", "symbol": "£" }
  ],
  "detected": "USD"
}
```

- `available` is the full list the site sells in. Stable and safe to cache
- `detected` is what this particular request resolved to. It varies by visitor

### GET /api/v1/products

The readings on sale, in the order the client arranged them.

```json
[
  {
    "slug": "one-card",
    "type": "reading",
    "name": "One Card Reading",
    "short_description": "A single card.",
    "allows_question": true,
    "price": { "currency": "GBP", "amount": 1900 }
  }
]
```

- **Published readings only.** Anything unpublished is absent
- `type` is `reading`, `pass` or `addon`. It exists so you can map presentation to it. Today this list only ever contains `reading`
- `allows_question` means the customer may attach a question when buying. It is never required of them
- `price` is exactly one price, in the currency this request resolved to. See section 5
- `slug` is the slug **in the requested language**, and is what you put in the URL

The viewing room pass is deliberately not in this list. It has a page of its own rather than sitting among the readings, and is fetched by slug once it exists.

### GET /api/v1/products/{slug}

One product. Same shape, plus `long_description`.

- The slug is matched **against the requested language only**. An English slug asked for in Spanish is a 404, not a fallback
- Anything unpublished is a **404, not a 403**. Whether an unreleased reading exists is not public information

All four endpoints are public and need no authentication.

## 3. Locale

**Locale travels as a query parameter, `?locale=es`. Never as a header.**

The backend never guesses. It takes what the request states, validates it, and falls back to the default. **Negotiation is yours**, since you route on locale in the path for SEO and therefore already know the answer definitively. We do not read `Accept-Language`, and sending it has no effect.

Resolution order:

1. A public language is served
2. A language under internal review is served **only** to a signed-in admin, and treated as absent for everyone else
3. Anything else, including absent, unknown, misspelled, or still being translated, falls back to English

An invalid locale is never an error. You get the default language and a 200.

**Spanish exists today but is not public.** Translations are being entered, and `?locale=es` currently returns English to everyone. Nothing changes for you when it goes live except that it starts appearing in `/languages`. Content will be complete before that happens: a language ships whole or not at all.

Why a query parameter: these responses sit behind a CDN and cache keys are built from URLs. A header-based locale would mean either varying on a header browsers fill with long weighted strings, or no edge caching at all.

## 4. Currency

**Currency travels as `?currency=GBP`, or as a `currency` cookie.**

Resolution order:

1. An explicit `?currency=` that we sell in is honoured exactly, and no detection happens
2. Otherwise a `currency` cookie that we sell in is honoured
3. Otherwise detected from the CDN's country header: EU member states to EUR, United Kingdom to GBP, everything else to USD
4. A currency we do not sell in is ignored and treated as absent

Two things worth knowing:

- **We never re-detect over an explicit choice.** A visitor in Berlin who picks dollars keeps dollars on every subsequent request
- **Write the `currency` cookie in plain text.** The API is configured not to decrypt it. Do not encrypt or sign it

## 5. Prices

- **Money is always integer minor units.** `1900` means £19.00. Never a decimal, never a formatted string
- Formatting is entirely yours, including symbol placement and separators. Use `symbol` from `/currencies`
- **There is no conversion anywhere in this system.** Prices are fixed per currency and entered by hand. The EUR price is not the USD price at a rate
- A product carries **exactly one price**, in the currency the request resolved to, never all three
- Locale and currency are independent. Spanish copy priced in pounds is an ordinary combination

## 6. Authentication

Configured for **both** cookie sessions and bearer tokens. Cookie auth is what the website uses. Token auth exists for future native apps.

### Cookie flow, for the website

1. `GET /sanctum/csrf-cookie` once, before your first authenticated request
2. Send `credentials: "include"` on **every** request after that, including that first one
3. Read the `XSRF-TOKEN` cookie and send it back as the `X-XSRF-TOKEN` header on any POST, PUT, PATCH or DELETE. Axios does this automatically, `fetch` does not

Two things break this silently:

- **Your origin must be registered with us**, in both the CORS allow list and the stateful domain list. If it is missing, requests fail with what looks like a network error
- **Both applications must share a root domain.** The session cookie is scoped to `.theworldtarot.com`, which is why the API is `api.theworldtarot.com` rather than a separate domain. Staging mirrors this exactly

### Token flow, for later native apps

Send `Authorization: Bearer <token>`. No CSRF, no cookies, no origin registration. Every protected route works under both.

## 7. Error responses

Everything under `/api/*` returns JSON, never an HTML error page.

| Status | When | Body |
|---|---|---|
| 401 | Not authenticated | `{ "message": "Unauthenticated." }` |
| 403 | Authenticated but not allowed | `{ "message": "..." }` |
| 404 | No such route or record | `{ "message": "..." }` |
| 419 | CSRF token missing or stale on a cookie-auth write | `{ "message": "..." }` |
| 422 | Validation failed | `{ "message": "...", "errors": { "field": ["reason"] } }` |
| 429 | Rate limited | `{ "message": "..." }` plus `Retry-After` |

On 422, `errors` is keyed by field name with an array of messages per field, and field names match what you sent.

**Backend validation is authoritative.** Validating with Zod as well is right, but assume every rule is enforced again here and that ours wins. If the two disagree, say so rather than working around it.

## 8. Caching

- `/api/v1/languages` is identical for every visitor and fully cacheable
- `/api/v1/products` and a single product vary by locale and currency, both of which are in the URL when you send them explicitly. Sending `?currency=` makes them cleanly cacheable, relying on country detection does not
- `/api/v1/currencies` contains `detected`, which is per-visitor. It must not be cached at a shared edge on URL alone, or one visitor's currency gets served to the next. The CDN cache key accounts for this

The country header only exists once traffic flows through the CDN. Before DNS cutover, and in local development, detection falls back to USD. That is expected, not a bug.

## 9. Not built yet

Nothing here has a shape yet, and we would rather agree them with you before writing them than after.

- Cards, the Library, and their translated slugs
- Video playback and signed URLs
- The viewing room pass, which is a product row you fetch by slug
- Checkout, orders, member accounts, gift codes
- FAQ and legal content, newsletter signup
- Editable page copy, delivered as named blocks per page

`GET /api/v1/user` exists as framework scaffolding. **It is not part of this contract** and its shape will change before member accounts ship.

## 10. What we need from you

1. **Your exact origins**, all of them: production, the preview URL pattern, and the local dev origin including port. Without these your requests are blocked by CORS and cookie auth cannot work at all
2. **Cookie auth or token auth for the website.** Cookie is our assumption
3. **Your locale routing plan**, so the `?locale=` values you send match the paths you route on
4. **Confirmation of the shapes above**, or a counter-proposal

One question from us: your application can read the visitor's country directly at the edge. If you would rather detect currency yourself and always send `?currency=`, we can drop `detected` and the caching complication with it.
