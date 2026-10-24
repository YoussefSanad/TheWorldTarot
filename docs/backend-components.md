# The backend, component by component

A walkthrough of every part of `theworldtarot-backend` as it stands on 3 September 2026, written to be read top to bottom by somebody new to the codebase.

**Brought up to date with the gifting epic on 3 September 2026**, B1 to B7 of it, which had been landing since 2 September while this file still said gifting was not started. What is described below as gifting is committed on `feat/gift-a-reading` and is **not deployed anywhere**. B8 to B10 are not built and are named where they belong.

`CLAUDE.md` holds the standing rules and the reasoning behind the decisions. `docs/CHECKPOINT.md` holds what is true today. This file holds the map: what each component is, what it talks to, and where it sits in the flow.

---

## 0. Shape of the whole thing

A Laravel 13 application on PHP 8.4 with Postgres, doing three jobs at once:

1. **A versioned JSON API** under `/api/v1`, consumed by a separate Next.js frontend owned by a frontend partner
2. **A Filament 4 admin panel** at `/admin`, the only rendered UI in the project, used by the client to edit content, watch orders and publish languages
3. **A set of queued workers and scheduled sweeps** that talk to Bunny.net, Stripe, Resend and Mailchimp out of band

Nothing customer-facing is rendered here. `routes/web.php` serves one placeholder view and nothing else.

```
Browser (Next.js on Cloudflare Pages)
        |  cookie or bearer token
        v
   /api/v1/*  ------------------> Controllers -> Actions -> Models -> Postgres
        ^                              |
        |                              +-> Jobs (managed queue) -> Bunny / Mailchimp / Stripe
   webhooks                            |
   Stripe, Bunny                       +-> Mail (Resend)

   /admin  --------------------> Filament resources -> the same Models and Actions
```

---

## 1. Entry points and bootstrap

### `bootstrap/app.php`

The whole framework configuration, in one file:

- **Routing**: `routes/web.php`, `routes/api.php`, `routes/console.php`, plus a `/up` health endpoint the platform polls
- **Trusted proxies** from `App\Support\TrustedProxies`. Load bearing: without an explicit list, Laravel trusts `'*'` on Laravel Cloud, which makes Symfony read the leftmost `X-Forwarded-For` entry, and that value is client supplied. Anything keyed on an IP address, rate limiters included, is only meaningful because this is configured
- **`statefulApi()`**, which puts Sanctum's cookie handling in front of the API so the SPA can authenticate with a session cookie scoped to `.theworldtarot.com`
- **`encryptCookies(except: ['currency'])`**, because the currency override cookie is written by the frontend in JavaScript and read here, so it cannot be encrypted at one end only
- **`ResolveRequestContext`** appended to every API request
- **JSON exception rendering** for anything matching `api/*`

### `app/Providers/AppServiceProvider.php`

The container wiring, and the place to look when something resolves to a class you did not expect:

- `FilmSource` is bound **contextually**, not globally. `CardController` gets whatever `config/reveal.php` names, `CardVideoController` gets whatever `config/viewing-room.php` names. There is deliberately no default binding, so a bare `app(FilmSource::class)` is an error rather than a silent guess
- `PaymentGateway` resolves through `PaymentMethods`, which reads `config/payments.php`
- `StripeClient` is a singleton configured with a **10 second timeout, 5 to connect, and zero network retries**, because creating a PaymentIntent happens inside a customer's request and cannot be queued
- The `enter-viewing-room` gate, one line: a customer may enter if `viewing_room_access_at` is set
- `TranslatableContentObserver` is registered against every model in `config/translatable.php`
- The shared password rule used by registration, reset and set-password

### `app/Providers/Filament/AdminPanelProvider.php`

The `admin` panel: path `/admin`, its own `admin` auth guard, amber theme, auto-discovered resources, and the `SpatieTranslatablePlugin` that gives every translatable resource its language tabs.

---

## 2. The request context middleware

`app/Http/Middleware/ResolveRequestContext.php` runs on every API request and resolves two things that almost everything downstream depends on:

- **Locale**, from the `{locale}` route segment, or the fallback when a route has none. Resolved through `LocaleResolver`, which asks the `locales` table whether that language is servable to this caller. A language that is not servable throws a 404 rather than falling back to English, because a path segment is an address and not a hint. Admins can see `preview` languages, the public cannot
- **Currency**, from `?currency=`, then the `currency` cookie, then Cloudflare's `CF-IPCountry` header, then the `USD` default. An explicit currency is honoured exactly and never re-detected

Both are put into the container as an immutable `App\Support\RequestContext`, which controllers and resources type-hint.

---

## 3. Routes

`routes/api.php` is the contract. Everything is under `/api/v1`; there is no unversioned route.

**Flat routes, no language segment**, because they are not content:

| Route | Throttle | Notes |
|---|---|---|
| `GET /languages` | none | The list of live languages. Cannot sit behind a language, since it is what tells the frontend which exist |
| `GET /currencies` | none | Available currencies plus the detected one |
| `POST /register` | 10/min | |
| `POST /login` | 20/min | A second, tighter per address and per email limit lives in `LoginRequest` |
| `POST /logout` | auth | |
| `GET /me` | 60/min, auth | |
| `POST /forgot-password` | 6/min | Plus a 60 second per account limit in the broker |
| `POST /reset-password` | 10/min | |
| `POST /set-password` | 10/min | Only works on an account that has never had a password |
| `POST /orders` | 20/min | Open to guests. Placing an order sends no email, which is what keeps the abuse case small |
| `POST /orders/{payToken}/pay` | 30/min | Addressed by token, not id, because ids are sequential |
| `POST /newsletter` | 6/min | |
| `POST /webhooks/bunny` | 60/min | |
| `POST /webhooks/stripe` | 120/min | |

**Content routes, under `/{locale}`:**

| Route | Notes |
|---|---|
| `GET /{locale}/products` | Readings and the pass |
| `GET /{locale}/products/{key}` | `key` is the `ProductKey` enum value and the URL segment |
| `GET /{locale}/cards` | The Library |
| `GET /{locale}/cards/draw` | The homepage reveal, 30/min, public, returns a signed film |
| `GET /{locale}/cards/{card}` | Digit-constrained, so it cannot swallow `draw` |
| `GET /{locale}/cards/{card}/video` | The Viewing Room, 30/min, `auth:sanctum` plus the pass gate |
| `POST /{locale}/gifts/lookup` | What a gift code is worth, 10/min, and it does not spend it |
| `POST /{locale}/gifts/redeem` | Spends it, 20/min, under a row lock |

**The two gift routes are POSTs for a read**, because the code is a bearer credential and a path segment lands in every access log between the browser and here, which is the same argument `POST /orders/status` makes for the pay token. **They sit behind the language segment** and orders and payment do not, because these two genuinely answer content: the lookup carries the reading's copy, in the language the visitor opened the link in.

**The third argument to every `throttle` is a key prefix and is load bearing.** Without it, Laravel keys a guest's limiter on domain and IP alone, so every throttled route in the file shares one counter and the strictest limit applies to their sum. This was a real defect found on staging: six orders refused the next password reset.

`routes/console.php` holds the two scheduled sweeps, described in section 10.

---

## 4. Controllers

Thin by policy. Anything that is a rule lives in an action or a service.

| Controller | What it does |
|---|---|
| `LanguageController` | Lists live locales |
| `CurrencyController` | Lists currencies plus the detected one from `RequestContext` |
| `ProductController` | `index` lists available readings and the pass; `show` resolves a `ProductKey` and 404s on anything unknown |
| `CardController` | `index` the Library, `show` one card, `draw` picks a random card that has a playable film and returns a signed URL with `Cache-Control: no-store` |
| `CardVideoController` | The Viewing Room. `Gate::authorize('enter-viewing-room')`, then mints the same kind of signed URL |
| `RegisterController` | Creates a customer, then issues credentials |
| `SessionController` | Login and logout |
| `MeController` | The signed-in customer |
| `ForgotPasswordController` | Always answers the same message, whether or not the account exists |
| `ResetPasswordController` | Resets and revokes every existing token |
| `SetPasswordController` | Claims an account created by a purchase. Refuses if a password already exists |
| `OrderController` | Places an order, creating a customer for an unrecognised email if nobody is signed in |
| `PayOrderController` | Looks an order up by `pay_token`, refuses a signed-in customer who is not its owner, and returns a payment instruction |
| `NewsletterController` | Honeypot check, then queues the subscription and answers 202 |
| `BunnyWebhookController` | Reads a video id out of the payload and re-asks Bunny. Always answers 200 |
| `StripeWebhookController` | Verifies the signature against the raw body first, then dispatches |
| `GiftController` | `lookup` resolves a typed code without spending it; `redeem` spends it. Both normalise through `Gift::withTypedCode`, both answer `Cache-Control: no-store`, and both read the product **past `is_published`**, which is the one place in this system that does |

**`Concerns/IssuesCredentials`** is shared by register and login: if the request names a `device_name`, it mints a Sanctum personal access token; otherwise it logs into the `web` guard and regenerates the session. An origin that cannot hold a session and did not name a device gets a validation error explaining that.

### Form Requests

`app/Http/Requests/Api/V1/` holds ten. Backend validation is authoritative; the frontend's Zod schemas are a convenience, never a substitute. `LoginRequest` carries the per address and per email rate limit that actually protects a single account from a slow guess.

### API Resources

`app/Http/Resources/Api/V1/` holds seventeen. **Nothing ever returns a model directly**, so column names never leak into the contract and a migration is not automatically a breaking change. All money is integer cents in transit; formatting is the frontend's job. `RevealCardResource` and `CardVideoResource` add a `video` object carrying the signed URL and its expiry.

---

## 5. Models and the database

| Model | Table | Notes |
|---|---|---|
| `Admin` | `admins` | Filament login, separate guard, implements `FilamentUser` |
| `Customer` | `customers` | Sanctum tokens, nullable password for accounts created by a purchase, `viewing_room_access_at` timestamp for the pass |
| `Locale` | `locales` | `code`, `english_name`, `native_name`, `status`, `sort_order` |
| `Product` | `products` | One table for everything sellable, soft deleting |
| `Card` | `cards` | The 22 Major Arcana and anything added later, soft deleting |
| `Order` | `orders` | |
| `OrderLine` | `order_lines` | |
| `Reading` | `readings` | Somebody asked a question and is waiting. Exists from the asking, never before |
| `Gift` | `gifts` | A reading bought for somebody who has not asked yet. A row here is a liability, not a purchase |

### Products

```
key                 string + ProductKey cast, unique, never translated, and the URL segment
type                reading | pass | addon
fulfillment         manual_pdf | access_grant, internal, never exposed
allows_question     boolean
name                jsonb, translatable
short_description   jsonb, translatable
long_description    jsonb, translatable
price_usd           integer minor units, NOT NULL
price_eur           integer minor units, NOT NULL
price_gbp           integer minor units, NOT NULL
is_published        developer switch, not in the panel
sort_order
defaults_seeded_at
```

Three price columns rather than a related table, so that **nothing can exist without a price in every currency** and the database enforces it with no trigger. The accepted cost is that a fourth currency is a migration. Prices are fixed per currency and set by hand; there is no base price and no exchange rate anywhere in the system.

Products are **seeded, never created in the panel**. The client edits copy, translations and prices.

### Cards

```
number                    the deck order, The Fool is 0. Unique among rows where deleted_at is null
name / short / long       jsonb, translatable
image_path                path only, nullable. The disk is configuration
is_published              developer switch
bunny_video_id            only ever a video that is ready to watch
bunny_incoming_video_id   one being prepared, waiting to take over
video_status              pending | processing | ready | failed
video_master_path         the master, on the private uploads disk
video_uploaded_at
```

**Two video columns, not one.** Replacing a video must never take a card out of the Viewing Room while the replacement transcodes, and a failed replacement leaves the old film playing.

`Card::booted()` carries the ingestion trigger. On save, a changed `video_master_path` either forgets the video entirely or forgets just the superseded master, flips the status to `pending`, and after commit dispatches `SendCardVideoToBunny`. The discarded artefacts go to `ForgetCardVideo`.

### Orders

```
orders
  customer_id                restrictOnDelete
  status                     pending | paid | cancelled
  currency                   3 chars
  total_amount               unsigned integer minor units
  charged_amount             nullable, what Stripe actually took
  stripe_payment_intent_id   nullable, indexed
  pay_token                  unique, 48 random chars, minted on create
  paid_at / fulfilled_at

order_lines
  order_id                   cascadeOnDelete
  product_id                 restrictOnDelete, read withTrashed
  product_key                captured at purchase
  unit_amount                captured at purchase, never re-read from the product
  quantity
  question                   nullable
```

Prices are tax inclusive, and Stripe Tax is off, so `charged_amount` always equals `total_amount`.

`order_lines.question` is what the buyer typed at checkout and stopped being the question of record when gifting landed. It is null on a gift line, because the person who will ask has not seen the present yet.

### Readings and gifts

Added by the gifting epic, `docs/adr/0004-a-reading-is-a-row-of-its-own.md`. **A gift bought in September and redeemed in December pulls "the money arrived" and "somebody asked" three months apart**, and one row cannot hold "paid, and not yet asked".

```
readings
  order_line_id              restrictOnDelete, deliberately not unique
  gift_id                    nullable, restrictOnDelete. Null is a line somebody bought for themselves
  question                   nullable, the question of record
  querent_email              captured, never joined: a gift's querent gets no customers row
  querent_name               nullable
  asked_at                   when the clock starts
  delivered_at               nullable

gifts
  order_line_id              unique, restrictOnDelete. One line is one thing bought once
  code                       nullable, unique. Null until settlement mints one
  recipient_email            where the code is sent, and the one address nothing can check
  signature                  who it is from, and not a name
  message                    nullable, the only optional one of the four
  locale                     the language the recipient's mail is written in
  redeemed_at                nullable. Null is an obligation still on the books
```

Three things about these are load bearing:

- **`gifts.code` is null between placement and settlement.** A code minted when the buyer typed the address would be a live bearer credential behind every abandoned checkout. A codeless row is inert: nothing can look it up and nothing can redeem it
- **Due is computed at read time**, `asked_at` plus `ProductKey::deliveryWindow()`, and there is deliberately no `due_at` column. A stored one is a second writer for a value that already has one
- **There is deliberately no `expires_at`.** `docs/adr/0005-gift-codes-do-not-expire.md` accepts the indefinite liability, and a column nothing writes is a column the next person assumes is enforced somewhere

`products.is_giftable` joins `allows_question` on the catalogue. The two are independent, and `one-card` is what proves it: it takes a question and cannot be gifted.

### Other tables

`personal_access_tokens` (Sanctum), `sessions`, `password_reset_tokens`, `cache` and `cache_locks`, `jobs`, `job_batches`, `failed_jobs`.

---

## 6. The rule engines

Two small declarative engines carry the rules that would otherwise be scattered across queries, panels and controllers, each of which used to disagree with the others.

### `app/Availability`, is this on the website?

`Availability::of(Condition, Condition, ...)` composes conditions that can each do three things: **constrain a query**, **answer about one record**, and **say what is missing in words**. That is what stops the list query and the panel's explanation drifting apart.

- `Product::availability()`: finished in the original language, finished in the language asked for, priced above zero in every currency, published, not deleted
- `Card` uses the same shape, with "has a picture" where a product has "has a price"
- `FilmSource::whatItCanPlay()` returns a `Condition`, so "has a playable film" composes into the same machinery

`php artisan availability:check` proves the query, the predicate and the panel all agree about every row.

### `app/Purchasability`, may this be bought?

`Purchasability::declared()` is the checkout guard, run by `PlaceOrder` against a `Basket` of `Line`s:

- sold here (the product exists)
- published
- priced in the currency being charged
- priced at something above zero
- a question only where the product takes one
- one access grant per line
- one access grant per basket
- not already held

Each `Rule` produces a `Refusal` carrying both a field, customer-facing words, and an internal fault message.

---

## 7. Actions

Small invokable classes holding the transitions that matter.

| Action | What it does |
|---|---|
| `PlaceOrder` | Builds a basket, runs the purchasability guard, captures prices at purchase time, and creates the order and its lines in a transaction |
| `MarkOrderPaid` | **The single funnel every payment path goes through.** Takes a row lock, takes a required `PaidBy` (intent id, amount and ~~intent id and amount~~ the paying customer's id, changed 24 October 2026) and answers a `Settlement`: only a `pending` order is settled, in one save that writes `status`, `paid_at`, `charged_amount` and the intent id, plus `customer_id` when the order has no owner (an existing owner is never changed; ~~`OrderBelongsToNobody` was thrown for an unowned order~~ deleted 24 October 2026). A paid order answers `AlreadyPaid` for the same intent and `PaidTwice` for a different one, writing nothing (~~answered a bool, 23 October 2026~~). Settling also grants the Viewing Room pass for any `access_grant` line, **mints a code for every gift on the order**, asks `AskForReading` about the lines that are waiting for a person, then asks the order itself whether everything it owes has gone. Then it sends the receipt, a set-password link if the order grants access and the account has no password, and a `GiftToRedeem` to each recipient |
| `AskForReading` | **The one moment a reading starts existing**, and the note to the client that follows it. Two roads reach it: settlement for a line nobody gifted, and `forGift` for a line somebody did. One message per asking, not per reading |
| `RedeemGift` | Spends a code. Re-reads the gift **under `lockForUpdate` inside the transaction**, refuses one already spent with a 409, stamps `redeemed_at`, and calls `AskForReading::forGift` in the same transaction. The mail waits for the commit |
| `DeliverReading` | Stamps `readings.delivered_at` once, then asks `MarkOrderFulfilled` whether the order it belongs to is now finished |
| `ReadPaymentStatus` | Asks Stripe what became of an order's PaymentIntent, for the confirmation screen to poll |
| `MarkOrderFulfilled` | Stamps `fulfilled_at` once, idempotently |
| `RecordStripePayment` | Refuses anything not succeeded, refuses a payment naming an unknown order, refuses one whose **amount and currency do not match the order**, ~~then records `charged_amount` and the intent id and calls `MarkOrderPaid`~~ then calls `MarkOrderPaid`, which records both in the settling save (changed 23 October 2026). A second distinct payment for a paid order is `Recording::PaidTwice`, logged at error and counted a fault, naming the owner and the duplicate's buyer. It resolves the buyer and hands the id to `MarkOrderPaid` through `PaidBy`, writing nothing to the order itself (changed 24 October 2026) |
| `SyncCardVideo` | Asks Bunny for a video's real status and promotes, fails or updates the card accordingly |

The row lock plus the "must be pending" check in `MarkOrderPaid` is what makes a duplicated webhook, a retried job and a panel click all safe together. `RedeemGift` is the same shape for the same reason, and it is the one place where getting it wrong means a free reading: `tests/Feature/RedeemAGiftRaceTest.php` forks two real processes at one code to prove the lock is there, because nothing else can.

**"Fulfilled" is asked rather than remembered.** `Order::everythingItOwesHasGone()` is the one definition: every reading delivered, every gift redeemed and delivered. `MarkOrderFulfilled` records it and deliberately does not decide it, and both the payment path and the panel's `Mark delivered` ask the same question.

---

## 8. Services and the seams

**Every third party sits behind an interface this system defines, named in config.** The interface describes what we need, in our words. If it mentions a Bunny library id or a Stripe payment intent, the seam is in the wrong place.

| Need | Interface | Implementation | Config |
|---|---|---|---|
| What the homepage reveal plays | `FilmSource` | `FullFilmRevealVideo` | `config/reveal.php` |
| What a pass buys | `FilmSource` | `ViewingRoomFilm` | `config/viewing-room.php` |
| Taking money for an order | `PaymentGateway` | `StripeCheckout` (~~`ManualSettlement`~~ retired 23 October 2026) | `config/payments.php` |
| Subscribing to the newsletter | `NewsletterList` | `MailchimpAudience`, `LoggedNewsletterList` | `config/newsletter.php` |

Where the framework already provides a seam, nothing is wrapped: Resend is a mail driver, the media bucket is a filesystem disk, managed queues are a queue connection.

### `Services/Bunny/BunnyStream`

The one service not yet behind an interface, deliberately, because it holds the signing logic and retrofitting it is real risk against modest gain. It offers `createVideo`, `uploadVideo`, `fetchStatus`, `deleteVideo` and `playbackUrl`.

**`playbackUrl` is the security-critical method in the project.** Three things about the signature are load bearing and each was learned expensively:

- The token covers the video's **whole directory**, not its manifest, because HLS resolves renditions and segments relative to `playlist.m3u8`
- The viewer's IP is **not** part of the signature and cannot be. The API is behind dual-stack Cloudflare while the video zone is IPv4 only, so an IPv6 visitor signs one address and arrives from another. `ZoneSecurityIncludeHashRemoteIP` is off on the zone to match, and the two must move together
- A playback URL cannot be opened in a browser tab, since the zone refuses requests with no referrer

What limits a copied link is its life: 30 minutes, from `services.bunny.playback_ttl` and `reveal_ttl`.

### `Services/Film`, `Reveal`, `ViewingRoom`

`LivingTarotFilm` is the abstract implementation of `FilmSource`. `FullFilmRevealVideo` and `ViewingRoomFilm` differ only in which config key names their expiry. One implementation, two named adapters, so that changing what anybody gets for free can never reach into what somebody paid for. Recorded as `docs/adr/0001`.

### `Services/Payments`

- `PaymentMethods` is the registry. `config/payments.php` maps `stripe` to `StripeCheckout` and the default method is `stripe` (~~`manual` mapped to `ManualSettlement`, retired 23 October 2026~~: `/pay` with `manual`, or with a default that is not offered, answers 422 on `method`), and **the Stripe entry filters itself out when the secrets are absent**, so an environment without keys cannot half-offer it
- `StripeCheckout` refuses anything not pending, refuses an order whose total disagrees with its lines, creates a PaymentIntent carrying `order_id` in metadata, stores the intent id only while the row is still pending (an order settled mid-call is refused, and plan step 19 removes the write) and returns a client secret
- ~~`ManualSettlement` returns "nothing to pay", which is how an order is settled by hand in the panel~~ **Retired 23 October 2026.** Stripe, by webhook and sweep, is the only road to paid

`PaymentInstruction` is the return value either way: a small value object that is either `nothing_to_pay` or a `client_secret`.

### `Services/Newsletter`

`MailchimpAudience` treats "Member Exists" as success, and classifies a 4xx that is not a 429 as a **permanent** failure by implementing `PermanentFailure`, which is how the job knows not to retry forever. `LoggedNewsletterList` is the local driver.

---

## 9. Jobs

Managed queues on Laravel Cloud, database driver locally. Delivery is **at least once**, delays cannot exceed 15 minutes, and a job slower than the visibility timeout will be delivered again while still running. Every job here is written to tolerate that.

| Job | Tries | What it does |
|---|---|---|
| `SendCardVideoToBunny` | 3, 600s timeout | Creates the video at Bunny **before** sending bytes and stores the id immediately, so a redelivery does not produce a duplicate film. Streams the master from the uploads disk. Marks the card failed if the master is missing |
| `ForgetCardVideo` | 3 | Deletes superseded videos at Bunny and the old master from the bucket |
| `SettleStripePayment` | unlimited, 6 hour ceiling | Runs `RecordStripePayment`. Backs off 30s, 120s, 900s |
| `SubscribeToNewsletter` | unlimited, 6 hour ceiling | Fails immediately on a `PermanentFailure`, retries otherwise |

Anything a job needs to read goes to a bucket first: managed workers run in a separate container from the request that uploaded the file.

---

## 10. Scheduled work

`routes/console.php`:

- **`cards:reconcile-videos`, daily.** The safety net under Bunny's transcode callback. Makes no request at all unless something is genuinely in flight
- **`orders:reconcile`, every five minutes.** The safety net under the Stripe webhook, paired with the command's own 15 minute window, so a lost notification costs about twenty minutes rather than a customer who paid and received nothing

The platform invokes `schedule:run` every five minutes, measured on staging on 26 August 2026.

### Console commands

| Command | Purpose |
|---|---|
| `availability:check` | Prove the query, the predicate and the panel agree about every card and product |
| `mail:check {email}` | Send a real message through the configured transport |
| `media:check` | Write, read back and delete a file on every disk |
| `app:create-admin` | Create or update a Filament admin |
| `cards:reconcile-videos` | Ask Bunny about anything still being prepared |
| `orders:reconcile` | Ask Stripe about any order still waiting on a payment |

---

## 11. Mail

`QueuedMail` is the base: every message is queued, and each names a sender from the `Sender` enum, which maps `support`, `orders` and `fulfillment` to addresses in `config/mail.php`.

| Mailable | When |
|---|---|
| `OrderPaid` | The receipt, on payment |
| `SetPasswordLink` | On payment of an order that grants Viewing Room access, when the account has never had a password |
| `ReadingToWrite` | To the client, whenever somebody asks: on settlement for a line nobody gifted, and on redemption for a line somebody did |
| `GiftToRedeem` | To the recipient, on payment, carrying the code and the `/redeem/` link. Rendered in `gifts.locale`, and the buyer never receives a copy |
| `PasswordResetLink` | A reset request |
| `PaymentDisputed` | A `charge.dispute.created` webhook |
| `TransportCheck` | `mail:check` only |

Links point at the frontend, built by `App\Support\FrontendUrl` from `config/frontend.php`, so the API never renders a page the customer lands on.

---

## 12. Translation

- `spatie/laravel-translatable` for values, one **jsonb** column per translatable field
- A `locales` table for the registry. **Adding a language is a row, never a migration**
- A locale is `draft`, `preview` or `live`. `preview` is visible to admins only
- Only **cards and product copy** are database-translatable, listed in `config/translatable.php`. UI strings, marketing pages, FAQ and legal all live in the frontend repo
- Completeness is **calculated, never stored**: a record is finished in a language when every field in the model's `requiredTranslations()` has a value. `HasTranslationCompleteness` on the model and `TranslationCoverage` across all of them
- `TranslatableContentObserver` watches every save and delete, and **takes a live language back off the website the moment anything in it becomes untranslated**, logging exactly what is outstanding. A language shows everything or nothing
- `Locales` caches the registry per request and degrades to an empty collection if the table does not exist yet, so the app can boot before its first migration

---

## 13. Filament admin panel

The only rendered UI in the project. Five resources, since `docs/adr/0006-one-screen-per-question.md` split one into three:

| Resource | What the client does there |
|---|---|
| **Cards** | Full CRUD, the one place rows are created. Copy, picture, video upload, per-language tabs |
| **Products** | Edit and list only. Copy, translations and prices. Never sees `key`, `type` or `fulfillment`, and never creates or deletes |
| **Locales** | List with actions: "Let me review it" (draft to preview), "Show on the website" (to live, refused while anything is untranslated), and withdraw |
| **Orders** | **Money, and only money.** List, filter by status and fulfilment. ~~Mark as paid~~ removed 23 October 2026: the panel has no way to mark an order paid. It lost the question column, the `Mark as fulfilled` button and its navigation badge to Readings |
| **Readings** | **The work queue.** Sorted oldest promise first, with due computed at read time and coloured, a detail page carrying the whole question and the buyer's message, and `Mark delivered` per reading. Carries the navigation badge |

**Orders is money, Readings is work, and Gifts is liability.** No screen answers two of them and no number on one is derived from another's table. The Gifts screen is B9 (#75) and is not built, so an outstanding gift is visible today only through the order it hangs off.

~~**Marking an order paid in the panel runs `MarkOrderPaid`**, the same action a card payment runs, so a hand-settled order and a Stripe-settled one can never come to mean different things.~~ **Struck 23 October 2026.** There is no panel action. `MarkOrderPaid` is reached only from the Stripe webhook and the sweep.

Two shared concerns: `EditsTranslatableContent` and `ShowsTranslationProgress`.

---

## 14. Configuration

Beyond the Laravel defaults:

| File | Holds |
|---|---|
| `config/currencies.php` | The three currencies, their symbols, and the country to currency map. EU to EUR, GB to GBP, else USD |
| `config/payments.php` | The payment method registry and the default |
| `config/reveal.php` | Which `FilmSource` the homepage reveal uses |
| `config/viewing-room.php` | Which `FilmSource` the pass buys |
| `config/newsletter.php` | Which `NewsletterList` implementation is live |
| `config/translatable.php` | Which models are database-translatable |
| `config/frontend.php` | The frontend URL and its reset and set-password paths |
| `config/services.php` | Bunny, Stripe, Mailchimp, Resend credentials and timeouts |
| `config/mail.php` | The three named senders, and where internal mail lands |
| `config/filesystems.php` | `media` and `uploads` disk names. Rows store paths, never URLs |
| `config/scramble.php` | API documentation generation |

---

## 15. Testing

Pest. 1359 tests in `tests/Feature` and `tests/Unit`, 1356 passing and 3 skipped, measured 3 September 2026. The three skips are `StripeWebhookVectorTest` and are the same three they have always been. There is a separate suite in `tests/Staging`.

- **Never hit a real external service from the offline suite.** `Http::fake()`, `Queue::fake()`, `Mail::fake()`
- **Never assert an external service's format against your own implementation of it.** `BunnyStreamTest` did exactly that, the suite stayed green, and playback returned 403 for every viewer for five days. Where only the other side can confirm a format, a **vector captured from that service** is pinned, with the date it was accepted, and regenerating one means proving the new one live first
- `tests/Staging/` is the one carve-out: run by hand against the deployed environment, test-mode credentials only, off by default, never in CI. It exists to answer the one question a fake cannot, whether a customer can actually hand over a card and receive what they bought
- Two agreement tests, `AvailabilityAgreementTest` and `PurchasabilityAgreementTest`, prove the rule engines answer consistently across query, predicate and words
- **`RefreshDatabase` everywhere but one file.** `tests/Feature/RedeemAGiftRaceTest.php` uses `DatabaseTruncation`, because a transaction that never commits is invisible to a second connection and there is then nothing for a second process to race for. It forks two real processes at one gift code, and it is the only proof anywhere that `RedeemGift` holds a row lock: every other case passes with no lock at all. **Two things about it are not optional**, and both were learned by getting them wrong. `Tests\Support\TruncatesWhatAGiftTouches` names the six tables it may empty as an **allowlist**, because `locales` is written by a migration and not a seeder and truncating it would empty the language registry for the rest of the process with nothing to put it back. And it clears up in an `afterEach`, because `DatabaseTruncation` truncates on the way in and not on the way out, so its committed rows would otherwise still be there for every test after it. Eleven tests in four unrelated files went red before that existed, and none of them named the file that caused it

---

## 16. Where the state is today

Milestones 1 to 4 are complete. M5 phase A (accounts, mail, orders, fulfilment) and phase B (Stripe and the webhook) are both merged and on staging, and the card walk passed there on 26 August 2026. Production has never been walked.

Outstanding, per `docs/CHECKPOINT.md`:

- The captured Stripe webhook vector does not exist yet, so `StripeWebhookVectorTest` skips loudly
- ~~Gifting is required at launch, planned in `docs/plans/gifting-plan.md`, not started.~~ **Struck 3 September 2026.** B1 to B7 are built and committed on `feat/gift-a-reading`, and **nothing is deployed**. `docs/plans/gifting-plan.md` is superseded; the design is ADRs 0004 to 0006 and the build order is `docs/plans/gifting-build.md`. Left to build: B8 the querent's own mail (#74), B9 the gifts screen (#75), B10 the staging walk (#76)
- M6 (FAQ and legal content, Mailchimp beyond the signup), M7 (AI fulfilment) and M8 (launch) are not started
- The origin is still reachable without going through Cloudflare on its `*.laravel.cloud` hostname, and a request arriving that way is trusted one hop too far

---

## Further reading

- `CLAUDE.md`, the standing rules and the reasoning behind every settled decision
- `docs/CHECKPOINT.md`, what is true today, and the reversals table
- `.claude/api.md`, `.claude/database.md`, `.claude/translation.md`, `.claude/external-services.md`, `.claude/filament.md`, `.claude/testing.md`
- `API_CONTRACT.md`, the endpoint-by-endpoint contract with the frontend partner
- `docs/adr/0001-one-film-implementation-two-named-adapters.md`
