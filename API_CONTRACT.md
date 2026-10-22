# API contract

For the frontend partner building the Next.js website against this API.

Everything here is live unless marked otherwise. Read it alongside the generated reference at `/docs/api`: that describes shapes and is generated from the code, this explains the rules behind them.

~~Status: draft, not yet acknowledged. Until you confirm it, these shapes can still change freely. After that, changes are coordinated.~~

Status: in use as of 22 October 2026. The frontend builds against it, so changes are coordinated rather than made freely. Formal confirmation of the shapes (section 10, item 4) is still outstanding.

**Updated 12 August 2026 with member accounts and orders**, the endpoints at the end of section 2. They are the first part of this document you build against rather than only read from: registering, signing in, passwords, `me`, and placing an order. `GET /api/v1/user`, which this document always warned you not to build on, is gone and `GET /api/v1/me` replaces it.

**One thing is deliberately missing, and it is the obvious one.** Nothing collects money yet. An order is created and then waits for somebody here to mark it paid by hand.

**Updated 25 August 2026 with the checkout design**, and section 9 described it before it existed so you could object while objecting was cheap. **The two endpoints are now in section 2, where the built things are.** `payment_url` is gone, replaced by `pay_token` on the order and a second call to `/pay`.

**Updated 29 August 2026, and it reverses the line above that said checkout happens on your page.** At your request, and the reasoning is yours: your ADR 0002, `docs/adr/0002-checkout-happens-on-stripes-page.md` in your repo, and ours, `docs/adr/0002-the-redirect-case-comes-back.md` in this one. Three things follow, all of them in section 2:

- **`/pay` may answer a third instruction, `redirect`**, carrying the address of a Stripe hosted Checkout Session. The withdrawal of that case, published here on 26 August, is itself withdrawn
- **A guest may place an order holding no name and no email.** Stripe's page collects the buyer's email, and it collects it after the order exists, so identity is filled here at settlement
- **`POST /api/v1/orders/status` reads a payment's status back**, which answers the standing question in section 10 about whether your confirmation screen needs to re-read an order. It does

**Updated 2 September 2026 with gifting, and the headline is a withdrawal.** ~~`gift_code` as a payment method~~ is gone from this document before anybody built a button for it: a redemption collects nothing, so there was never a payment for a third method to make. The sentences that promised one are struck where they stood, in sections 2 and 9. **What gifting is instead** is a `gift` object on an order line, `is_giftable` on `/products`, and two endpoints of its own under "Gifts" in section 2. ~~**None of it is live yet**~~ **struck 3 September 2026: the two endpoints under "Gifts" answer now**, and each piece says where it stands where it is documented. The reasoning is `docs/adr/0004-a-reading-is-a-row-of-its-own.md` on our side and `docs/adr/0003-redemption-is-a-page-of-its-own.md` on yours.

**What has not changed is the order of the calls and the shape of everything else.** You still place an order, you still POST the token to `/pay`, and you still read `type` before you read anything else.

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
  { "code": "en", "label": "English", "native_name": "English" }
]
```

- A single-item array until a second language goes live. Use the length to decide whether to render a language selector at all
- **`native_name` is the language's name in itself, added 9 September 2026.** Draw your switcher's rows from it rather than from `label`: a switcher is read by somebody who cannot read the language the page is currently in, so "Español" works where "Spanish" does not. `label` stays, and is the name in English
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

### GET /api/v1/payment-methods

**Which ways of paying this environment offers, and therefore which buttons to draw.** Added 29 August 2026, with `stripe_wallet`. Flat, with no language segment, because it carries no copy.

```json
{ "methods": ["stripe", "stripe_wallet"] }
```

- **`stripe` is the card button.** It answers `redirect` from `/pay`, a Stripe hosted Checkout Session
- **`stripe_wallet` is the wallet button**, Apple Pay and Google Pay in the Express Checkout Element on your own page. It answers `client_secret` from `/pay`
- **These are the exact values you send as `method`** on `POST /api/v1/orders/{pay_token}/pay`. They are not labels and carry no copy. What a button says is yours
- **Draw a button only for a method in this list.** Whether Stripe is offered at all depends on whether that environment is configured, and a local one legitimately offers nothing. **An empty list is a valid answer**, and it means there is nothing a customer can press here. It is not a fault to retry
- **The list can grow.** ~~`gift_code` joins it with gifting.~~ **Struck 2 September 2026: gifting adds no payment method, and this list stays at two.** A redemption collects nothing, so there is no payment for a third method to make. What gifting adds instead is two endpoints of its own, below. Ignoring a name you have no button for rather than treating it as an error is still the rule, and still the reason this is safe to grow
- **Hand settlement is deliberately never in this list.** It exists, it is how support settles an order that went wrong, and it is a person in the admin panel rather than anything a customer presses
- **The order is ours and is stable**, so you may render in it if you like
- Safe to cache briefly. It varies by what the environment configured and by nothing about the visitor

### GET /api/v1/{locale}/products

Everything on sale, in the order the client arranged them.

```json
[
  {
    "key": "one-card",
    "type": "reading",
    "name": "One Card Reading",
    "short_description": "A single card.",
    "allows_question": true,
    "is_giftable": false,
    "price": { "currency": "GBP", "amount": 1900 }
  }
]
```

- **Published readings only.** Anything unpublished is absent
- **`key` is the product's permanent identifier and its URL segment.** It is the same in every language, it is never edited, and it is a fixed set: `one-card`, `three-card`, `month-ahead`, `in-depth`, later `viewing-room-pass`, plus `rush-24h`, which **is cancelled and will never be sold** — see section 9. It remains in the enum only because it is unseeded, and our tests use it as their example of a key nobody seeded. The generated specification carries it as an enum, so you can build an exhaustive switch over it and let your compiler tell you when we add one
- `type` is `reading`, `pass` or `addon`, so you can map presentation to it. **Changed 20 August 2026: this list now contains the viewing room pass as well as the readings**, so `type` is how you tell them apart rather than something you can currently ignore. See the note below
- `allows_question` means the customer may attach a question when buying. It is never required of them
- **`is_giftable` means this product may be bought for somebody else**, added 2 September 2026 with gifting and **not live until the gifting work lands**. It is `true` for `three-card`, `month-ahead` and `in-depth`, and `false` for `one-card` and the pass. **Draw `GIFT A READING` from this field and never from a list you hold**, exactly as you already draw the question field from `allows_question`: a gift object sent on a line that is not giftable is a 422, so a hardcoded toggle is a button that refuses on submit. The two fields are independent, and `one-card` is the product that proves it: it takes a question and cannot be gifted
- `price` is exactly one price, in the currency this request resolved to. See section 5

**A product appears only in the languages it is finished in.** If a reading is added after Spanish goes live, it is in the English list immediately and joins the Spanish list when its Spanish copy is written. So the two lists can legitimately differ in length, and a language switcher on a product page should hide any language that product is not available in.

**The viewing room pass is in this list, as of 20 August 2026.** It was deliberately excluded before then, on the grounds that it has a page of its own rather than sitting among the readings. That was the wrong cut: your Choose Your Journey section sells all four things side by side, so excluding one only meant fetching it separately to rebuild a list we could have returned whole. It is still also available at its own key.

**So expect five entries, not four:** `one-card`, `three-card`, `month-ahead`, `in-depth`, `viewing-room-pass`. If your homepage shows four tiles, match on `key` and ignore what you have no artwork for. `in-depth` has no tile today.

~~**When the rush add-on arrives it will not appear here**, because nothing sells an add-on on its own.~~ **Moot from 25 August 2026: the rush was dropped and there is no add-on.** `type` is still `reading`, `pass` or `addon`, and nothing returns `addon` today. If a future add-on gives you a reason to filter yourself, ask and we will add `?type=`.

**Everything we seed is complete and priced**, changed the same day. A product used to be created with empty copy and a zero price, which meant a deploy against a fresh database produced a shop with nothing in it. Seeding now fills anything empty and never overwrites an edit, so **an empty products list should be treated as a fault rather than a state to design for.** It can still legitimately be *shorter* in a language that is not fully translated.

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

**Still true, and measured on 16 August 2026 rather than assumed:** the CDN refuses requests that arrive with no referrer. A freshly issued URL answers **403 with no `Referer` and 200 with one**, everything else held equal. So a playback URL pasted into an address bar will 403 and cannot be debugged by clicking it, and **`Referrer-Policy: no-referrer` on a page that plays video breaks every film on it.** Please check that header before you ship, including anything a security scanner or a platform default might add for you.

We intend to turn this off, and said so on 7 August. It is still on nine days later, so **build as though it will stay on** and treat it being turned off as a constraint being lifted rather than a date you can plan around.

**The embed player is not available to you.** `iframe.mediadelivery.net/embed/...` served films with no token and no expiry until **16 August 2026**, when that was closed. It now refuses. There is one supported way to play a film and it is the signed HLS URL these endpoints return. If you had an iframe player anywhere as a fallback or in a spike, it stopped working that day and this is why.

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
- **429 above 30 requests a minute**, counted per signed-in customer rather than per address, carrying `Retry-After` in seconds. Renewing before `expires_at` costs one request about every 30 minutes, far below that, so a player that renews correctly never meets it. A 429 here means something is requesting in a loop

**Access is decided here, every time.** Do not gate the player on anything the browser knows. If your UI shows a locked state, that is presentation, and this endpoint is the enforcement.

Both cookie auth and bearer tokens work, as with every protected route.

---

### Member accounts

**None of these carry a language segment**, because an account is the same account in every language. `/api/v1/register`, not `/api/v1/en/register`. Wording in the responses below is English only for now; anything a customer reads should come from your side rather than from ours.

All of them work under both authentication styles. Section 6 explains how to choose.

**`name` can be `null` in every customer body below, since 30 August 2026, issue #52.** A customer created at settlement was never asked their name: Stripe's hosted page collects a text field the buyer types, and a wallet may collect no name at all. It used to be filled with the part of the address before the `@`, so a real customer read "Hello jennifer," in their receipt, and that invention is gone.

- **Render nothing rather than the string "null"**, and treat a missing name as ordinary rather than as an error
- **A buyer who claimed their account is where you will actually meet it.** Settlement made that account and nobody asked a name for it, so `POST /api/v1/login` and `GET /api/v1/me` answer `null` for them from their first sign in onward. `POST /api/v1/set-password` itself carries no customer body, only a message
- **`POST /api/v1/register` always has a name**, since that form requires one

#### POST /api/v1/register

```json
{
  "name": "Jane Doe",
  "email": "jane@example.com",
  "password": "a decent long one",
  "password_confirmation": "a decent long one"
}
```

**201** with the customer, and registering signs them in. There is no separate sign in call afterwards.

```json
{
  "id": 12,
  "name": "Jane Doe",
  "email": "jane@example.com",
  "created_at": "2026-08-12T10:04:00+00:00",
  "has_viewing_room_access": false
}
```

- **`password_confirmation` is required and checked here.** Your own confirm field is not enough
- **Email is lowercased before it is stored and before it is checked for duplicates**, so `Jane@` and `jane@` are the same account and cannot both be registered
- **422 on a duplicate email**, keyed to `email`. This one does reveal that an address is taken, which registration cannot avoid without leaving people unable to find out why they were refused. The sign in and forgotten password endpoints do avoid it
- **That message names the claim path, and deliberately does not say whether the address is claimed.** It reads: "We cannot make an account for that address. If you have bought a reading with it, ask for a link on the sign in page and choose a password there." Somebody who bought a reading already has an account with no password on it, and this rule is often exactly that person. Telling an unclaimed account apart from a claimed one here would answer the question the other three endpoints refuse, so the way out is offered on a condition only the reader can evaluate. **Render the message as it is**, and do not write copy that says the address is registered
- Password strength is enforced here and the message comes back on the `password` field. Match your Zod rules to ours or people will pass yours and fail ours
- **There is no email verification**, deliberately. Nobody is asked to confirm an address before they can use the site
- Rate limited to 10 a minute per address

#### POST /api/v1/login

```json
{ "email": "jane@example.com", "password": "a decent long one" }
```

**200**, the same customer shape as above.

- **A wrong password and an unknown email are indistinguishable**: identical status, identical message, on the `email` field. That is on purpose and will not be changed, so do not write UI that says "no account with that address"
- **429 after 5 failures for the same email and address pair**, carrying `Retry-After` in seconds. Not 422. A separate 20 a minute limit applies per address regardless of which account is being tried
- **A customer whose account was made for them by a purchase cannot sign in at all until they set a password.** They get the same indistinguishable failure. Section 2's set-password endpoint is how they get out of that state

#### POST /api/v1/logout

**204**, empty. Requires authentication.

- On the cookie flow it invalidates the session and rotates the CSRF token, so **fetch `/sanctum/csrf-cookie` again before your next write**
- On the token flow it deletes only the token that made the request. Signing out of a phone does not sign the same person out of their laptop

#### GET /api/v1/me

Who you are talking to. Requires authentication. **401** when not signed in, which is the normal answer for a visitor and not an error worth showing anybody.

```json
{
  "id": 12,
  "name": "Jane Doe",
  "email": "jane@example.com",
  "created_at": "2026-08-12T10:04:00+00:00",
  "has_viewing_room_access": true
}
```

- **`has_viewing_room_access` is what lets you choose between "enter the Viewing Room" and "buy the pass"** without guessing
- **It is a hint for your UI and never the enforcement.** The film endpoint decides again, every single time. A `true` here with a 403 there is possible, if a pass were withdrawn between the two calls, and the 403 is the truth
- `Cache-Control: no-store`. It is different for every caller, so a shared cache holding it serves one customer's name and address to the next

#### POST /api/v1/forgot-password

```json
{ "email": "jane@example.com" }
```

**Always 200, always the same body**, whatever happened:

```json
{ "message": "If we have an account for that address, a reset link is on its way." }
```

- **A known address, an unknown one, and one asking again too soon are identical.** Anything else makes this a way to ask whether somebody has an account here. Show the message as it is, and never phrase your own copy as "sent", because sometimes nothing was
- Rate limited to 6 a minute per address, and a second limit of one message per account per 60 seconds
- The link points at **your** site, since we render nothing a customer sees. See section 10

#### POST /api/v1/reset-password

```json
{
  "token": "from the link",
  "email": "jane@example.com",
  "password": "the new one",
  "password_confirmation": "the new one"
}
```

**200** on success. **422** keyed to `email` when the token is wrong, already used, or older than 60 minutes.

- **`token` and `email` both come from the link's query string and must be sent back unchanged.** The reset fails without both
- Single use, and 60 minutes
- **Every bearer token that customer holds is deleted by a successful reset.** Somebody resetting a password is often somebody who thinks another person has it. Browser sessions elsewhere are not killed, which is a real gap and stated rather than hidden

#### POST /api/v1/set-password

The same body as a reset, and a **different endpoint on purpose**: this is somebody claiming the account that was created for them when they bought something, following the link in the mail that arrives once their order is paid. **Since 30 August 2026 that mail is sent only for an order that grants Viewing Room access**, so in practice this is a pass buyer choosing their first password. A reading buyer is never sent one and reaches the same place through `/api/v1/forgot-password`, which sets a first password just as well.

**200** on success. **422** on a used or expired token, and equally on an account that already has a password.

- **It works exactly once, on an account that has never had a password.** After that the link is worth nothing even inside its 60 minutes, and changing a password becomes an ordinary reset
- **Build it as its own page with its own words.** Nothing is being reset, and telling somebody to "reset" a password they never had reads as a mistake on our side
- A failure here says the link is no longer valid without saying which of the possible reasons applies, for the same reason the sign in failure is vague
- **One live claim link per address at a time.** Somebody whose orders reach the send twice inside the hour gets the claim link in the first receipt only, and the second receipt carries none. Since the mail follows the pass rather than the payment, that now takes two pass orders settling together, which is possible because buying the pass while holding it is refused at placement but two orders placed before either settles both go through. Minting a second link deletes the first, so sending both would mean two receipts of which one is already dead and neither says which. Nothing for you to render differently, and worth knowing when a customer says their link is missing from a receipt
- **Asking for a reset link inside 60 seconds of a claim link being minted sends nothing at all**, because that is the broker's throttle. `/api/v1/forgot-password` still answers its usual 200 with its usual body, so this is invisible on the wire and is exactly the case its wording is written for. The claim link they already hold is untouched and still works

### Orders

#### POST /api/v1/orders

Placing an order. **Open to anybody**, signed in or not, because demanding an account before somebody can buy costs sales at the worst possible moment.

No language segment: an order is the same order whatever language it was placed in.

```json
{
  "name": "Jane Doe",
  "email": "jane@example.com",
  "currency": "GBP",
  "lines": [
    { "product": "one-card", "quantity": 1, "question": "What should I focus on this month?" },
    { "product": "viewing-room-pass" },
    {
      "product": "month-ahead",
      "gift": {
        "recipient_email": "sam@example.com",
        "signature": "Mum",
        "message": "Thought of you.",
        "locale": "en"
      }
    }
  ]
}
```

**201:**

```json
{
  "id": 41,
  "status": "pending",
  "currency": "GBP",
  "total_amount": 6400,
  "lines": [
    { "product": "one-card", "unit_amount": 1900, "quantity": 1, "question": "What should I focus on this month?", "gift": null },
    { "product": "viewing-room-pass", "unit_amount": 4500, "quantity": 1, "question": null, "gift": null },
    {
      "product": "month-ahead",
      "unit_amount": 5500,
      "quantity": 1,
      "question": null,
      "gift": { "recipient_email": "sam@example.com", "signature": "Mum", "message": "Thought of you.", "locale": "en" }
    }
  ],
  "pay_token": "kQ3rN8xvT1sLb0Zy..."
}
```

- **`name` and `email` are optional for a guest, since 29 August 2026, and ignored for a signed-in customer**, who always gets their own account. Nobody can attach an order to somebody else while authenticated as themselves. **This relaxes a requirement rather than removing a capability**: an order that arrives with a name and an email behaves exactly as it always has, and if you already collect them, keep sending them
- **A guest order that arrives with no identity gets one at settlement**, filled from the Checkout Session's `customer_details`, which is where Stripe's page collects the buyer's email. An identity the order already carried is never overwritten. An order that is abandoned therefore stays `pending` holding nobody, which is safe, because placing an order sends no mail. Reasoning in `docs/adr/0002-the-redirect-case-comes-back.md`
- **An email with no account gets one made for it**, with no password. It cannot be signed in to until a password is set. **A claim link arrives only when the paid order grants Viewing Room access**, and never when an order is placed. A reading buyer is sent nothing about an account at all and sets a first password through `/api/v1/forgot-password` if they ever want to sign in
- **`currency` is required and is taken exactly as sent.** No detection happens here. It is one currency for the whole order, because a basket priced in two has no total. An unsupported one is a 422 rather than a fallback
- **`quantity` defaults to 1**, maximum 10 per line. At most 10 lines
- **`question` is refused with a 422 on any product whose `allows_question` is false**, and is never required on one where it is true
- **`gift` makes the line a present for somebody else**, added 2 September 2026 and **not live until the gifting work lands**. Four fields, and the whole of the buyer's side of gifting:
  - **`recipient_email`** is where the code is sent, and it is required. **Collect it twice on your side**, because the buyer never receives the code and a typo'd address is otherwise unrecoverable: nothing on this side can tell that a gift mail arrived nowhere
  - **`signature`** is who it is from, required, and it is not a name. It is what the recipient's mail is signed and subject-lined with, so "Mum" is a truer answer than what is on the card, and an order may carry no name at all. An unsolicited mail carrying a code and a link from a brand somebody has never bought from is shaped exactly like phishing, and **a named sender is the strongest signal it is real**
  - **`message`** is optional, and it is the buyer's own words to the recipient
  - **`locale`** is which language the recipient's mail is written in, and it defaults to the language the order was placed in. It is a language code from `/languages`
- **A `gift` on a line whose `is_giftable` is false is a 422 keyed to that line**, `lines.2.gift`, and it is **refused rather than quietly ignored**. Silently dropping it would charge somebody for a present that was never sent
- **A gift line carries no `question`.** The person who will ask has not seen it yet, and asking is what redemption is for. Sending both is a 422, keyed to that line: `lines.0.question`. **Enforced since 3 September 2026**, and until that day the `question` was silently dropped instead. An empty string still buys an ordinary present, because a field nobody filled in is nothing asked
- **`gift` is present on every line and null on the ones nobody gifted**, corrected 3 September 2026 when this was built. The example above omitted the key on those two lines and the shape is present-and-null, the same as `question` on a line that takes none, so nothing has to tell an absent key from an empty one
- **The code does not come back here and never appears in this response.** It does not exist yet: it is minted when the payment settles, so that an abandoned checkout leaves no live credential behind. What the buyer gets is a receipt naming the address it went to
- **One bad line refuses the whole order**, with the error keyed to that line: `lines.0.product`. Nothing is silently dropped, because charging somebody for a basket they did not agree to is worse than making them try again
- **Unpublished, unpriced and retired products are all refused** with the same message, `That is not something you can buy right now`
- **The Viewing Room pass can only be bought once, and this is new on 24 August 2026.** It never expires, so it is the only thing in the catalogue somebody can already hold. Two of it in one basket, whether as `quantity: 2` or as two lines, is a 422, and so is buying it while holding it. A signed-in customer is told `You already have this, and it does not expire`. **A guest gets the same wording an unavailable product gets**, because naming the reason to somebody who has only typed an address would turn this endpoint into a way of asking whether that address holds a pass. Every other product can be bought as often as anybody likes. **Where you can, offer the pass as already owned rather than as something to buy**, using `has_viewing_room_access` from `me`, so nobody meets this refusal at the checkout
- **`total_amount` and every `unit_amount` are integer minor units**, and they are captured at this moment. A later price change never moves an existing order
- **Placing an order sends no email whatsoever.** Anybody can type any address into a checkout, so if placing an order sent mail this endpoint would be a free email bomb aimed at whoever the sender names. The first message goes out when the order is paid
- `Cache-Control: no-store`
- Rate limited to 20 a minute per address

**`pay_token` replaced `payment_url` on 25 August 2026**, and the body you send, the rules and the errors are unchanged. What you get back is the address of this order rather than a checkout URL: you POST it to `/api/v1/orders/{pay_token}/pay` to start a payment, and that call is what may hand you somewhere to send the customer. See the next endpoint.

**Placing an order collects nothing**, so **treat 201 as "your order is recorded", never as "you have paid"**. It sits at `pending` until a payment succeeds or somebody here settles it by hand.

**`pay_token` is unguessable and it is a credential.** Order ids are sequential integers, so an id in a URL is a number anybody can count to. Holding this token is the whole of the authority to pay for the order, which is what lets a guest come back to theirs. **Do not put it in an address bar, a redirect, an analytics event or a log.** Keep it in memory for the checkout, or in the customer's own browser storage if you want them to be able to come back.

**There is still no way to read the order itself back**, and no order history. What there is, since 29 August 2026, is a way to read the payment's status: `POST /api/v1/orders/status`, two endpoints below. If an account area needs the order as well, say so.

### POST /api/v1/orders/{pay_token}/pay

**Starting a payment for an order you have already placed.** Built 25 August 2026. Open to anybody, because the token is the authority. No language segment.

The body is optional and has two fields:

```json
{ "method": "stripe", "return_to": "month-ahead" }
```

**200**, and it names which of three shapes it is:

```json
{ "type": "redirect", "redirect_url": "https://checkout.stripe.com/c/pay/cs_..." }
{ "type": "client_secret", "client_secret": "pi_..._secret_..." }
{ "type": "nothing_to_pay" }
```

- **Read `type` and then read the field it names.** Do not infer from the shape of what came back. Handle an unknown `type` by not crashing, since a new payment method can add one
- **`redirect` means send the browser to `redirect_url`**, and it is a Stripe hosted Checkout Session. **Back on 29 August 2026**, at your request and on your reasoning: a panel cannot be laid out around a control whose height depends on whether the visitor's device has a card in its wallet. It was published here, withdrawn on 26 August as a case with no caller, and now has one. Both ADRs carry the argument, `docs/adr/0002-checkout-happens-on-stripes-page.md` in your repo and `docs/adr/0002-the-redirect-case-comes-back.md` in ours
- **Do not store the URL and do not treat it as the order's address.** A Session expires after 24 hours and an order never does, so a customer coming back calls `/pay` again and is handed a fresh one
- **`client_secret` is what you confirm with Stripe** in the Express Checkout Element, on your own page, and **it has a caller again from 29 August 2026**: `stripe_wallet` answers it. It is a PaymentIntent's secret, so `stripe.confirmPayment` is what takes it, and **`return_url` for that call is your own page and you build it**. That is not the open redirect this document guards against elsewhere: nothing caller-supplied reaches us on this road, and the only redirect in play is 3D Secure returning to where it started
- **`nothing_to_pay` means there is nothing to collect** and you should go straight to your confirmation. It is what an order already settled answers, and what a hand settled order answers. ~~And what a gift code redemption will answer.~~ **Struck 2 September 2026**: a redemption never reaches this endpoint. The case itself is unchanged and keeps both its callers
- **`success_url` and `cancel_url` are built here, from a frontend origin configured on this side, and you never send them.** A caller-supplied return address is an open redirect available to anybody holding a pay token, and allowlisting the origin to fix it leaves us building the address anyway
- **`return_to` is optional, and it is a product key**, one of `one-card`, `three-card`, `month-ahead` or `in-depth`. **Not a path and not a URL.** It says which reading page the checkout started from, so a cancelled one lands back on it. The keys are already your reading-page slugs, so nothing you send is ever concatenated into an address: **anything outside that set is a 422 on `return_to`**, never a fallback. Send nothing and a cancelled checkout lands on the readings index
- **Where a paid customer comes back to is ours as well**, `/checkout/complete/` carrying the Checkout Session id. **Corrected 29 August 2026: that id is not what you hand to `POST /api/v1/orders/status`, and an earlier line here said it was.** A `cs_...` id is opaque, it cannot be resolved with a publishable key, and there is nothing on this side that maps one back to an order. **Keep the pay token yourself across the redirect**, in the customer's own browser storage, and hand that to the status endpoint. The session id in the query string is Stripe's, and you can ignore it
- **`method` names which button was pressed, and from 29 August 2026 there are two.** ~~Everything a card processor handles, Apple Pay and Google Pay included, is one method~~: they are now `stripe`, the hosted page, and `stripe_wallet`, the wallet element on your own page. They are two because they are a hosted Session and a PaymentIntent, and no Stripe parameter reconciles those. **`GET /api/v1/payment-methods` is what says which exist here.** ~~And gifting adds `gift_code` to the same list.~~ **Struck 2 September 2026: it does not, and there are two**
- **It is still optional and you can still leave it out**, and leaving it out gets whichever method this environment defaults to, which is not a promise about which. Send one. **A method this environment does not offer is a 422 on `method`**, never a fallback to one that does
- **Calling this twice on a pending order is safe** and gives you a fresh answer, a new secret or a new Session, which is what a declined card and an abandoned checkout should both do. It creates no second order
- **Orders never expire.** A customer who closes the tab mid-payment still has theirs days later
- **An order already paid answers `nothing_to_pay` and creates nothing**, so a customer cannot be charged twice for one thing
- **An unknown token is a 404. So is a token for somebody else's order while you are signed in as a different customer**, and the two answer identically, because the difference between them is information
- **Nothing about the order is echoed back.** Not the amount, not the email, not the id. A guessed token leaks nothing
- `Cache-Control: no-store`
- Rate limited to 30 a minute per address, looser than placing an order, because a declined card is a retry

### POST /api/v1/orders/status

**Reading a payment's status back.** Added to this document 29 August 2026, and it exists because a hosted checkout hands your success page a Checkout Session id, which is opaque and cannot be resolved with a publishable key. Open to anybody, because the token is the authority. No language segment.

**The pay token goes in the body, not in the path, and this is not a `GET`.** The token is a credential, and a path segment lands in every access log between the browser and this application.

```json
{ "pay_token": "kQ3rN8xvT1sLb0Zy..." }
```

**200:**

```json
{ "status": "succeeded" }
```

- **`status` is Stripe's PaymentIntent status, retrieved here, passed through unchanged.** It is not the order's status, and it is deliberately not `pending`/`paid`: your mapping from Stripe's statuses onto the outcomes you render is already written, and a customer who arrives before the webhook lands would otherwise read a screen hedging about a payment that plainly succeeded
- **The values are Stripe's, and the set can grow.** Today: `requires_payment_method`, `requires_confirmation`, `requires_action`, `processing`, `requires_capture`, `canceled`, `succeeded`. **Treat an unrecognised one as "we do not know yet" and poll again**, never as a failure
- **An order with no payment started yet answers `requires_payment_method`**, which is the same thing your polling does with it: nothing has been collected
- **An order settled by hand answers `succeeded`**, ~~or by a gift code~~ **struck 2 September 2026**, so a confirmation screen has one path rather than two. A gift is paid for when it is bought, so the order behind a redeemed code answered `succeeded` months before anybody redeemed anything, and **a redemption never calls this endpoint at all**
- **Nothing else about the order comes back.** Not the amount, not the email, not the id, exactly as on `/pay`
- **An unknown token is a 404**, and so is somebody else's, and the two answer identically
- **If Stripe cannot be reached, this answers 503** and says nothing about the payment. Guessing `processing` would be us inventing a status about somebody's money. **Treat it exactly as an unrecognised status: we do not know yet, ask again**, and let your backoff do its job
- **This is a poll, so poll it politely.** A short interval for a few seconds after the return, then back off. The webhook is what settles the order; this only tells you what Stripe already thinks
- `Cache-Control: no-store`
- Rate limited to 60 a minute per address, since a confirmation screen polls

### Gifts

~~**Two endpoints, and neither is built yet.**~~ **Both are built, 3 September 2026.** Designed 2 September, documented here rather than in section 9 because their shapes are settled and the frontend's `/redeem/` page is built against them. They answer now, and every shape below is what they actually return. **Committed and not yet pushed or deployed**, so `/redeem/` cannot be pointed at a deployed environment until it is.

Two things worth having in writing, because the frontend can neither see them nor infer them:

- **Both answer 200 on success**, redemption included. It creates a reading and still does not answer 201, because a 201 offers the address of the thing it made and nothing on your side addresses a reading
- **The two rate limits are separate counters**, so mistyping a code ten times on the lookup does not cost anybody the redemption they were about to make

**Looking a code up and spending it are two calls on purpose.** A visitor who opens the link and closes the tab must still have their present.

#### POST /api/v1/{locale}/gifts/lookup

**What a code is worth, without spending it.**

```json
{ "code": "k7m4 9pqr 2xyz" }
```

**200:**

```json
{
  "code": "K7M4-9PQR-2XYZ",
  "product": "month-ahead",
  "name": "THE MONTH AHEAD",
  "short_description": "...",
  "long_description": "...",
  "redeemed": false,
  "redeemed_at": null
}
```

- **The code goes in the body, and this is not a `GET`.** It is a bearer credential, and a path segment or a query string lands in every access log and every proxy between the browser and this application. `POST /orders/status` takes its credential the same way and for the same reason
- **Entry is normalised here before anything is looked up**: uppercased, everything that is not a letter or a digit stripped, `I` and `L` read as `1`, `O` read as `0`. So `k7m4 9pqr 2xyz`, `K7M4-9PQR-2XYZ` and `k7m49pqr2xyz` are one code. **Be forgiving on your side too**, but do not try to reproduce this: send what was typed
- **`code` comes back in its printed form**, `XXXX-XXXX-XXXX`, so you can show the visitor what you resolved
- **The reading's copy comes back with it**, in the language of the path. That is deliberate rather than an invitation to call `/products/{key}`: **a code for a withdrawn product still redeems**, and `/products/{key}` 404s on anything unpublished. The person paid. This is the one place in the system that reads a product past `is_published`
- **Live since 3 September 2026.** Nothing about the shape above changed when it was built
- **`redeemed` is the answer in full, and it does not hedge.** A code that has already been spent says so, and says when. That is a state the real recipient has to be told about, so it cannot hide behind the answer an unknown code gets. **What closes the oracle is the entropy in the code**, roughly 60 bits, plus the throttle below
- **The words are yours.** This returns booleans and a date, never a sentence to display, because nothing here renders customer-facing copy
- **An unknown code is a 404**, with no distinction between one that never existed and one that is malformed past normalising
- **A product with no page of yours still resolves**, which is why `name` and the two descriptions are here: fall back to them rather than refusing to render. **Refusing to render is refusing to redeem**
- `Cache-Control: no-store`. A response naming what a bearer credential is worth is not cacheable by anything
- Rate limited per address, and it is the tighter of the two limits, because this is the endpoint somebody would guess against

#### POST /api/v1/{locale}/gifts/redeem

**Spending the code.** This is the only call that spends it, and it happens on submit, never on arrival.

```json
{
  "code": "K7M4-9PQR-2XYZ",
  "question": "What should I focus on this month?",
  "querent_email": "sam@example.com",
  "querent_name": "Sam"
}
```

**200:**

```json
{
  "product": "month-ahead",
  "name": "THE MONTH AHEAD",
  "question": "What should I focus on this month?",
  "querent_email": "sam@example.com",
  "asked_at": "2026-12-24T10:03:11+00:00"
}
```

- **Live since 3 September 2026.** Nothing about the shape above changed when it was built
- **`question` is required**, and every giftable product allows one. This is the moment the reading is asked for, and a reading without a question is not one. **Maximum 2000 characters**, the same limit a buyer's question gets at checkout, so two screens do not tell one person two different things
- **`querent_email` is required, and it is asked for rather than inherited** from the address the gift was sent to. A forwarded gift is enough to part those two people, and inheriting silently would deliver the reading to whoever forwarded it. `querent_name` is optional, exactly as a buyer's name is
- **The answer is the confirmation**, and it is rendered from this rather than from a second call. **Do not call `POST /api/v1/orders/status` after redeeming.** That endpoint reports a payment, and the payment here happened months ago to somebody else
- **`asked_at` is when the clock starts.** State the reading's delivery window relative to it if you show one, as a property of the reading rather than as a promise this call makes. The promise is made once, in the mail this sends to the querent
- **Spending is atomic and single use.** The row is locked, so two submits from two tabs produce exactly one reading, and the loser is refused rather than served
- **A code already spent is a 409**, and the body names the field: `{ "message": "...", "errors": { "code": ["..."] } }`. It is not a 404, because the difference between "you mistyped it" and "this has already been used" is exactly what the real recipient needs to be told
- **An unknown code is a 404**, as on lookup
- **A code for a withdrawn product still redeems here too**, for the same reason
- **The querent is sent a mail of her own**, which is what makes the delivery promise durable after the tab is closed. **Live since 3 September 2026**, B8. It states the window and the moment it runs to, counted from `asked_at`, so this response stays the one that reports rather than promises
- `Cache-Control: no-store`
- Rate limited per address

### POST /api/v1/newsletter

**Joining the mailing list.** Built 15 August 2026, live on staging and on production. Flat, with no locale segment, and no authentication. **An earlier version of this document listed newsletter signup under "not built yet", which was true when written and is not now.**

Your coming-soon page already posts to it. The footer form can use the same endpoint whenever it is wired up, which is why `name` is accepted and optional.

```json
{
  "email": "someone@example.com",
  "consent": true,
  "name": "Jennifer"
}
```

- **`email` is required**, and is lowercased before validation, so `Jennifer@` and `jennifer@` cannot become two contacts that look identical in her audience. **Maximum length 254**, which is RFC 5321's limit. Please match that on the field rather than choosing a shorter number
- **`consent` is required and must be accepted.** The checkbox gates a button on your side, which anybody can post around, and the copy beside it makes a promise about what somebody agreed to. A request that never agreed must not be able to satisfy that promise by leaving the field out
- **`name` is optional** and may be omitted entirely
- **`website` is a honeypot.** Leave it out of real submissions. Anything that fills it in receives an ordinary success and is discarded, because refusing would tell whoever sent it which field to leave alone next time

**The answer is 202, not 201.** Nothing has been created. The subscription is handed to a queue and the request returns immediately, because a request that waits on a third party fails whenever that third party is slow.

```json
{ "subscribed": true }
```

**The response is identical for a new address, an address already on the list, and an address the list will refuse later.** That is deliberate. Anything else turns a public endpoint into a way of asking whether a named person is subscribed, which is their business rather than ours. **So there is no 409 to handle and "already subscribed" is not a state you can render.**

- **Rate limited to 6 a minute**, answering 429 with `Retry-After`. Filling in a form takes one
- **What goes wrong afterwards is invisible to the visitor, deliberately.** If the list refuses an address later, that is dealt with here. The person has been told we have their address, which is true. Please do not word your confirmation as a promise that they are now on the list

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

**Which one you get is decided by a single field.** Send `device_name` on register or login and you get a bearer token in the response. Omit it and you get a session cookie and no `token` field at all. The website omits it.

### Cookie flow, for the website

1. `GET /sanctum/csrf-cookie` once, before your first authenticated request
2. Send `credentials: "include"` on **every** request after that, including that first one
3. Read the `XSRF-TOKEN` cookie and send it back as the `X-XSRF-TOKEN` header on any POST, PUT, PATCH or DELETE. Axios does this automatically, `fetch` does not
4. Call `/api/v1/register` or `/api/v1/login` **without** `device_name`. The session is established by that call
5. Fetch `/sanctum/csrf-cookie` again after signing out, since logging out rotates the token

Three things break this silently:

- **Your origin must be registered with us**, in both the CORS allow list and the stateful domain list. If it is missing, requests fail with what looks like a network error
- **Both applications must share a root domain.** The session cookie is scoped to `.theworldtarot.com`, which is why the API is `api.theworldtarot.com` rather than a separate domain. Staging mirrors this exactly
- **An origin that cannot hold a session gets a 422 on the `device_name` field**, saying so in as many words. If you see that message on the website rather than in an app, your origin is not on the stateful list, and no amount of changing your own code will fix it. Tell us

### Token flow, for later native apps

Send `device_name` when registering or signing in, then `Authorization: Bearer <token>` on everything after. No CSRF, no cookies, no origin registration. Every protected route works under both.

- **The token is returned once and cannot be retrieved again.** Store it as a credential
- It is named after the device so one can be revoked without ending every session
- A password reset deletes every token that customer holds

**This is the part of the system with the least real-world exposure so far**, since cookie auth across two subdomains only genuinely happens in a browser on the real origins. If anything here behaves differently from what this section says, that is a finding worth reporting immediately rather than working around.

## 7. Error responses

Everything under `/api/*` returns JSON, never an HTML error page.

| Status | When | Body |
|---|---|---|
| 401 | Not authenticated | `{ "message": "Unauthenticated." }` |
| 403 | Authenticated but not allowed | `{ "message": "..." }` |
| 404 | No such route or record | `{ "message": "..." }` |
| 419 | CSRF token missing or stale on a cookie-auth write | `{ "message": "..." }` |
| 409 | The thing is in a state that refuses this, and trying again will not help | `{ "message": "...", "errors": { "field": ["reason"] } }` |
| 422 | Validation failed | `{ "message": "...", "errors": { "field": ["reason"] } }` |
| 429 | Rate limited | `{ "message": "..." }` plus `Retry-After` |

On 422, `errors` is keyed by field name with an array of messages per field, and field names match what you sent.

**409 was added 2 September 2026, with gifting, and it has exactly one caller: a gift code that has already been spent.** Live since 3 September 2026, on `POST /gifts/redeem`, keyed to `code`. It carries a 422's body so your existing error handling reads it unchanged, and it is a separate status because a 422 says "fix what you sent and send it again" and there is nothing here to fix.

**Backend validation is authoritative.** Validating with Zod as well is right, but assume every rule is enforced again here and that ours wins. If the two disagree, say so rather than working around it.

## 8. Caching

- `/api/v1/languages` is identical for every visitor and fully cacheable, but **cache it briefly**. It is what tells you a language has been taken down, so a long cache delays the switch you are relying on
- Content URLs carry the language in the path, so each language caches separately with no configuration at all. Currency is the only remaining variance: sending `?currency=` explicitly makes them cleanly cacheable, relying on country detection does not
- **`/api/v1/{locale}/cards/draw` must never be cached or stored, by you or by anything between us.** It says so in its own headers, and it holds a signed film as well as a card. Everything else on the cards endpoints is as cacheable as the readings
- **`/api/v1/{locale}/cards/{id}/video` must never be cached or stored either**, for the same reason: the answer is a signed credential rather than content
- **Both of those mean a service worker and a persisted store too.** Either one turns half an hour of exposure into however long it keeps the URL, which is the single easiest way to undo the paywall from your side
- `/api/v1/currencies` contains `detected`, which is per-visitor. It must not be cached at a shared edge on URL alone, or one visitor's currency gets served to the next. The CDN cache key accounts for this
- **`/api/v1/payment-methods` is identical for every visitor and fully cacheable, and cache it briefly** for the reason `/languages` gets the same advice. It is what tells you a way of paying has stopped being offered, so a long cache delays a change you are relying on. Nothing about the visitor varies it
- **Nothing on the member account or order endpoints is cacheable by anything, ever.** They all answer `Cache-Control: no-store`, and `/api/v1/me` is the dangerous one: it is personal, it looks harmless, and a shared cache holding it hands one customer's name and address to the next visitor

The country header only exists once traffic flows through the CDN. Before DNS cutover, and in local development, detection falls back to USD. That is expected, not a bug.

## 9. Not built yet

Nothing here has a shape yet, and we would rather agree them with you before writing them than after.

### Paying, designed 25 August 2026, built, redirected on 29 August 2026, and given a second road the same day

**The endpoints are documented in section 2** and this stays as the design and the reasoning behind them. **The last two items in section 10 are still yours and still take real-world time.**

**There are two roads from 29 August 2026, and the customer picks between them by pressing a button.** The card button sends the browser to a Stripe hosted page, which is the reversal below and stands. The wallet button draws Apple Pay and Google Pay in the Express Checkout Element on your own reading page, confirming a client secret we mint. **They are two payment methods rather than one with a flag**, because a hosted Session is an address you send a browser to and an element is an iframe you mount, and no Stripe parameter reconciles them. Ours is `docs/adr/0003-the-wallet-keeps-its-own-payment-intent.md`, which amends the ADR below rather than reversing it.

**Checkout happens on a Stripe hosted page for the card, since 29 August 2026.** This section said the opposite from 25 August, and the reversal is yours to own and ours to implement: a panel cannot be laid out around a control whose height depends on whether the visitor's device has a card in its wallet, so one button of a known size beat a wallet sheet that saves a tap. The ADRs are `docs/adr/0002-checkout-happens-on-stripes-page.md` in your repo and `docs/adr/0002-the-redirect-case-comes-back.md` in ours.

**What was true about Stripe when this section argued the other way is still true**: no parameter pre-selects a wallet on a hosted page. What changed is which cost the design would rather pay.

- **Card**: `stripe`, whatever the hosted Checkout Session offers, which is Stripe's problem rather than an element you mount
- **Wallets**: `stripe_wallet`, Apple Pay and Google Pay in the Express Checkout Element on your page, confirming a client secret. **Added 29 August 2026**, at the client's request, and it is the reason `emailRequired: true` is a requirement again
- ~~**Redeem a code**: a payment method, arriving with gifting, and it answers `nothing_to_pay` rather than a redirect~~ **Withdrawn 2 September 2026, before it ever had a caller.** There are two roads and there will not be a third. Gifting is the section below

**The sequence, and the order of it matters.** Nothing exists on our side until the customer commits to buying:

1. Customer presses your buy button. **No order and no payment exist yet**
2. You call `POST /api/v1/orders`. **This endpoint does not change**, and since 29 August you may call it **with no name and no email**, because Stripe's page collects the email and collects it after the order exists
3. You call `POST /api/v1/orders/{pay_token}/pay` and get back what to do next. **The token comes back from step 2**, and it is the token rather than the id because ids here are sequential integers
4. On `redirect`, you send the browser to `redirect_url`. On `client_secret`, you confirm it in the element on your own page. On `nothing_to_pay`, you go straight to your confirmation
5. Stripe returns the customer to an address **we built**, never one you sent. You may send a return path fragment so a cancelled checkout lands where it started
6. Your confirmation screen calls `POST /api/v1/orders/status` with the pay token, and polls it, because the webhook that settles the order may not have arrived yet

**`payment_url` was replaced**, because there are genuinely different answers and a nullable string cannot carry them. `/pay` returns an object naming which it is:

```json
{ "type": "redirect", "redirect_url": "https://checkout.stripe.com/c/pay/cs_..." }
{ "type": "client_secret", "client_secret": "pi_..._secret_..." }
{ "type": "nothing_to_pay" }
```

**That a nullable string could not carry these is the whole point**: `redirect` was published, withdrawn, and brought back inside five days, and the shape absorbed all of it without a breaking change.

`nothing_to_pay` means the order is already settled and you should go straight to your confirmation. ~~It is what a gift code redemption returns, and~~ **struck 2 September 2026:** it is what a hand-settled order returns, and that is the whole of its caller list.

**Calling `/pay` twice on a pending order is safe** and gives you a fresh answer. Orders do not expire and a Checkout Session expires after 24 hours, so calling again is exactly how a customer who abandoned a checkout resumes it.

**One requirement, not a suggestion:**

- **Register `theworldtarot.com` as a Stripe payment method domain**, in test and live. Apple Pay does not render on an unregistered domain, hosted page or not, and it fails by not appearing rather than by erroring. **With the element mounted on your own origin this stopped being belt and braces and became mandatory**, because a domain nobody registered and a device with no wallet are indistinguishable from the page

~~**`emailRequired: true` on the Express Checkout Element was a requirement here and is gone with the element.**~~ **Wrong from 29 August 2026, and this is the sentence the buyer's identity depends on.** The element is back for the wallet button, alongside the hosted page for the card, and **`emailRequired: true` is a requirement again.**

A wallet PaymentIntent has no Checkout Session, so there is no `customer_details` for us to read. What we read instead is the intent's own charge, `latest_charge.billing_details`, which carries the email and the name **the wallet collected because that flag was set**. Without it a wallet payment arrives here belonging to nobody, and an order that cannot be attached to a buyer is left pending on a customer who has already been charged. The card path is unaffected: it is still the hosted page, and Stripe's page still collects the email itself.

`docs/adr/0003-the-wallet-keeps-its-own-payment-intent.md` on our side is the record.

**And two things that are gone:** the **24 hour rush** was dropped by the client on 25 August, so remove it from the reading page if you have built it. **There are no refunds.**

### Gifting, designed 2 September 2026, and it replaces the gift codes section of 25 August

~~**Gift codes, designed 25 August 2026.** A code names a reading rather than an amount, so it is worth the right price in every currency. The recipient enters it as a **payment method** on that reading's page, alongside the wallets and the card, and pays nothing. The buyer's side is a recipient email and a message captured on the order line, the way `question` already is.~~

**Replaced wholesale on 2 September 2026, rather than struck line by line, because no sentence in it survived.** The first half is still true and the second half never becomes true. Kept above so that the next person to propose redemption as a payment method finds out it was already proposed and already answered. Ours is `docs/adr/0004-a-reading-is-a-row-of-its-own.md`, yours is `docs/adr/0003-redemption-is-a-page-of-its-own.md`.

**What survives:** a code names a reading rather than an amount, so it is worth the right price in every currency, and it never expires. The buyer's side is a recipient email, a message and a signature captured on the order line, the way `question` already is.

**What is withdrawn, and it is the whole mechanism:**

- **Redemption is not a payment method.** `gift_code` never joins `/payment-methods`, and `/pay` never answers for a gift. A redemption collects nothing, because the buyer paid months earlier, so there is no payment for a method to make. **Every sentence in this document that promised otherwise is struck and dated where it stood**, six of them, in sections 2 and 9
- **Redemption does not happen on the reading's page.** It is a page of its own, `/redeem/`, one page for every reading, reached from a link in the recipient's mail
- **A redemption never calls `POST /api/v1/orders/status`.** That endpoint reports a payment, and the confirmation the recipient sees is rendered from the redemption's own answer

**The shapes are in section 2**, with the built things, each marked as not live yet: the `gift` object on an order line, `is_giftable` on `/products`, and the two endpoints under "Gifts".

**The flow, end to end:**

1. A buyer on a giftable reading's page fills in the recipient's address, a signature and an optional message, and buys the reading exactly as they buy one for themselves. **The order and the payment are unchanged**, and a basket may hold a gift and a self-purchase together
2. The payment settles. **The code is minted at settlement and never at placement**, so an abandoned checkout mints nothing. Two mails go out: the recipient's, carrying the link and the code, and the buyer's ordinary receipt, carrying the address it went to. **The buyer never receives the code**
   - **The link we send is `{your site}/redeem/?code=XXXX-XXXX-XXXX`**, built 3 September 2026, and the same characters are printed underneath it for somebody typing them in. The path is configurable on our side, so tell us if it is not `/redeem/`, and the trailing slash is part of the address for the reason the two password pages carry one
   - **The code is twelve characters of `0-9A-Z` less `I`, `L`, `O` and `U`**, printed in three groups of four. Those four glyphs never appear in one, which is why the normalising in section 2 can read `I` and `L` as `1` and `O` as `0` without ambiguity
3. Nothing is on anybody's work queue yet. A gift that is never redeemed asks for no reading, and there is nothing to write
4. The recipient opens the link. `/redeem/` **looks the code up without spending it**, because email scanners and link prefetchers follow links, and a gift spent by a scanner is unrecoverable
5. They ask their question and give an address. **That is the moment the reading exists**, the moment the delivery window starts, and the moment this side learns anybody is waiting

**Gifts do not expire**, decided in `docs/adr/0005-gift-codes-do-not-expire.md`. There is no expired state to build and no `expires_at` to read. **There are no refunds**, which is the other half of why a code spent by accident cannot be put right.

### Still to design

- **Reading an order back, and order history.** There is no `GET /orders` or `GET /orders/{id}`. **The confirmation half of this is answered**: it needed one, and `POST /api/v1/orders/status` in section 2 is it, added 29 August 2026. Tell us if an account area needs the orders themselves
- FAQ and legal content. **Newsletter signup has moved out of this list**, built 15 August 2026 and documented in section 2
- Editable page copy, delivered as named blocks per page

**`GET /api/v1/user` has been removed**, as this document warned it would be. `GET /api/v1/me` replaces it and is part of the contract.

## 10. What we need from you

1. **Your exact origins**, all of them: production, the preview URL pattern, and the local dev origin including port. Without these your requests are blocked by CORS and cookie auth cannot work at all
2. **Cookie auth or token auth for the website.** Cookie is our assumption
3. **Confirmation that your language switcher reads `/api/v1/languages` at runtime**, since that is what makes taking a language down actually work
4. **Confirmation of the shapes above**, or a counter-proposal
5. **Confirmation that your player handles an expiring HLS URL**, renewing rather than holding one. Thirty minutes covers a single film comfortably and is not forever
6. **Whether a card ever needs an address of its own.** Cards are identified by number today, on the assumption that the Library opens them in a pop-up. If any card is ever linkable, shareable or indexed on its own URL, adding a readable identifier is cheap now and awkward once you have built against ids
7. ~~**The paths of your two password pages**~~ **Answered 28 August 2026, and both pages exist.** They are `/reset-password/` and `/set-password/`, two pages with different words, and we link to them as `{your site}/set-password/?token=...&email=...`.
   - **The trailing slash is part of the address.** The website is a static export built with `trailingSlash`, so the slashless form is a redirect, and a redirect on these two links is not merely untidy: they carry a single-use token that a scanner following the hop can spend before anybody reads the mail
   - Both pages read `token` and `email` from the query string and post them back to us unchanged, along with the new password
8. ~~**Whether you want an order history or an order lookup**, and whether your confirmation screen needs to re-read the order after placing it~~ **Half answered 29 August 2026.** The confirmation screen needs to read the payment back, and `POST /api/v1/orders/status` does that. **An order history is still open** and still does not exist
9. **Confirmation you are not caching `/api/v1/me`**, in a service worker, a persisted store, or anything at the edge

**Added 25 August 2026, for checkout. The first two have lead time and nothing on our side unblocks them:**

10. **Register `theworldtarot.com` as a Stripe payment method domain**, in both test and live mode. Apple Pay will not render until you do, and it fails by simply not appearing rather than by erroring. **Mandatory rather than belt and braces from 29 August 2026**, because the element is now mounted on our own origin: an unregistered domain and a device with no wallet look identical from the page, which means this failing looks exactly like it working
11. **Confirm you will pass `emailRequired: true`** to the Express Checkout Element. **Un-struck 29 August 2026, later the same day it was struck.** It was moot for the hours in which there was no element; `stripe_wallet` brings one back for the wallet button, and this flag is now the only thing that puts a buyer's email on the charge we read at settlement. Without it a wallet payment settles nobody
12. ~~**An email field on the card path**~~ **Still moot from 29 August 2026.** The card path is the hosted page and Stripe's own page collects the email, so this one does not come back with item 11
13. ~~**Confirmation you have the design without the 24 hour rush.** The version we have shows it as a radio under the price and as a bullet in the feature list, and it was dropped on 25 August~~ **Answered 1 September 2026: they have it, and the rush is out of the design for good.** The website's reading page renders one delivery and states it. Nothing on either side should be built against `rush-24h` again
14. ~~**Whether you want the Payment Element or a hosted redirect for the card path**~~ **Answered 29 August 2026: the hosted page.** ~~For every path rather than only the card.~~ **That half was corrected later the same day**, when you asked for the wallet buttons back: the hosted page is the card path and nothing else, and the wallet path is the Express Checkout Element on your own page. Both roads are live and the customer picks between them, which is what item 11 above came back for. Recorded in `docs/adr/0002-the-redirect-case-comes-back.md` and `docs/adr/0003-the-wallet-keeps-its-own-payment-intent.md`

**Added 29 August 2026, with the redirect:**

15. **The exact origin we build return addresses from**, per environment. It is configured here and you never send it, so a wrong one sends your customers to somebody else's site after they have paid
16. **The return path fragments you want to send**, if any, so a cancelled checkout lands on the reading page it started from rather than the homepage

One question from us: your application can read the visitor's country directly at the edge. If you would rather detect currency yourself and always send `?currency=`, we can drop `detected` and the caching complication with it.
