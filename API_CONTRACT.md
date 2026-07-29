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
- `code` is the segment you put in the URL, `/api/v1/en/...`

**Build your language switcher from this endpoint, never from a hardcoded list.** A language can be taken down at any moment, and that takes effect on the next request with no deploy on our side. If your switcher is hardcoded, taking one down leaves a dead link on your site and a 404 behind it. This is the one requirement in this document that we cannot enforce for you.

This endpoint has no language segment, since it is what tells you which languages there are.

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

### GET /api/v1/{locale}/products

The readings on sale, in the order the client arranged them.

```json
[
  {
    "key": "one-card",
    "type": "reading",
    "name": "One Card Reading",
    "short_description": "A single card.",
    "allows_question": true,
    "price": { "currency": "GBP", "amount": 1900 }
  }
]
```

- **Published readings only.** Anything unpublished is absent
- **`key` is the product's permanent identifier and its URL segment.** It is the same in every language, it is never edited, and it is a fixed set: `one-card`, `three-card`, `month-ahead`, `in-depth`, later `viewing-room-pass` and `rush-24h`. The generated specification carries it as an enum, so you can build an exhaustive switch over it and let your compiler tell you when we add one
- `type` is `reading`, `pass` or `addon`, so you can map presentation to it. This list only ever contains `reading`
- `allows_question` means the customer may attach a question when buying. It is never required of them
- `price` is exactly one price, in the currency this request resolved to. See section 5

**A product appears only in the languages it is finished in.** If a reading is added after Spanish goes live, it is in the English list immediately and joins the Spanish list when its Spanish copy is written. So the two lists can legitimately differ in length, and a language switcher on a product page should hide any language that product is not available in.

The viewing room pass is deliberately not in this list. It has a page of its own rather than sitting among the readings, and is fetched by its key once it exists.

### GET /api/v1/{locale}/products/{key}

One product. Same shape, plus `long_description`.

- **404, not 403**, for anything unpublished, any key outside the fixed set, and any product not yet translated into the language you asked for. Whether an unreleased reading exists is not public information

### GET /api/v1/{locale}/cards

The Library: every card on the website in that language, in deck order.

```json
[
  {
    "id": 1,
    "number": 0,
    "name": "The Fool",
    "short_description": "A beginning.",
    "long_description": "The longer meaning of the card.",
    "image": "https://media.theworldtarot.com/cards/the-fool.jpg"
  }
]
```

- **The listing carries the full copy**, unlike the readings listing. The Library opens a card in a pop-up on a page that already holds the deck, so one request renders everything and opening a card costs no round trip. The whole deck is roughly 25KB of text before compression
- `number` is the number on the card, and the order the deck is shown in. The Fool is 0
- `id` is how you fetch a single card. **There is no slug**, because a card is never reached by a URL a person reads. If you need per-card addresses, say so now rather than after launch
- **`image` is an absolute URL and is never null.** A card is on the website only once it has both its copy and its picture, so a card you receive always has one. It is the same picture in every language
- **A card appears only in the languages it is finished in**, on the same rule as readings, and only once it has a picture

### GET /api/v1/{locale}/cards/{id}

One card, the identical shape. 404 for a card that does not exist, is unpublished, or is not written in the language you asked for.

Worth having even though the listing carries everything: video playback and its access check will hang off this endpoint, so build against it now rather than moving to it later.

### GET /api/v1/{locale}/cards/random

One card drawn at random, for the reveal on the homepage. The identical shape again, so one component renders a card wherever it came from.

- **Never cached. It carries `Cache-Control: no-store`, and you must not cache it either.** A held response reveals the same card to every visitor, which is the one thing the reveal must not do
- **This endpoint does not remember anyone.** It will hand the same visitor a hundred cards. The rule that a visitor gets one reveal per session is yours, in a cookie or local storage
- 404 when no card is on the website yet, which is the state of a freshly seeded site

Those seven endpoints are public and need no authentication.

### GET /api/v1/{locale}/cards/{id}/video

**The Viewing Room.** One card's Living Tarot film, as a signed URL that expires. Requires an authenticated customer who holds the viewing room pass.

```json
{
  "card_id": 1,
  "url": "https://vz-xxxx.b-cdn.net/<video>/playlist.m3u8?token=...&expires=...",
  "expires_at": "2026-07-30T14:22:00+00:00"
}
```

- **`url` is a credential with a timer, valid for two hours.** Do not cache it, do not store it, do not put it anywhere it can be shared. The response carries `Cache-Control: no-store` and your side must respect that: a service worker or a persisted store turns two hours of exposure into however long it keeps it
- **Ask for a fresh one rather than reusing an old one.** `expires_at` is there so a player can renew before it dies rather than stalling mid-video
- **404 for a card whose video is not ready**, which includes one still transcoding, one with no poster frame, and one not on the website in that language. There is genuinely nothing to serve
- **403 for a signed-in customer without a pass**, not 404. The Viewing Room is advertised, and hiding it would only confuse somebody who paid and is signed in as the wrong account
- **401 with no authentication at all**

**Access is decided here, every time.** Do not gate the player on anything the browser knows. If your UI shows a locked state, that is presentation, and this endpoint is the enforcement.

Both cookie auth and bearer tokens work, as with every protected route.

## 3. Locale

**Language is a path segment: `/api/v1/es/products`. Every language is prefixed, English included.** Never a header.

The backend never guesses. **Negotiation is yours**, since you route on language in the path for SEO and therefore already know the answer definitively. We do not read `Accept-Language`, and sending it has no effect.

| You ask for | Public visitor | Signed-in admin |
|---|---|---|
| A public language | Served | Served |
| A language under internal review | **404** | Served |
| A language being translated, or one that does not exist | **404** | **404** |

**A language that is not public is a 404, not a fallback to English.** A path segment is an address, not a hint. If we answered in English, the same content would sit at as many URLs as anyone cared to invent, which is bad for you and worse for search.

So: only ever send a code you received from `/api/v1/languages`, and treat a 404 on a language you thought was live as that language having been taken down rather than as an outage.

**Spanish exists today but is not public.** `/api/v1/es/...` currently 404s for everyone. When it goes live it starts appearing in `/languages` and its URLs start answering. Content is complete before that happens: a language ships whole or not at all.

Why a path segment rather than a header: these responses sit behind a CDN and cache keys are built from URLs, so each language caches cleanly and separately.

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

- `/api/v1/languages` is identical for every visitor and fully cacheable, but **cache it briefly**. It is what tells you a language has been taken down, so a long cache delays the switch you are relying on
- Content URLs carry the language in the path, so each language caches separately with no configuration at all. Currency is the only remaining variance: sending `?currency=` explicitly makes them cleanly cacheable, relying on country detection does not
- **`/api/v1/{locale}/cards/random` must never be cached, by you or by anything between us.** It says so in its own headers. Everything else on the cards endpoints is as cacheable as the readings
- **`/api/v1/{locale}/cards/{id}/video` must never be cached or stored either**, for a stronger reason: the answer is a signed credential rather than content
- `/api/v1/currencies` contains `detected`, which is per-visitor. It must not be cached at a shared edge on URL alone, or one visitor's currency gets served to the next. The CDN cache key accounts for this

The country header only exists once traffic flows through the CDN. Before DNS cutover, and in local development, detection falls back to USD. That is expected, not a bug.

## 9. Not built yet

Nothing here has a shape yet, and we would rather agree them with you before writing them than after.

- The viewing room pass as a purchase. The product row exists and is fetched by its key like any other; buying it, and the account that holds it, arrive with checkout
- Checkout, orders, member accounts, gift codes
- FAQ and legal content, newsletter signup
- Editable page copy, delivered as named blocks per page

`GET /api/v1/user` exists as framework scaffolding. **It is not part of this contract** and its shape will change before member accounts ship.

## 10. What we need from you

1. **Your exact origins**, all of them: production, the preview URL pattern, and the local dev origin including port. Without these your requests are blocked by CORS and cookie auth cannot work at all
2. **Cookie auth or token auth for the website.** Cookie is our assumption
3. **Confirmation that your language switcher reads `/api/v1/languages` at runtime**, since that is what makes taking a language down actually work
4. **Confirmation of the shapes above**, or a counter-proposal
5. **Confirmation that your player handles an expiring HLS URL**, renewing rather than holding one. Two hours is generous for a single film and is not forever
6. **Whether a card ever needs an address of its own.** Cards are identified by number today, on the assumption that the Library opens them in a pop-up. If any card is ever linkable, shareable or indexed on its own URL, adding a readable identifier is cheap now and awkward once you have built against ids

One question from us: your application can read the visitor's country directly at the edge. If you would rather detect currency yourself and always send `?currency=`, we can drop `detected` and the caching complication with it.
