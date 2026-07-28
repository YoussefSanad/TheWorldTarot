# The World Tarot, API and admin panel

The Laravel API and Filament admin panel behind The World Tarot. The public website is a separate Next.js application in its own repository, built by the frontend partner, and it consumes this API.

**Nothing customer-facing is rendered here.** This repository owns the API, the admin panel, payment processing, video ingestion and authentication. If a change appears to need a rendered page, the correct output is an API endpoint plus a note on what the frontend does with it.

Internal planning documents and deployment runbooks are deliberately kept outside this repository.

---

## Requirements

| | Version | Notes |
|---|---|---|
| PHP | 8.4.1 or newer | Declared in `composer.json`, which also pins the platform so Composer resolves against production rather than your local interpreter |
| Node | 22 or newer | For the admin panel asset build |
| Postgres | 16 or newer | **Never SQLite**, in any environment. The schema uses `jsonb` columns and expression indexes |

Required PHP extensions: `intl`, `pdo`, `pdo_pgsql`.

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate

createdb the_world_tarot
createdb the_world_tarot_testing

php artisan migrate
npm install && npm run build
```

Then create an admin account. Omit `--password` and one is generated and printed once:

```bash
php artisan app:create-admin --name="Your Name" --email="you@example.com"
```

`composer dev` runs the server, queue listener, log tail and Vite together. The admin panel is at `/admin`.

## Tests

```bash
./vendor/bin/pest
```

Tests run against Postgres, never SQLite. Every feature ships with tests in the same commit as the feature. Access control and anything touching money are tested on the denied path as carefully as the allowed one.

Format with `./vendor/bin/pint` before committing.

## Generated API reference

An OpenAPI reference is generated from the code, so it cannot drift from the actual responses:

- `/docs/api`, the browsable page
- `/docs/api.json`, the OpenAPI 3.1 specification

Open in local development. Everywhere else it requires an admin session, since it enumerates the whole API surface.

The specification is machine readable, so the frontend can generate TypeScript types from it rather than hand-writing interfaces.

## The frontend contract

**[API_CONTRACT.md](API_CONTRACT.md)** is the agreement with the frontend partner: base URLs and versioning, every endpoint, how locale and currency are resolved, how prices are expressed, the authentication flow, error shapes, caching, and what is not built yet.

The generated reference describes shapes. The contract explains the rules behind them, and the rules are the part that costs a week when they are not written down. Read both.

Changing a documented response shape is a coordination event, not a unilateral change. Adding a field is safe.

## Manual QA

**[QA_CHECKLIST.md](QA_CHECKLIST.md)** is the pass to run against a deployed environment after each milestone: every check paired with what should happen, and a section on what looks broken but is not. Extend it per milestone rather than starting a new one.

