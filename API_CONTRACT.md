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
    "image": "https://media.theworldtarot.com/cards/the-fool.jpg",
    "in_viewing_room": true
  }
]
```

- **The listing carries the full copy**, unlike the readings listing. The Library opens a card in a pop-up on a page that already holds the deck, so one request renders everything and opening a card costs no round trip. The whole deck is roughly 25KB of text before compression
- `number` is the number on the card, and the order the deck is shown in. The Fool is 0
- `id` is how you fetch a single card. **There is no slug**, because a card is never reached by a URL a person reads. If you need per-card addresses, say so now rather than after launch
- **`image` is an absolute URL and is never null.** A card is on the website only once it has both its copy and its picture, so a card you receive always has one. It is the same picture in every language
- **A card appears only in the languages it is finished in**, on the same rule as readings, and only once it has a picture
- **`in_viewing_room` says whether there is a film to watch.** This is what lets you build the Viewing Room in one request: render all twenty-two, locked, and invite people to buy the pass
- **`poster` was removed on 7 August 2026.** It carried a still the player could rest on, it was a second per-card upload gating the Viewing Room and the draw, and `image` is what a player rests on instead. Nothing on your side read it
- **It is not an access check.** It says a film exists, not that this visitor may watch it. Whether they may is answered by the video endpoint, every time, and a `true` here for somebody without a pass is expected rather than a mistake
- `in_viewing_room` is false while a film is still being prepared, so a card never advertises a video that would 404

### GET /api/v1/{locale}/cards/{id}

One card, the identical shape. 404 for a card that does not exist, is unpublished, or is not written in the language you asked for.

Worth having even though the listing carries everything: video playback and its access check will hang off this endpoint, so build against it now rather than moving to it later.

### GET /api/v1/{locale}/cards/draw

One card drawn at random, with its film, for the reveal on the homepage. The identical card shape, plus one block:

```json
{
  "id": 1,
  "number": 0,
  "name": "The Fool",
  "…": "every other card field, unchanged",
  "video": {
    "url": "https://vz-xxxx.b-cdn.net/bcdn_token=…&expires=…&token_path=…/<video>/playlist.m3u8",
    "expires_at": "2026-08-03T14:22:00+00:00"
  }
}
```

- **`video.url` is a credential with a timer, valid for thirty minutes.** Everything the Viewing Room endpoint below says about handling applies here word for word: do not cache it, do not store it, do not put it anywhere it can be shared. The response carries `Cache-Control: no-store` and your side must respect it
- **It is HLS**, an `.m3u8` manifest with adaptive renditions, not a progressive MP4. Safari plays it natively in a `<video>`, Chrome and Firefox need a library such as `hls.js`
- **`video` is never null.** A card with no film to play is never drawn, so there is no empty case to handle
- **Never cached, for two reasons.** A held response reveals the same card to every visitor, which is the one thing a reveal must not do, and it now carries a credential rather than only a card
- **This endpoint does not remember anyone.** It will hand the same visitor a hundred cards. The rule that a visitor gets one reveal per session is yours, in a cookie or local storage. It is rate limited per IP, which is abuse protection and not that rule
- 404 when no card on the website has a finished film, which is the state of a freshly seeded site. There is no fallback to a card without one. **The body says which kind of nothing it is** as of 7 August 2026, either no cards on the website in that language or no film among them, so a 404 here can be read rather than guessed at. Treat any 404 as nothing to draw; the message is for a person, not for branching on
- **Renamed from `/cards/random` on 3 August 2026**, before anything consumed it

**Six of these seven endpoints are public and carry nothing sensitive. `/cards/draw` is the exception:** it needs no authentication, and it answers with a credential that must be handled like the one below.

### Rules about playback URLs, from both endpoints

These come from how the video CDN is configured. They fail as a plain **403 with no explanation**, so they are close to undiagnosable if you do not know them in advance.

1. **A playback URL is short lived: thirty minutes.** Ask for a fresh one rather than keeping one alive, and do not put one anywhere it outlives that. `expires_at` is there so a player can renew before it dies rather than stalling mid-film

2. **A playback URL is not tied to a device or a person.** It works for anyone who holds it until it expires, which is exactly why the thirty minutes matters and why the handling rules below are not decoration

**Two rules were removed on 7 August 2026, and both make your life easier:**

- **URLs are no longer bound to the viewer's IP address.** They were from 3 August, and it could never have worked: this API sits behind a dual-stack Cloudflare and the video zone is reachable only over IPv4, so a visitor with IPv6 had a token signed for their v6 address and then arrived at the CDN over v4. That is a 403 for every visitor with working IPv6, on every film, every time
- **Which means the "fetch it from the browser, never from a server" rule is gone.** A URL fetched by a server component or a route handler now works for the visitor you hand it to. Caching one still does not become safe, for the reasons below, but the binding that made server-side fetching fatal is no longer there

**Still true:** the CDN currently refuses requests that arrive with no referrer, so a URL pasted into an address bar 403s and cannot be debugged by clicking it, and you should not set `Referrer-Policy: no-referrer` on pages that play video. **This is being turned off**, so treat it as a temporary hazard rather than a design constraint. If it is still biting when you read this, say so.

### GET /api/v1/{locale}/cards/{id}/video

**The Viewing Room.** One card's Living Tarot film, as a signed URL that expires. Requires an authenticated customer who holds the viewing room pass.

```json
{
  "card_id": 1,
  "url": "https://vz-xxxx.b-cdn.net/bcdn_token=...&expires=...&token_path=.../<video>/playlist.m3u8",
  "expires_at": "2026-07-30T14:22:00+00:00"
}
```

- **`url` is a credential with a timer, valid for thirty minutes.** Do not cache it, do not store it, do not put it anywhere it can be shared. The response carries `Cache-Control: no-store` and your side must respect that: a service worker or a persisted store turns half an hour of exposure into however long it keeps it
- **The rules above apply here too**, and matter more: this URL is the thing the pass is sold for, and since 7 August 2026 it works for anyone holding it rather than only for the person who asked
- **HLS, not MP4.** Same as the draw
- **Ask for a fresh one rather than reusing an old one.** `expires_at` is there so a player can renew before it dies rather than stalling mid-video
- **404 for a card whose video is not ready**, which includes one still transcoding, one whose film has been removed, and one not on the website in that language. There is genuinely nothing to serve
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
- **`/api/v1/{locale}/cards/draw` must never be cached or stored, by you or by anything between us.** It says so in its own headers, and it holds a signed film as well as a card. Everything else on the cards endpoints is as cacheable as the readings
- **`/api/v1/{locale}/cards/{id}/video` must never be cached or stored either**, for the same reason: the answer is a signed credential rather than content
- **Both of those mean a service worker and a persisted store too.** Either one turns half an hour of exposure into however long it keeps the URL, which is the single easiest way to undo the paywall from your side
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
