# Translation design, rebuilt from requirements

**Superseded in part, and implemented.** The requirements interview and the reasoning here
still stand and are worth reading. Two things changed during the build: readiness became a
calculation rather than a manual flag, and content added after a language goes live is gated
per record. Delivered on 28 July 2026 via `docs/plans/translation-rebuild-plan.md`.

Agreed 27 July 2026. Replaces every earlier translation decision, including everything in `.claude/translation.md`, which is now stale and must be rewritten before this is built.

---

## The requirements this came from

Gathered rather than assumed. Recorded here because the design only makes sense against them.

| # | Question | Answer |
|---|---|---|
| 1 | Multilingual or multi-market? | Same site everywhere. Only price and language differ |
| 2 | Which locales? | Undecided. The requirement is being able to add one easily |
| 3 | Who translates? | The development team, from content Jennifer supplies |
| 4 | Missing translations? | A language is not shown until the whole site is ready |
| 5 | Mixed language? | Unacceptable |
| 6 | How many languages? | Three at most, realistically |
| 7 | What is translated? | Everything. Only **cards** need to be editable in the CMS. The rest can be JSON in the frontend repo |
| 8 | Anything never translated? | No. Even names are hers to change |
| 9 | SEO metadata | Whatever is best and simplest |
| 10 | URL shape | Every language is prefixed, English included |
| 11 | Card URLs | A stable English identifier is fine |
| 12 | hreflang | Explained below, no API work needed |
| 13 | Editing | Switching tabs is enough, no side by side |
| 14 | Progress visibility | A **manual flag per record per language**, plus a higher level flag per content type |
| 15 | Who makes a language public? | A developer, because the frontend JSON ships with it |
| 16 | Copy the English in as a starting point? | No. Never |
| 17 | Email language | English for now. Jennifer's written readings are outside the system |
| 18 | Turning a language off | Ideally instant, without a deploy |
| 19 | Legal documents | Whatever the standard is |
| 20 | Keep the current implementation? | Design from scratch |
| 21 | Partial languages over the API | Never. A language the frontend receives is complete |
| 22 | UI strings | Frontend repo, with the non-editable pages |
| 23 | Anything that annoyed you before? | Only the editable slugs |

### Decisions taken on top of those

1. **Product copy stays in the CMS**, so a typo is hers to fix. Only FAQ, legal, marketing pages and UI strings move to the frontend repo
2. **Storage: JSON columns keep the values, a small table holds the readiness flags.** Option B of the two considered
3. **Cards are identified by a slug generated from the English title**, once, and never editable afterwards
4. **The preview state is kept**, so Jennifer can walk the whole site in a language before customers can

---

## What this system is actually for

Twenty-two cards and four products, in three languages at most. Roughly seventy translated records for the life of the site.

At that size, storage choice, indexing and join cost are irrelevant, and any design that spends its complexity there is solving the wrong problem. **The work is the workflow**: knowing what is ready, refusing to publish what is not, and being able to pull a language instantly if it is wrong.

---

## The design

### 1. Where content lives

| Content | Home | Editable by |
|---|---|---|
| Cards, including translations | Database | Jennifer |
| Product copy, including translations | Database | Jennifer |
| Product prices, published state | Database | Jennifer |
| UI strings, navigation, buttons | Frontend repo, JSON | Developer |
| Marketing page copy | Frontend repo, JSON | Developer |
| FAQ | Frontend repo, JSON | Developer |
| Legal documents | Frontend repo, JSON | Developer |

The split follows one rule: **if she needs to change it without us, it is in the database.** Everything else ships with the code, which also matches the fact that a language only goes live in a deploy.

### 2. Locale registry

One table. No config file, so there is a single source of truth and no "config wins over database" rule to remember.

```
locales
  code            "en", "es"
  english_name    "Spanish"
  native_name     "Español"
  status          draft | preview | live
  sort_order
  timestamps
```

| Status | Who sees it | Purpose |
|---|---|---|
| `draft` | Nobody. Tabs in the CMS only | Translations being entered |
| `preview` | Signed-in admins, through the locale in the URL | Jennifer signs off the whole site |
| `live` | Everyone. Appears in `/languages` | Public |

**Turning a language off is a status change**, effective on the next request, no deploy. That only works end to end if the frontend builds its language switcher from `GET /api/v1/languages` rather than hardcoding a list, so it belongs in the contract as a requirement rather than a suggestion.

### 3. Readiness flags

Exactly what was asked for: a manual flag, not a calculation. A filled field is not a good translation, and only a person can say otherwise.

```
translation_readiness
  translatable_type, translatable_id
  locale
  is_ready
  timestamps
  unique (translatable_type, translatable_id, locale)
```

In the panel:

- A **toggle inside each language tab**, "This language is finished", which sets the flag for the record and locale being edited
- A **column on each list** showing which languages are ready, as badges. With three languages at most this stays readable
- A **summary line** above each list: "Spanish: 20 of 22 cards ready"

### 4. The gate

**A locale cannot move to `live` while any translatable record is unflagged in it.** One check, one place, and it is the machine-readable form of "do not show the language until the whole site is ready".

The models it walks are declared in code, since adding a translatable model is a developer act:

```php
// config/translatable.php
'models' => [App\Models\Card::class, App\Models\Product::class],
```

Moving to `draft` or `preview` is never blocked. Only publication is.

Because the gate guarantees completeness, **the API never serves a partial language**, which is what the frontend is entitled to assume. If a value is somehow missing anyway, serve the default locale for that field and log it loudly. That path should never fire in production, and a blank page is worse than one stray English line.

### 5. Storage of the values themselves

JSON columns per translatable field, keyed by locale, as the current code already does. Adding a locale adds a key and never a migration.

Chosen over a translations table for one honest reason: at seventy records, the relational version buys nothing measurable, and the Filament language tabs already work against JSON columns. The readiness table is needed under either design, and it is where all the new behaviour lives.

### 6. URLs and the API

- Every language is prefixed, English included: `/en/...`, `/es/...`
- Cards carry a **slug generated from the English title when the card is created**, then frozen. Not editable, not translated. Jennifer renaming a card never breaks a link
- Content endpoints sit under the locale: `/api/v1/{locale}/products`, `/api/v1/{locale}/cards`, `/api/v1/{locale}/cards/{slug}`
- `/api/v1/languages` lists live locales only, and is not itself under a locale
- **A locale that is not live is a 404**, not a fallback. An unfinished language must not be reachable by guessing a URL. For a signed-in admin, a `preview` locale resolves normally

### 7. SEO

**Derive it, do not store it.** Page title from the card or product name, meta description from the short description. Separate SEO fields per language mean three more boxes per record that can be left empty or go stale, for a gain nobody has asked for. Add overrides later if a real need appears.

**hreflang** tells search engines that the same page exists in another language, so the two do not compete and each is shown to the right searcher. Since URLs are deterministic, the same path with a different prefix, the frontend generates these itself from its own routes. No API involvement, one line in the contract.

### 8. Email and legal

- Transactional email is English for now. The order records the customer's locale, so switching this on later needs no data migration
- Jennifer's hand-written readings are outside the system entirely
- Legal documents live in the frontend repo. Standard practice for a US business selling online is that the English version governs and translations are informational, stated on the page. That line is the frontend's to render

---

## What this deletes from the current code

- `config/localization.php`, replaced by the `locales` table
- The `configured()` scope and the "config always wins over the database" rule
- The `LocaleState` enum's `available` case, which becomes `draft`
- The legal document fallback exception, since legal is no longer in this repo
- Any notion of automatic coverage counting

`.claude/translation.md` describes the old design in detail and is now wrong in almost every particular. **It must be rewritten before implementation starts**, or it will quietly steer the work back to the design it documents.

---

## Implementation steps

Each is one commit with tests in the same commit.

### Step 1, rewrite the rules file
`.claude/translation.md` replaced with this design. No code.

### Step 2, the locale registry
- Migration: add `english_name`, `native_name` to `locales`, rename `state` to `status`, convert `available` to `draft`
- Delete `config/localization.php` and every reference to it: `LocaleResolver`, `Locale::scopeConfigured`, `AdminPanelProvider`'s tab list, `LanguageResource`'s label lookup, and the tests that set the config
- Language tabs in the panel come from the `locales` table, cached per request
- **Tests:** the tab list follows the table, a language added to the table appears with no code change and no migration, `/languages` lists live locales only

### Step 3, readiness
- `translation_readiness` table, model, and a `HasTranslationReadiness` concern
- `config/translatable.php` declaring the models the gate walks
- A `LanguageReadiness` service answering "is this locale complete" and "what is outstanding"
- **Tests:** flags are per record and per locale, an unflagged record makes the locale incomplete, flagging every record makes it complete, a record added later makes it incomplete again

### Step 4, the panel
- Readiness toggle inside each language tab
- Badge column on the card and product lists
- Summary line per list
- A `LocaleResource`: statuses visible, promotion to `live` **blocked and explained** when anything is outstanding, and demotion always allowed so a language can be pulled instantly
- **Tests:** the toggle writes the flag for the active locale only, promotion is refused with a message naming what is missing, promotion succeeds when everything is flagged, demotion is never blocked

### Step 5, the API
Assumes the locale path work in `docs/plans/product-keys-and-locale-paths.md`. Do that plan first, or fold the two together, since both move the same routes.

- Content routes under `{locale}`, a non-live locale 404s for the public and resolves for a signed-in admin in `preview`
- `/languages` reads the table
- **Tests:** live serves everyone, preview serves admins and 404s for the public, draft 404s for everyone, an unknown locale 404s

### Step 6, cards
Belongs to M3 and is listed here only so the shape is agreed in advance: translatable name, description and meaning, a slug generated once from the English title, and readiness flags like everything else.

### Step 7, documentation
`API_CONTRACT.md` gains the locale path shape, the 404 rules, the requirement to build the language switcher from `/languages`, and the hreflang note. `QA_CHECKLIST.md` gains the readiness and promotion paths. `README.md` needs no change.

---

## Risks

- **Removing `config/localization.php` touches a lot of files.** It is referenced in the resolver, the panel provider, the API resource, two migrations and several tests. Expect step 2 to be wider than it sounds
- **The frontend must respect `/languages` at runtime**, or the kill switch is decorative. This is the single most important line in the contract update
- **Spanish is currently seeded as `available`**, which becomes `draft`. After the API change it will 404 rather than falling back to English, which is correct and will look like a bug during QA
- **The readiness gate is only as honest as the person ticking the boxes.** That is deliberate, and worth saying out loud to whoever ticks them
