# Newsletter signup, plan

Drafted 14 August 2026.

## Progress

- **Trusted proxies**, the prerequisite commit from question 2. Built 15 August 2026. `App\Support\TrustedProxies`, wired in `bootstrap/app.php`, pinned by `tests/Feature/TrustedProxiesTest.php`. What it found is recorded in CLAUDE.md section 3 and is worse than what the question described
- **Step 1, the seam.** Built 15 August 2026. `App\Contracts\NewsletterList`, `App\Support\NewsletterSignup`, `App\Services\Newsletter\LoggedNewsletterList`, `config/newsletter.php`, bound in `AppServiceProvider`, proved by `tests/Feature/NewsletterListTest.php`. Suite green at 581
- **Step 2, Mailchimp. Complete.** Built 15 August 2026. `MailchimpAudience`, `MailchimpRequestFailed` and `PermanentMailchimpFailure`, the `mailchimp` block in `config/services.php`, `config/newsletter.php` switched to a driver, 11 tests. **Vector captured the same day** against the live audience and pinned in `tests/Feature/MailchimpVectorTest.php`: one POST accepted with 200, an identical second answered 400 Member Exists and handled as success. Suite green at 595
- **A contact now exists in the live audience**, `yosefsanad11@gmail.com`, created by that capture. Left in place deliberately, since deleting it would mean the vector describes something that never happened. Delete it before launch if the list should start clean
- **Step 3, the queued job.** Built 15 August 2026. `App\Jobs\SubscribeToNewsletter`, 8 tests
- **Step 4, the endpoint.** Built 15 August 2026. `NewsletterController`, `SubscribeToNewsletterRequest`, the route and its own throttle key, 12 tests. Documented in `.claude/api.md` for the frontend partner
- **The backend is complete.** Suite green at 615, Pint clean. What remains is step 5, which is in the frontend repo, and step 6, which is deployment and a walk
- **Step 6, walked on staging 15 August 2026.** Proved from outside, against the deployed environment:
  - `POST /api/v1/newsletter` answers 202, and a missing consent flag answers 422
  - CORS allows `https://staging.theworldtarot.com` and echoes it on both the preflight and the request. It does **not** allow `https://theworldtarot.com`, which matters when the coming-soon page gets its production origin
  - The job reaches the managed queue, a worker runs it, and the contact arrives in the audience as `subscribed` with source `API - Generic`
  - **`ip_signup` is the visitor's own address**, `62.163.245.202`, confirming the trusted proxy work end to end rather than in a synthetic chain
  - The rate limiter refuses the seventh call in a minute, keyed on that real address rather than on one bucket for everybody
- **Step 5, the frontend.** Done 15 August 2026, in the frontend repo. Most of it already existed: `InvitationSignup` owning the status, `InvitationForm` rendering it, the loader and confirmation swap. What was missing was that `requestInvitation` pointed at `/api/v1/{locale}/subscribe`, a route sketched before the backend had one. Corrected to `/api/v1/newsletter`, the 409 branch removed since the endpoint never distinguishes an existing address, the dev simulation kept for a new reason, and the email field's `maxLength` corrected from 50 to 254
- **Closed 15 August 2026.** A real browser on the deployed coming-soon page produced `yosefsanad11+comingsoon@gmail.com` in the audience, `subscribed`, with `ip_signup` the visitor's own address. Every step is proved against something other than our own code

## Close-out

**What shipped:** a public endpoint that takes an address and a consent flag, answers immediately, and hands the subscription to Mailchimp on a queue behind an interface named in config. Five backend commits and one frontend commit. Suite green at 617.

**What was found on the way, none of it in the plan:**

- **`$request->ip()` was caller supplied in both deployed environments**, because Laravel trusts every proxy by itself on this platform when nothing is configured. Fixing it took two attempts, the first of which made the address constant instead and would have shared one rate limit bucket across the whole internet. Neither was visible from the test suite
- **Mailchimp will not re-add a permanently deleted address**, ever, through any API call. Found by walking, pinned as a vector
- **Pest reads a name that is not a class as an expected message**, so `not->toThrow(SomeInterface::class)` passes while asserting nothing

**Deviations from the plan as written** are listed under Progress above. The largest is that consent evidence was dropped on 14 August, so this records that an address subscribed at a time from a place, and not what wording it agreed to.

**Left open, deliberately:**

- **Production is not done.** It needs the three `MAILCHIMP_*` variables, `NEWSLETTER_DRIVER` left unset, its own origin in `CORS_ALLOWED_ORIGINS`, and its own walk. Nothing about staging proves it
- **The trusted proxy residual.** The peer is trusted unconditionally, so anything reaching the balancer without passing through Cloudflare is trusted one hop too far. Whether such a path exists has not been established
- **A `workers.dev` origin is allowed on staging**, added for a preview deploy. Remove it once the page is served from `staging.theworldtarot.com`, because that domain is shared with every other Cloudflare user and this API allows credentials
- **Four test contacts are in the live audience.** Archive rather than permanently delete
- **The newsletter checkbox at checkout**, question 3, still deferred to M5 phase B
- **Production is not done.** It needs the three `MAILCHIMP_*` variables, `NEWSLETTER_DRIVER` left unset, its own origin in `CORS_ALLOWED_ORIGINS`, and the same walk
- **Three test contacts are in the live audience**, `yosefsanad11+walk1`, `+walk2` and `+walk3`. **Archive rather than permanently delete them.** A permanent delete burns the address for good, as `yosefsanad11@gmail.com` found out

Two deviations from the plan as written, both small and both deliberate:

- **`NewsletterSignup` lives in `App\Support`, not `App\Newsletter`.** The plan invented a namespace for it. `RequestContext` and `Money` are value objects in `App\Support` already, and one more top level namespace for one class is worse than following what is there
- **`App\Contracts\PermanentFailure` was not in the plan.** Step 3 has to tell a bad address from a bad afternoon, and the only alternative was the job catching `MailchimpRequestFailed` by name, which would have taught the job whose API it was talking to and undone the seam. A marker interface keeps that knowledge in the implementation, where only it knows what a status code means
- **`config/newsletter.php` reads a driver name rather than hardcoding a class.** The plan had it naming a class the way `config/reveal.php` does. With one audience shared by every environment, a developer running the form locally would add rows to Jennifer's real list, so `NEWSLETTER_DRIVER=log` exists to opt out. An unrecognised value falls through to Mailchimp on purpose, because a typo must not be a silent way to stop collecting subscribers
- **`LoggedNewsletterList` was not in the plan.** Step 1 needs `config/newsletter.php` to name something that exists, and naming a class step 2 has not written yet would leave the container broken between two commits. It also turns out to be the thing a developer wants locally, since both deployed environments share one audience and local signups would otherwise land in the list Jennifer mails

## Goal

The coming-soon page carries an email box and a consent checkbox that do nothing. This plan gives it somewhere to post: one endpoint on this API that takes an address, answers immediately, and hands the subscription to Mailchimp on a queue. The frontend sends an email and a consent flag and nothing else. It never learns that Mailchimp exists, holds no credential, and is unaffected on the day Mailchimp is replaced.

This is the `NewsletterList` row of `.claude/external-services.md`, which M6 owns, pulled forward because the coming-soon page is live and collecting nothing.

## Out of scope

- **The footer `NewsletterForm`** in the frontend repo, which also takes a first name. The endpoint is designed to accept one, so wiring that form later is frontend-only work. Decided 14 August 2026: coming-soon only for now
- **FAQ and legal content blocks**, the rest of M6
- **Storing subscribers in our database.** Mailchimp holds the list. A local table is a second copy that can disagree with it, and nothing in this system reads a subscriber
- **Unsubscribe.** Mailchimp's own footer link owns it, which is also what the consent copy promises
- **Any admin panel surface.** Jennifer reads her audience in Mailchimp

## The shape of it

```
Browser ──POST /api/v1/newsletter──> Controller ──dispatch──> Queue
   <────────── 202, always the same ─────┘                      │
                                                                v
                                          SubscribeToNewsletter ──> NewsletterList
                                                                      (MailchimpAudience)
```

Per `.claude/api.md`, a request must never wait on a third party, and newsletter signup in particular must be allowed to fail without affecting the request it rode in on. So the controller dispatches and answers. The visitor is told we have their address, because we have.

**The consequence, in writing: a signup that Mailchimp later refuses is invisible to the person who made it.** They saw a success message. That is the correct trade for a mailing list, and it would not be for a purchase. Three things keep it honest: the job retries for six hours so an outage is not a refusal (step 3), a permanent refusal is logged loudly and keeps the address in `failed_jobs`, and step 6 walks a real address through to the audience rather than trusting the suite.

## Steps

### 1. The seam

`App\Contracts\NewsletterList`, one method, in our words:

```php
public function subscribe(NewsletterSignup $signup): void;
```

`App\Newsletter\NewsletterSignup` is a readonly value object: the address, when they consented, the exact consent text they were shown, the address they came from, and an optional name. Deliberately not a `Customer`. Somebody joining a mailing list has no account and may never have one.

**No audience id anywhere in the interface or the value object.** `.claude/external-services.md` names that exact leak as the test for whether the seam is in the wrong place. The audience is a Mailchimp concept and lives in config.

`config/newsletter.php` names the implementation, mirroring `config/reveal.php`. `AppServiceProvider` binds the interface to whatever it names.

- **Proves it:** the container resolves the configured implementation, and swapping the config line swaps what a caller gets
- **Risk:** designing for a vendor nobody has named. Mitigated by the interface having one method that does one thing this system actually needs

### 2. `MailchimpAudience`

`App\Services\Newsletter\MailchimpAudience` implements `NewsletterList`, and is the only file in this repo that knows what Mailchimp is. Modelled on `App\Services\Bunny\BunnyStream`, including a `MailchimpRequestFailed` exception alongside it.

`config/services.php` gains a `mailchimp` block: the API key, the audience id, and the server prefix.

**The server prefix is its own env var, not parsed out of the key.** Mailchimp's key carries its datacenter as a suffix, `-us21`, and every example on the internet splits the key on a dash to build the base URL. That works until a key without the suffix is pasted in, at which point the base URL becomes `https://.api.mailchimp.com` and the failure reads like an auth problem rather than a config one. One more env var is cheaper than that hour.

Single opt-in was decided on 13 August 2026, so a contact is created with status `subscribed` rather than `pending`. Two fields carry what record there is:

- `ip_signup`, the address the request came from
- `timestamp_signup`, when they ticked the box

Both are native Mailchimp fields, so neither adds a column to the audience and neither needs anything created.

**Recording the consent wording was considered and dropped on 14 August 2026.** The audience has `marketing_permissions` false, so Mailchimp's GDPR structure is unavailable and it would have meant a new `CONSENT` merge field, visible as a column in Jennifer's audience, holding a version identifier per subscriber.

Dropped because the list is friends and family. **The consequence, in writing: we can show that an address subscribed at a given time from a given place, and not what wording it agreed to.** If this list ever becomes a public marketing list, revisit it, because the wording is the part that answers a complaint and it cannot be reconstructed after the fact.

The consent checkbox is still validated by `SubscribeRequest` in step 4. It is client-side on the frontend and therefore not a control at all, so the backend checks it regardless.

- **Proves it:** `Http::fake()` tests over the request shape, plus a **pinned vector**, below
- **Risk:** the one that cost five days on Bunny. See the next paragraph

**The vector is a manual step and cannot be skipped.** Per `.claude/testing.md`, the only authority on whether Mailchimp accepts our request is Mailchimp. Before this step closes: one real subscription sent by hand against a throwaway audience, the exact request and the exact accepted response recorded with the date, and the test asserting the code reproduces it. A comment says that regenerating the vector means proving the new one live first. An `Http::fake()` suite agreeing with our own implementation of Mailchimp's API is exactly the test that stayed green through total failure last time.

### 3. `SubscribeToNewsletter`

A queued job, modelled on `SendCardVideoToBunny`. Laravel Cloud's managed queues deliver at least once, so it must tolerate running twice.

**Running twice is free here, and that is by design.** Mailchimp answers a repeat subscription with a 400 whose detail says the member already exists. The job treats that as success rather than as a failure, which makes the whole thing idempotent without storing anything locally.

#### Surviving an outage

**A fixed `tries = 3` is not an outage policy.** Three attempts over a couple of minutes covers a dropped connection. Mailchimp being down for an afternoon is the case worth designing for, and it is the case where the visitor has already been told we have their address.

So the job carries a **deadline rather than an attempt count**:

- `retryUntil()` returns six hours from first dispatch. Attempts keep happening until then
- `backoff()` returns `[60, 300, 900]`, and Laravel repeats the last value, so it settles into a retry every fifteen minutes for the rest of the window

**Fifteen minutes is a ceiling, not a preference.** CLAUDE.md section 2 records that Laravel Cloud's managed queues cannot express a delay longer than fifteen minutes. A backoff of an hour would not produce an hour, it would produce whatever the platform does with an out-of-range delay, so the schedule is built to stay inside the limit rather than discovering it in production.

Six hours is roughly twenty-four attempts. It is chosen so an outage spanning a working day is survived without a person noticing, and so a genuinely dead integration still surfaces the same day rather than retrying quietly forever.

#### Two things the deadline makes more important, not less

**Permanent refusals must not enter the retry loop at all.** A malformed address, a rejected API key, or an audience id that does not exist cannot improve. Under `tries = 3` a wrong address wasted three attempts. Under a six hour deadline it would cycle for six hours, and a batch of them would keep a worker busy doing nothing. So a permanent refusal calls `fail()` immediately, and only a timeout, a 5xx, or a 429 retries.

**429 is a retry, not a failure.** Mailchimp rate limits, and being throttled is precisely the transient case this is for. Treating it as permanent would drop addresses during exactly the traffic spike a launch list exists to capture.

#### When the deadline is exhausted

`failed()` logs loudly. The address is not lost: the failed job driver is `database-uuids`, so the payload sits in `failed_jobs` with the address, the consent text and the timestamp intact, and `queue:retry` replays it once Mailchimp is back. That is the recovery path, and it is the reason this plan still does not need a local subscribers table.

Worth stating plainly, since it is the residual exposure: **six hours of continuous Mailchimp downtime means signups that need a person to run one command.** Nothing is silently dropped, but nothing is automatically recovered either.

- **Proves it:** the job's effect under `Http::fake()`, a redelivery producing one contact and no error, an already-subscribed address treated as success, a permanent refusal failing without retrying, a 500 and a 429 both retrying, and a job that exhausts its deadline landing in `failed_jobs` with the address still readable
- **Risk:** treating "already exists" as success also swallows a genuinely wrong response if Mailchimp ever reuses that shape. Accepted, and the vector is what would catch it

### 4. The endpoint

```
POST /api/v1/newsletter
     { "email": "...", "consent": true, "name": "..." (optional) }
→ 202 { "subscribed": true }
```

Flat, with no locale segment. Per `.claude/api.md`, content routes carry a language and this is not content.

`SubscribeRequest` validates: email required, lowercased in `prepareForValidation` exactly as `RegisterRequest` does it, `max:254`. Consent must be accepted, because the copy promises it was. Name optional, `max:40`, which is what the footer form's field allows.

Throttled, and **the third argument to `throttle` is load-bearing.** The comment at the top of `routes/api.php` records what happens without it: every throttled route shares one counter per address, which on 13 August 2026 meant six orders could refuse a password reset. This route gets its own key.

Two properties of the response, both deliberate:

- **202, not 201.** Nothing was created yet. It was accepted
- **The answer is identical for an address already on the list, a new one, and one Mailchimp will later refuse.** Anything else turns a public endpoint into a way to ask whether a given person subscribed

`CORS_ALLOWED_ORIGINS` must contain the coming-soon origin in both deployed environments, or this fails in the browser and nowhere else.

- **Proves it:** validation including a missing consent flag, the throttle refusing the eleventh call, dispatch happening, the response shape, and the same answer for a known and an unknown address
- **Risk:** an unauthenticated endpoint that reaches a paid third party. The throttle is the backstop and the queue absorbs the rest

### 5. The frontend

In the frontend repo, coming-soon form only.

`src/lib/newsletter.ts` owns the call, the way `src/lib/api.ts` owns the reveal. One function, one endpoint, so the day this moves the change is one file. No credentials on the fetch, since this is not an auth call.

`InvitationForm` becomes stateful: idle, submitting, success, error. `Button` already takes `disabled`. Success replaces the form with a confirmation. An error stays recoverable and does not clear what they typed. The outcome goes in an `aria-live` region, so it is announced rather than only drawn. Double submit is guarded while a request is in flight.

New copy goes in `src/content/coming-soon.ts` with the rest, not inline in the component.

**One bug fixed while in there.** The email input is `maxLength={50}` under a comment claiming that is "RFC 5321's maximum addressable length". It is 254, which is what the footer form uses. Today the field silently truncates long addresses, and a truncated address fails validation for a reason the visitor cannot see.

- **Proves it:** the frontend has no test runner, so this is proved by the walk in step 6 rather than asserted
- **Risk:** `AGENTS.md` in that repo warns this is not the Next.js in your training data. Read `node_modules/next/dist/docs/` before writing the component

### 6. Deploy and walk

Env vars set in staging and production, CORS origin added, then a real address subscribed through the deployed coming-soon page and confirmed present in the audience with the right IP, timestamp and consent text.

**Nothing before this step proves the feature works**, for the reason written at the top of `.claude/testing.md`. Steps 1 to 5 prove our code does what we meant. Only this proves Mailchimp agreed.

Watch specifically for the IP, which is the open question below.

## Settled on 14 August 2026, by asking the live account

The API key is in `.env`, datacenter `us20`. Read-only calls against it answered two of the four open questions:

- **One audience exists**, `The World Tarot`, id `3cef9f995a`, one member. There is no staging audience
- **`marketing_permissions` is false**, so GDPR fields are not enabled and the consent evidence goes in a merge field. See step 2
- **`double_optin` is false**, which agrees with the single opt-in decision. It would not have mattered either way: that setting governs Mailchimp's own embed forms, and a contact created through the API with status `subscribed` is single opt-in regardless
- **Merge fields are the six stock ones**, and none is required. Nothing to work around, and `FNAME` is already there for the footer form later. **No merge field is created by this plan**, so Jennifer's audience gains no column

## The DNS cutover already happened, and two documents say otherwise

Established 14 August 2026. `api.theworldtarot.com` and `staging-api.theworldtarot.com` both answer with `server: cloudflare` and a `cf-ray`, so they are proxied rather than merely resolved by Cloudflare, and `/api/v1/currencies` answers `detected: EUR` from a Dutch address, which is only possible if `CF-IPCountry` is arriving.

**Two documents are now wrong and should be corrected outside this plan**, per CLAUDE.md rule 14:

- **CLAUDE.md section 3** says `theworldtarot.com` "does not point here until launch"
- **`docs/deploy/laravel-cloud-setup.md` section 9** treats every Cloudflare concern as "at DNS cutover only", and says `CF-IPCountry` does not exist yet and currency detection falls back to USD. It does exist, and detection is live

One item in that section deserves checking rather than assuming, and it is good news: it asks for a cache rule on `/api/v1/currencies` keyed on `CF-IPCountry`, warning that otherwise one visitor's currency is served to everybody. **That has not happened.** The endpoint answers `cache-control: no-cache, private` and Cloudflare reports `DYNAMIC` on staging and `BYPASS` on production, so nothing is being held. The application's own headers are what protect it. The warning still stands for the day somebody adds a cache rule by hand.

## Open questions

1. ~~Does staging get its own audience?~~ **Settled 14 August 2026: no, both environments use `3cef9f995a`.** Step 6 subscribes `yosefsanad11@gmail.com`, a real address belonging to us, and the contact stays or is deleted by hand afterwards. The exposure accepted in writing: staging can write to the list Jennifer mails on launch day, so anything that dispatches this job in bulk from staging reaches her real audience
2. **How the visitor's real address is resolved, now that Cloudflare is confirmed to be in front.** Trusted proxies are not configured in `bootstrap/app.php`, so `$request->ip()` is whatever spoke to the application last, which is a proxy. Every subscriber gets the same `ip_signup`, and the step 4 throttle counts every visitor into one bucket. **It is already live beyond this plan**: `LoginRequest` keys its per-account limiter on `email|ip`, which with a constant address collapses to email only. Blocks step 2. Touches auth, so it is decided rather than assumed, and the naive fix is worse than it looks. See below
3. **Does anything need to happen when a customer places an order?** `.claude/api.md` mentions newsletter signup not blocking checkout, which implies a checkbox at checkout eventually. Not built here, and the interface is what it would call

## Question 2, settled 14 August 2026 and built 15 August 2026: option A

**What the code actually found, which changes the severity rather than the fix.** This question assumed an unconfigured application reports the balancer's address. It does not. `Illuminate\Http\Middleware\TrustProxies` applies `'*'` on its own when nothing is configured and `laravel_cloud()` is true, so the address was the leftmost `X-Forwarded-For` entry, which the caller writes. `LoginRequest`'s limiter was evadable by sending a header, not merely coarse, in both deployed environments. The fix below is unchanged and was already the right one. The urgency was not.

Confirmed rather than reasoned: reverting the configuration to `'*'` makes the forged-address test answer `1.2.3.4` instead of the real client, which is what a spoof looks like from the inside.

**Residual, and it is not closed by this commit.** The origin answers on its `*.laravel.cloud` hostname without passing through Cloudflare, so a request sent straight there is still trusted one hop too far. The fix is refusing non-Cloudflare traffic at the edge, which no code in this repo can do. Worth raising separately from this plan.

**Trust Cloudflare's published ranges**, so `$request->ip()` returns the real visitor everywhere and every IP-keyed thing in the application is fixed at once with no caller changing. Its own commit, before step 2. Declined: a middleware reading `CF-Connecting-IP` by hand, which needs the same range list and reimplements what the framework already does, and scoping the fix to the newsletter alone, which would leave a known auth weakness in place while proving we knew about it.

**What must not be done is `trustProxies(at: '*')`.** Trusting everything makes Symfony discard the whole `X-Forwarded-For` chain and return its leftmost entry, and that entry is whatever the client sent. Cloudflare appends to the header rather than replacing it, so somebody sending `X-Forwarded-For: 1.2.3.4` arrives as `1.2.3.4, <their real address>, <Cloudflare>` and the application believes `1.2.3.4`. That is worse than the situation today: the limiter would go from blunt to evadable by setting one header, and `ip_signup` would become forgeable.

**Naming the ranges is what makes it safe, and the reason is worth writing down.** Symfony walks the chain from the right, discarding addresses it trusts, and returns the first one it does not. With Cloudflare and the platform load balancer trusted and nothing else, that is the address Cloudflare itself observed. A forged entry sits further left and is never reached.

**The load balancer's address does not need to be known.** Symfony replaces the literal string `REMOTE_ADDR` in the trusted list with the actual peer, `vendor/symfony/http-foundation/Request.php` line 639, so the list is Cloudflare's ranges plus `REMOTE_ADDR`. Trusting the immediate peer is safe because it is not client controlled. This also means nothing here has to be rediscovered if Laravel Cloud renumbers.

Both IPv4 and IPv6 ranges go in, and the IPv6 half is not optional. The Bunny incident on 7 August 2026 was an IPv6 assumption that survived testing because `curl` defaults to IPv4, and omitting the v6 ranges here would fail the same way for the same reason: correct in every test, wrong for a real visitor.

- **Proves it:** `$request->ip()` returns the client address for a request carrying a realistic Cloudflare chain, and a forged `X-Forwarded-For` arriving from an address outside the trusted ranges does **not** move it. The second assertion is the one that matters and is the one that would be tempting to omit

## Dependencies

- ~~Mailchimp account access and an API key~~. In `.env` as of 14 August 2026
- ~~The audience id~~. `3cef9f995a`, unless question 1 adds a second for staging
- The coming-soon page's production origin, for CORS
- One manual subscription against the live API, for the step 2 vector
