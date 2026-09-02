# Laravel Cloud setup, staging and production

The dashboard steps for standing up both environments, in order. Written to be followed once, then kept as the record of how the environments are configured.

The point of doing this against an essentially empty app is that environment problems, cross-subdomain cookies, CORS, queue workers, migrations, surface now rather than in week six against a full one.

Status: not yet executed. Update the checkboxes and the notes as you go.

---

## 0. Before you start

Have ready:

- GitHub access to this repository
- The domain, currently at GoDaddy. Nameservers do **not** move to Cloudflare during this step
- The frontend origins, if the frontend partner has provided them. If not, the placeholders below are fine and get corrected later

Two facts that shape everything here:

- **PHP 8.4** is the target. `composer.json` requires `^8.4.1` and pins `config.platform.php`. **Check the runtime dropdown offers 8.4 before creating the project.** If it caps lower, stop and tell me, because the lock file will not install
- **Node 22 or newer** is needed for the Filament asset build, declared in `package.json`

---

## 1. Create the project

- [ ] New project, connected to this GitHub repository
- [ ] Region: closest to the audience. UK or EU given the client and expected traffic
- [ ] PHP version: **8.4**
- [ ] Two environments: **production** and **staging**

Create staging first and deploy to it first. Production should never be the environment where you find out something is wrong.

---

## 2. Database

- [ ] Attach a **Postgres** database to each environment, separate instances, never shared
- [ ] Confirm the version. Local development runs Postgres 16

Laravel Cloud injects the database connection variables. Do not paste credentials by hand, and do not set `DB_URL` yourself unless the platform tells you to.

The app uses `jsonb` columns and expression indexes later, so Postgres is not interchangeable with anything else here. Never SQLite, in any environment.

---

## 3. Environment variables

Set per environment. Everything not listed keeps the value in `.env.example`.

### Both environments

| Variable | Value |
|---|---|
| `APP_NAME` | `The World Tarot` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | Generate per environment, never reuse between them |
| `SESSION_DRIVER` | `database` |
| `QUEUE_CONNECTION` | Set by the platform to `cloud`, leave it alone. See section 5 |
| `CACHE_STORE` | `database` |
| `SESSION_SECURE_COOKIE` | `true` |
| `SESSION_SAME_SITE` | `lax` |

`APP_DEBUG=true` on a deployed environment renders a stack trace page that includes environment variables, which means database credentials and, later, Stripe and OpenAI keys. It is the single most expensive checkbox on this page.

### Production

| Variable | Value |
|---|---|
| `APP_ENV` | `production` |
| `APP_URL` | `https://api.theworldtarot.com` |
| `SESSION_DOMAIN` | `.theworldtarot.com` |
| `SANCTUM_STATEFUL_DOMAINS` | `theworldtarot.com,www.theworldtarot.com` |
| `CORS_ALLOWED_ORIGINS` | `https://theworldtarot.com,https://www.theworldtarot.com` |
| `CORS_ALLOWED_ORIGINS_PATTERNS` | empty, unless preview URLs need production |

### Staging

| Variable | Value |
|---|---|
| `APP_ENV` | `staging` |
| `APP_URL` | `https://staging-api.theworldtarot.com` |
| `SESSION_DOMAIN` | `.theworldtarot.com` |
| `SANCTUM_STATEFUL_DOMAINS` | `staging.theworldtarot.com` plus the Cloudflare Pages preview host |
| `CORS_ALLOWED_ORIGINS` | `https://staging.theworldtarot.com` |
| `CORS_ALLOWED_ORIGINS_PATTERNS` | `^https://[a-z0-9-]+\.<project>\.pages\.dev$` |

Three things about this block, all of which fail silently rather than loudly:

- `SANCTUM_STATEFUL_DOMAINS` takes **hosts without a scheme**. `CORS_ALLOWED_ORIGINS` takes **full origins with the scheme**. Mixing the two formats produces no error and no working login
- The leading dot on `SESSION_DOMAIN` is what makes the cookie readable across `api.` and the bare domain. Without it, the frontend authenticates and then appears logged out
- Staging must mirror the production pattern exactly, same root domain, same cookie scope. A staging setup that does not share a root domain with its frontend proves nothing about production

### Added by M5 phase A, on 13 August 2026

Everything above dates from M1 and was verified against staging on 13 August: `APP_DEBUG` is off, the session cookie is `tarot_staging_session` scoped to `.theworldtarot.com`, `secure`, `httponly` and `samesite=lax`, and the site origin is both CORS-allowed and stateful. Member accounts work there.

**None of the following existed when that was written, and mail cannot work without it.**

| Variable | Staging | Production |
|---|---|---|
| `MAIL_MAILER` | `resend` | `resend` |
| `RESEND_API_KEY` | A sending-only key | A sending-only key, a different one |
| `MAIL_FULFILLMENT_RECIPIENT` | Wherever you want the staging copy | **An address Jennifer really reads** |
| `FRONTEND_URL` | `https://staging.theworldtarot.com` | `https://theworldtarot.com` |
| `FRONTEND_RESET_PASSWORD_PATH` | `/reset-password/`, and only to override the default | The same |
| `FRONTEND_SET_PASSWORD_PATH` | `/set-password/`, and only to override the default | The same |

The three sender addresses, `MAIL_SUPPORT_ADDRESS`, `MAIL_ORDERS_ADDRESS` and `MAIL_FULFILLMENT_ADDRESS`, need setting only to override the defaults in `config/mail.php`. Every one of them has to sit on a domain verified in Resend.

Four ways this fails quietly, and the first two have already happened:

- **`MAIL_MAILER` left at `log` accepts every message, reports success and delivers nothing.** Run `php artisan mail:check you@example.com --now` on the environment console, then again without `--now`. The first proves the transport, the second proves the transport and a queue worker together, and those two failures look identical from the outside
- **A variable that is present and blank is not absent.** `env()` falls back only when a key is missing, so a cleared field in the platform UI gives an empty string. `config/mail.php` was hardened with `?:` on 13 August after `MAIL_FULFILLMENT_RECIPIENT=` in `.env.example` meant Jennifer's notification was addressed to nobody, everywhere the template had been used
- **`MAIL_FULFILLMENT_RECIPIENT` falling back to `fulfillment@theworldtarot.com` is not a working setup.** Resend verification authorises sending and creates no mailbox, so unless an MX record points somewhere real, that address accepts the notification and discards it. Set it to something Jennifer reads
- **`FRONTEND_URL` carrying the wrong environment is worse than a broken link.** A staging reset email pointing at production sends somebody to the right-looking site with a token it cannot use
- **Both password paths end in a slash**, because the website is a static export and the slashless form redirects. `FrontendUrl` adds the slash whatever these two are set to, so a value typed without one is harmless, but the pair is easier to read set correctly. Both pages were built and proven against this API on 28 August 2026

---

## 4. Deploy commands

Build:

```
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

Deploy, after the build:

```
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:optimize
```

`--force` is required because migrations are non-interactive here. `filament:optimize` caches the panel components and matters more as resources are added.

**`db:seed` runs on every deploy and must.** Products are seeded rather than created in the panel, so this is how they reach production. It is safe to repeat: structure is re-applied each run, and anything Jennifer has edited, copy, prices, published state, is never overwritten. Only production-safe seeders belong in `DatabaseSeeder`.

Do not cache config locally. `config:cache` freezes `env()` reads, and anything calling `env()` outside a config file returns null afterwards.

---

## 5. Queues, managed rather than self-run

Creating a **managed queue** sets `QUEUE_CONNECTION=cloud` for that environment automatically. That is expected and correct. Laravel Cloud provisions the queue, runs the workers on their own instances, and scales them on queue depth down to zero. Nothing needs adding to `config/queue.php`, the platform injects the connection.

This supersedes the database queue driver named in `CLAUDE.md` section 2. The reason is specific rather than a preference: on a Scale-to-Zero environment, a worker running on the app cluster is killed when the app sleeps, **even mid-job**. Managed queue workers run separately and are not interrupted.

- [ ] Add compute, Managed queue, on each environment
- [ ] Name it something conventional and **mark it as the environment default**, so jobs dispatch without `->onQueue()`
- [ ] Memory: 256 MiB is right for M1 through M3
- [ ] Maximum workers: start at the Starter plan cap of 3. Parallelism is limited by what Postgres and third parties tolerate, not by ambition
- [ ] Leave polling interval at 5 seconds, visibility timeout at 60 seconds, shutdown timeout at 90 seconds

Requirements, both already satisfied in this repo, listed so a failed deploy is diagnosable:

- `aws/aws-sdk-php` in `composer.json`, present at 3.389
- Laravel 13.11.2 or newer, present at 13.21.1

Nothing queues work in milestone 1. Create the queue anyway. A worker nobody has run is a worker nobody has proved runs.

### Three consequences to design around later

- **At-least-once delivery, no exactly-once and no ordering.** Every job must tolerate running twice. Already the rule for webhooks in `.claude/api.md`, now the rule for all jobs
- **Jobs cannot be delayed more than 15 minutes.** Anything wanting a longer delay, such as a follow-up a day after purchase, needs the scheduler plus a query, not a delayed dispatch
- **Raise the visibility timeout for slow jobs.** The default 60 seconds is well under a Bunny upload in milestone 4. If a job outlives the visibility timeout it gets delivered again and runs twice concurrently. Set it above the worst case for that queue when the job exists

Failed jobs appear in the Queues tab with the payload, exception and a retry button. There is no failed jobs driver to configure, and the `failed_jobs` table stops being the place to look.

No scheduler is needed yet. Nothing is scheduled.

---

## 6. Domains

- [ ] Production environment: `api.theworldtarot.com`
- [ ] Staging environment: `staging-api.theworldtarot.com`
- [ ] Add the DNS records Laravel Cloud specifies
- [ ] Confirm TLS is issued and the health endpoint at `/up` answers over HTTPS

**Nameservers were at GoDaddy when this was written and are now at Cloudflare.** This step used to carry a warning against moving them before every existing record was mirrored, MX especially, because Jennifer's email would break silently. The move has happened, so the warning is history rather than instruction. Whether her mail survived it is a question for CLAUDE.md section 8, which still has an open item about `support@theworldtarot.com`.

---

## 7. Prove the deploy

Work through these against **staging** first, then repeat the ones that apply against production.

- [ ] A push to `main` triggers a deploy and it succeeds
- [ ] Migrations ran. `locales` contains `en` as `live`
- [ ] `GET /up` returns 200
- [ ] `GET /api/v1/languages` returns `[{"code":"en","label":"English"}]`
- [ ] `GET /api/v1/currencies` returns the three currencies, and `detected` matches where you are calling from. It answered `EUR` from the Netherlands on 14 August 2026. Before the DNS cutover this checklist expected `USD` for everybody, because `CF-IPCountry` did not exist yet
- [ ] Create the first admin, non-interactively, via the platform's command runner:
      `php artisan app:create-admin --name="Jennifer" --email="jennifersemail6064@gmail.com"`
      Omitting `--password` generates one and prints it **once**. Copy it before closing the log, and have her change it at first login
- [ ] Log in at `/admin`, open a reading, change its name and save, confirm the English tab persists
- [ ] Open **Languages**. English is live, Spanish is a draft showing how many readings are translated
- [ ] `/docs/api` is **refused** when signed out, and loads when signed in as an admin
- [ ] The Queues tab lists the managed queue, with workers correctly at zero since nothing has been dispatched

Then the cross-domain check, which is the one that actually justifies deploying this early. It needs the frontend deployed, so it may lag by a few days:

- [ ] From the frontend origin, `GET /sanctum/csrf-cookie` sets a cookie scoped to `.theworldtarot.com`
- [ ] A credentialed request from that origin succeeds, no CORS error
- [ ] The session survives a page reload

---

## 8. Measure the cold start

Open item in `CLAUDE.md` section 8. Do this once staging is idle for a while, at least 15 minutes.

```
curl -o /dev/null -s -w "%{time_total}\n" https://staging-api.theworldtarot.com/up
```

Take the first number after idle, then two more immediately after. The gap between them is the cold start.

Rough reading: under half a second is fine and Scale-to-Zero stays. Anything past two seconds and production wants an always-warm instance, which costs money and is therefore a decision for the client, not a default.

Record the numbers here when measured:

```
first request after idle:
second request:
third request:
measured on:
```

---

## 9. Cloudflare, now live

**This section described future work until 14 August 2026, when checking established that the cutover had already happened.** Both API hostnames answer with `server: cloudflare` and a `cf-ray`, so they are proxied and not merely resolved. Rewritten to say what is true rather than what was planned.

- ~~Mirror every existing DNS record, MX especially, before changing nameservers~~. Nameservers moved. Whether MX came through intact is still open, see CLAUDE.md section 8
- **`CF-IPCountry` arrives, and currency detection is live.** It no longer falls back to USD for everybody. `/api/v1/currencies` answered `EUR` from the Netherlands on 14 August 2026
- **The cache rule for `/api/v1/currencies` was never added, and nothing is broken by that.** The risk it guarded against, one visitor's `detected` currency being served to everybody, is real but not live: the endpoint answers `cache-control: no-cache, private` and Cloudflare reports `DYNAMIC` on staging and `BYPASS` on production, so no copy is held. **The application's own headers are what protect it, not a rule at the edge.** If a cache rule is ever added by hand, it must key on `CF-IPCountry` and the `currency` query parameter, and must not key on the whole query string or marketing parameters will shred the hit rate
- `/api/v1/languages` is identical for every visitor and can be cached normally. Also not currently cached
- **Trusted proxies were never configured, and the platform quietly configured them for us.** `Illuminate\Http\Middleware\TrustProxies` trusts everything when nothing is set and `laravel_cloud()` is true, which made `$request->ip()` the leftmost `X-Forwarded-For` entry, a value the caller writes. Not a blunt address, a forgeable one. Fixed 15 August 2026, reasoning in `App\Support\TrustedProxies`, and the negative case is pinned by `tests/Feature/TrustedProxiesTest.php`
- **Whether anything can reach the balancer without passing through Cloudflare has never been established.** The `*.laravel.cloud` hostname was assumed to be such a path and turned out to be proxied as well. The exposure is unquantified rather than known to be open or closed

### Proving a request came through Cloudflare

Anything reaching the balancer is trusted one hop too far, because the balancer is the peer however it was reached. Cloudflare cannot be recognised by address here, and `CF-Connecting-IP` proves nothing on its own: Cloudflare overwrites it in transit, and a caller talking straight to the balancer is not in transit.

A secret the caller does not know is what breaks the circle. `App\Http\Middleware\VerifyCloudflareOrigin` checks it and, failing, strips the forwarded headers so the request falls back to the balancer's address rather than one it chose.

**Order matters, and getting it backwards strips every real visitor's address:**

- [ ] Generate a secret, `php -r 'echo bin2hex(random_bytes(32));'`
- [ ] At Cloudflare, add a **Rules > Transform Rules > Modify Request Header** rule on the zone: *Set static*, header `X-Origin-Verify`, value the secret. Apply it to all incoming requests
- [ ] Confirm it is arriving before requiring it. With `CLOUDFLARE_ORIGIN_SECRET` still unset, nothing changes and nothing breaks, so this step is free
- [ ] **Then** set `CLOUDFLARE_ORIGIN_SECRET` in the environment and redeploy
- [ ] Walk it: a signup through the deployed page still records the visitor's own address, and `curl` straight at the API records the balancer's instead

**Rotating it** means changing the rule and the variable. They may disagree for as long as that takes; addresses fall back to the balancer during the window and nothing fails.

**If the rule is ever removed or edited wrongly**, every request loses its address at once, rate limits collapse into one bucket, and `A request claimed a forwarded address without proving it came through Cloudflare` appears in the log once a minute. That line is the symptom to look for.

**This is not a full close.** Refusing non-Cloudflare traffic outright, at the platform or with authenticated origin pulls, is still stronger and still unavailable. What this removes is the ability to forge an address.

---

## 10. What is deliberately not here

- Stripe keys and webhook endpoints, milestone 5
- Bunny credentials and the transcode callback URL, milestone 4
- Resend and Mailchimp keys, milestones 5 and 6
- OpenAI keys, milestone 7 if commissioned
- R2 bucket credentials, when image storage arrives

Each is added to both environments by the milestone that needs it, with the staging environment always pointed at test credentials.
