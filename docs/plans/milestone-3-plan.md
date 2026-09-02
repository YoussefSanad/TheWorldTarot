# Milestone 3, the World Tarot and the Library

> **Shipped 29 July 2026.** Written and approved 28 July. The close-out is at the bottom:
> what shipped, what deviated, and what is still owed. Everything above it is the plan as
> approved, kept as written so the deviations mean something.

---

## Close-out, 29 July 2026

**All eight steps shipped, plus card artwork, which the plan had explicitly excluded.** 309 tests, 695 assertions, green. Merged to `main` and running on production, where uploads reach the attached bucket.

### What deviated, and why

| Deviation | Why |
|---|---|
| **Artwork pulled in from M4** | A card must have a picture to be on the website, which makes it part of what finished means rather than a later addition. Brought the storage disk with it, which M4 needed anyway |
| **Copy went from two fields to three** | Decision 1 changed after approval, to match products field for field |
| **Cards addressed by id, not a frozen slug** | Decision 5, raised after drafting. A card is a modal opened from the Library, so its identifier never reaches an address bar |
| **All three endpoints return one shape** | Decision 6, raised after drafting. Splitting the copy would save a few kilobytes and cost a round trip per card opened |
| **A partial unique index was needed after all** | The plan claimed dropping the slug removed the need. The number carried the identical problem, since a soft deleted card keeps it |
| **Four things built that the plan did not name** | The shared edit concern, the progress concern, `CopyFields`, and `media:check`. The first three were deduplication as the second model arrived. The last was infrastructure this milestone turned out to need |

### Standing rules this milestone reversed

Rewritten where they were written, rather than left contradicting the code.

- `CLAUDE.md` section 5, the reveal having no backend endpoint
- `CLAUDE.md` section 2, images living in hand-configured Cloudflare R2
- `.claude/translation.md` section 9, cards using a slug frozen from the English title

### Bugs found while building, all fixed

- **A factory state that built translations from other attributes was order-dependent**, producing cards that claimed to be translated and were not. Tests would have passed for the wrong reason
- **Filament's `unique` rule counts soft deleted rows**, so the panel refused a card number the database would accept, trapping the one person who could not get out of it
- **A file field on a translation tab is emptied by the language switcher**, so saving a translation deleted the card's picture
- **The platform publishes attached buckets as a blob naming the disk they replace**, not as `AWS_` variables. Nothing read it, so uploads were landing on an ephemeral filesystem and would have vanished on the next deploy, silently

### Still owed

- **The manual QA pass.** Sections 6a and 6b of `QA_CHECKLIST.md` are written and unwalked
- **The frontend contract is unsent**, and now describes three endpoints they have never seen
- **There is no staging environment.** Testing happens on production, which `CLAUDE.md` section 3 does not allow for and M5 cannot tolerate
- **Jennifer owes** the card names if her deck differs from Rider-Waite, the 22 pictures, and the copy. Until the pictures exist, `/cards` is correctly empty

---

## Current state, read from the code as it is

- **Products are the working example of everything this milestone repeats.** One table, translatable `jsonb` columns, calculated completeness, calculated availability, an edit-only Filament resource with language tabs, two locale-prefixed endpoints
- **The translation machinery is finished and generic.** `HasTranslationCompleteness` is a trait taking its field list from the model, `TranslationCoverage` walks whatever `config/translatable.php` lists, and `AppServiceProvider` registers the withdrawal observer over that same list. Cards join by adding one line to that config
- **`config/translatable.php` already carries a comment saying cards join it in M3.** Nothing else in the codebase anticipates cards. There is no `cards` table, no model, no migration, no route
- **No card, video, image or media code exists anywhere.** `aws/aws-sdk-php` is installed, but `config/filesystems.php` still holds the stock `s3` block and no Cloudflare R2 disk is configured, and there are no storage credentials in the environment
- **192 tests, 439 assertions, green.** `migrate:fresh --seed` clean
- **Deferred from M2 and now due:** the per-list translation summary line, which M2 postponed to here on the grounds that two translatable models were too thin for it to mean anything. Twenty-two card rows make it mean something
- **Still true and unchanged:** no deploy has happened, the frontend contract is unacknowledged, Spanish is a draft and correctly 404s

## Goal

Give the 22 Major Arcana a content model Jennifer owns end to end: a card is a row she can create, name, describe and translate herself, with nothing on it she is not allowed to change her mind about. Cards join the existing language gate by declaring their required fields, which means the Library counts toward whether a language is finished and a card missing its Spanish takes Spanish off the website exactly as a reading does. Expose the Library to the frontend as locale-aware read endpoints, including the one the homepage reveal draws from. Nothing here touches video, artwork, the Viewing Room, or access control.

## Explicitly out of scope

| Not here | Owned by |
|---|---|
| Card videos, Bunny upload, transcode webhook, the processing and ready status column | M4 |
| The Viewing Room, the pass product row, the access stamp on the customer | M4 seeds the row, M5 grants the stamp |
| Signed video URLs and token protection | M4 |
| Checkout, orders, anything that sells a card | M5 |
| FAQ, legal, marketing copy | M6, and mostly the frontend repo |
| The one per session limit on the homepage reveal | Frontend, cookie or local storage. This milestone serves a card, it does not remember who has had one |
| ~~Card artwork and any image storage, R2 included~~ | ~~M4~~. **Pulled into M3 on 28 July 2026**, see decision 2 |
| Card layout, the pop-up, the flip animation, the deck arrangement | Frontend repo |

## Steps

Each step is one commit, with its tests in the same commit, reported before the next begins.

### Step 1, this plan
No code. It lands in the repo with your commit, as the record of why the rest is shaped this way.

### Step 2, the `cards` table, model and factory
- Migration, literals only, no enum or config references, per the trap list in `docs/CHECKPOINT.md`
- Columns: `number` unique integer, `name`, `short_description` and `long_description` as `jsonb`, `is_published` boolean defaulting true, timestamps, soft deletes
- Three translatable fields, all three required, per decision 1. Nothing else, and no artwork column, which arrives with the video in M4
- The field names match `products` exactly, deliberately. One vocabulary across the panel, one wording list for what is missing, and the two resources read the same way
- **No slug**, per decision 5. A card is addressed by its id, because it is never addressed in a browser. There is no slug history, no redirect layer, and no partial unique index to work around soft deletes
- `number` is the Major Arcana position, 0 to 21 today. Unique but not range checked, per decision 3, so a twenty third card is a row she can add rather than a developer change. It is also the display order, so there is no second `sort_order` column for Jennifer to keep in sync with it
- `is_published` mirrors products: a developer level switch, defaulting true, never shown in the panel, and the only thing the language gate excludes
- Soft deletes, because M4 attaches a video asset to a card and losing the row would orphan it
- `Card` model uses `HasTranslations` and `HasTranslationCompleteness`, declares `requiredTranslations()`, and carries `published()`, `completeIn()`, `availableIn()` and `isAvailableIn()` matching `Product`
- **Tests:** translatable columns are `jsonb`, per locale values round trip, a missing translation reads as `''` and not null, `number` is unique, soft delete keeps the row, `availableIn` excludes a card that is untranslated in the requested language and a card that is unfinished in English
- **Risk:** the availability rule for a card is thinner than a product's, because there are no prices and no artwork here. A card is on the website when it is published and finished in both the original language and the requested one, and that is all. M4 adds artwork, and with it the question of whether a card with no image should be on the website at all. Flagged there, not decided here

### Step 3, cards join the language gate
- One line in `config/translatable.php`. The observer registration and coverage walk pick it up with no other change, which is the point of the design
- Collapse the two places that translate a field name into the client's words into one: `Product::whatIsMissing()` has a `match` over field names and `LocalesTable::outstandingMessage()` has a second copy of the same thing. Cards share every field name with products, so this is deduplication rather than extension, and it stops the two drifting the moment a third model arrives in M6
- **Tests:** a card missing Spanish refuses the publish action and the refusal names the card, saving an untranslated card takes a live Spanish back down and logs a warning, an unpublished card is ignored by the gate, the progress count includes cards
- **Risk:** this is the step that proves the machinery is actually generic. If anything in coverage or the observer turns out to be product shaped, it surfaces here, and I will say so rather than special casing cards around it

### Step 4, seed the 22 Major Arcana
- English names and numbers, 0 The Fool through 21 The World
- Structure is the existence and the number. Everything else is Jennifer's and is written once on first insert, never re-applied, so a later run cannot overwrite her copy. This differs from `ProductSeeder`, which re-applies type and fulfilment every run, because a card has no structural values to correct
- Idempotent on `number`. Running it twice leaves 22 rows
- **Tests:** seeding twice leaves 22 rows and does not overwrite edited copy, numbers are distinct, a seeded card is not on the website in any language until its copy is written
- **Risk:** none of consequence now that names carry no identity. A misspelled name is a value Jennifer can correct herself

### Step 5, the Filament resource, create and edit
- Cards are the one place Jennifer creates rows, per the binding FAQ commitment in `.claude/filament.md`. Create, edit, and soft delete behind a confirmation
- Language tabs from `getTranslatableLocales()` reading the registry when the form is built, never the plugin's own captured list
- The number is required at create. It is unique with no allowed range, and a duplicate says "There is already a card numbered 7" rather than reporting a constraint
- The English name is required at create too, for a different and smaller reason than before: a nameless row is unreadable in the list and it is what the language gate's warnings name when they say what is missing. Nothing else is required field by field, so a half translated card stays saveable
- **There is no web address field**, so nothing in this form is frozen and nothing has to be explained as unchangeable. Renaming a card is now an ordinary edit
- List view: name, number, whether it is on the website, and which languages it is finished in, using the same badge column products already have
- **Tests:** a card can be created through the panel, translations persist per language tab, editing does not disturb another language, a duplicate number is refused in plain language, deletion soft deletes
- **Risk:** requiring the English name at create is the one thing here that could fight the locale switcher, which validates the tab it is leaving against the language it is arriving at. If it does, the name requirement is dropped rather than worked around, and a nameless card shows as "Untitled" in the list

### Step 6, the per-list translation summary
- The widget deferred from M2. A line above each translatable list saying "Spanish: 20 of 22 cards translated", per `.claude/translation.md` section 4
- `TranslationCoverage::progress()` currently counts every translatable model together, which is right for the Languages screen and wrong for a per-list line. Add a per-model variant rather than changing the existing one
- Hidden entirely when only the default language exists, since "English: 22 of 22" tells nobody anything
- **Tests:** the count reflects reality after a translation is added, the line is absent with one language configured, it appears on both the cards and products lists
- **Risk:** none material. This is the smallest step and it can move if a step ahead of it turns out larger than planned

### Step 7, the public endpoints
- `GET /api/v1/{locale}/cards`, every card on the website in that language, ordered by number
- `GET /api/v1/{locale}/cards/random`, one card at random, for the homepage reveal, per decision 4
- `GET /api/v1/{locale}/cards/{card}`, one card by id, 404 for unknown, unpublished, or untranslated in the requested language
- **All three return the identical card shape, long copy included**, per decision 6. The Library is a modal opened from a page that already holds every card, so splitting the copy would buy a smaller payload nobody benefits from and cost a round trip every time a modal opens
- **`random` is registered before `{card}`**, and the id is constrained to digits. Either alone would do, and both together mean the reveal cannot be swallowed by the parameter no matter which is changed later
- **The random response is explicitly uncacheable**, `Cache-Control: no-store`. Every other endpoint here is cacheable and this one is the exception, because a CDN or a browser holding it would show the same card to everyone, which is the one thing the reveal must not do
- The endpoint is stateless. It draws one card and has no idea who is asking, so whether a visitor has already had their reveal stays a cookie on the frontend
- With two cards or fewer available, random is not meaningfully random. That is a content state, not a bug, and it needs no handling beyond returning what exists
- Controller actions take `(string $locale, int $id)`, in path order. Omitting the first receives the language where the id belongs, which is trap two on the checkpoint list
- Filtering happens in the query through `availableIn()`, never after it in PHP, the random draw included. A card that is not on the website must never be the one revealed
- Route model binding is not used, because the bound model would bypass `availableIn()` and answer for a card that is not on the website
- **Tests:** the three responses are identical in shape and a card fetched three ways is the same card, random returns only cards that are on the website in that language, random carries `no-store`, `random` resolves to the reveal rather than to a lookup, a card untranslated in Spanish is absent from the Spanish index and 404s on show while remaining present in English, a draft locale 404s, a preview locale 404s for the public and serves for a signed in admin, unpublished is absent and 404s, ordering is by number
- **Risk:** shape decisions here are a frontend coordination event once the contract is acknowledged. It is unacknowledged today, so this is the cheapest moment to get it wrong. The one shape that must not drift later is `show`, because M4 hangs the signed video URL and the pass check off it

### Step 8, documentation
- **`CLAUDE.md` section 5 currently says the homepage reveal has no backend endpoint.** Decision 4 replaces that, so the line is rewritten rather than left contradicting the code. Per section 0.2 step 5, a changed decision is recorded, not quietly built around
- **`.claude/translation.md` section 9 says cards use a slug frozen from the English title.** Decision 5 replaces that with the id, and the section is rewritten. It is the standing rule set, so leaving it stale is worse than never having written it
- `API_CONTRACT.md`: the three endpoints, their identical shape, that the Library index is safe to cache and carries everything a modal needs, and that the random one must not be cached by them either
- The contract also has to say plainly that the one per session limit is the frontend's, since the endpoint will happily serve a hundred cards to the same visitor
- `.claude/api.md`: the new endpoints alongside the product ones, and why cards are addressed by id while products are addressed by key
- `.claude/database.md`: a cards section covering the number as order, why there is no slug, and why there is no publish switch
- `docs/CHECKPOINT.md`: what M3 shipped, what moved, what M4 inherits, and the two rules this milestone reversed
- `QA_CHECKLIST.md`: creating a card, translating it, and watching Spanish come down as a manual pass

## Decisions, all taken 28 July 2026

The options are kept below with the reasoning, because the reasoning is what a later reader needs. Two of these reverse standing rules, and both are rewritten in step 8: `CLAUDE.md` section 5 on the reveal having no endpoint, and `.claude/translation.md` section 9 on cards using a frozen slug.

### 1. What copy does a card carry
**Answered: B, name, short description and full description**, against the recommendation below. Three required translations, so 66 boxes per language rather than 44, and the field names match products exactly.

The gate counts fields, so every field is 22 more boxes per language, and the required ones decide when a language can go public.

- **A, name and description.** Two required translations. The leanest thing that fills a Library pop-up
- **B, name, short description and full description.** Mirrors products exactly, so one vocabulary across the panel, and it presumes a card has its own page as well as a pop-up
- **C, tarot shaped: name, keywords, upright meaning, reversed meaning.** Real tarot semantics, and it presumes reversals are part of the brand, which nothing in the client context says they are

**Recommendation: A.** The Library is described as informational pop-ups, and a field nobody renders is 22 boxes of content going stale. Adding a field later is a small migration whose only real cost is that it takes non-English languages down until they catch up, and launch is English only, so that cost is currently nil. Starting wide and deleting later is the expensive direction, because it means asking Jennifer to write copy we then throw away.

### 2. Does a card carry its artwork, and if so from when
**Answered B, M4 with the video, then reversed to A on 28 July 2026, after the steps had shipped.** A card must have a picture to be on the website, which makes artwork part of what "finished" means rather than a later addition, and that is not something to leave half true.

What the reversal cost, and what it did not: the disk was configured now instead of in M4, which is work M4 needed anyway for thumbnails, so nothing was paid twice. What it did buy is the credential dependency surfacing now, while nothing is deployed, rather than in six weeks. The picture also moved the availability rule, so every card is off the website until its artwork is uploaded, which is correct and makes an empty staging Library the expected state rather than a fault.

The Library shows the card image. Jennifer supplies all 22, and her commitment is that she can add a future card herself, which she cannot do if a developer has to place its artwork.

- **A, image in M3.** She uploads a still through an ordinary file field, saved synchronously per `CLAUDE.md`. Requires configuring Cloudflare R2 as a disk, which is unbuilt and uncredentialed today, and pulls a piece of infrastructure into a content milestone
- **B, image in M4, with the video.** M4 already owns media, Bunny, thumbnails and the storage disk. M3 ships copy only, and the Library is not renderable until M4 lands
- **C, image in M3 on the local public disk, moved to R2 in M4.** Unblocks the frontend now, and pays for the same work twice

**Recommendation: B, with one caveat I want to name.** The artwork is one upload field once R2 exists, and R2 exists as part of M4's thumbnail work regardless. Splitting it costs a second pass over the same resource. The caveat is that the frontend partner cannot build the real Library from M3 alone, only its text, so if their schedule needs the images sooner then A is right and the R2 setup moves forward with it. That is a scheduling answer more than a technical one.

### 3. Can she create cards beyond the 22, and can she delete one
**Answered: A, unrestricted, with the number unique rather than range checked.**

The FAQ commitment is that she can add future cards without a developer. What a future card is has never been pinned down.

- **A, unrestricted.** She types a number and a name. Nothing enforces 0 to 21, and the Minor Arcana or a bonus card would just work
- **B, the Major Arcana is fixed.** Numbers constrained to 0 to 21, so the deck cannot be malformed by a typo, and anything beyond it is a developer change

**Recommendation: A, with the number simply unique rather than range checked.** B reads safer and quietly breaks the commitment the moment a twenty third card exists, which is the exact promise the panel was built to keep. Soft delete stays available in both, since a card created by mistake needs a way back and nothing references it until M4.

### 4. Does the Library need anything beyond the two card endpoints
**Answered: B, add a random card endpoint**, against the recommendation below, which is recorded because it lost rather than because it was right.

This overturns the line in `CLAUDE.md` section 5 saying the reveal has no backend endpoint, so that line is rewritten in step 8. What the endpoint does not do is remember anyone: the one per session rule stays a cookie on the frontend, and the endpoint will serve the same visitor as many cards as they ask for. The two things that make it work rather than break are in step 7, registering it above the single card route and refusing to let it be cached.

Carried open from the checkpoint.

- **A, two endpoints.** The frontend fetches the index and renders the whole Library from it
- **B, add a random card endpoint** for the homepage reveal

**Recommendation: A.** `CLAUDE.md` puts the homepage reveal client side, gated by cookie or local storage, with no backend endpoint. The frontend already holds the full list and can pick from it, and a server endpoint would need session state the reveal deliberately does not have.

### 5. Is a card addressed by a slug or by its id
**Answered: by its id.** Raised after the plan was drafted, and settled by one fact: a card is a modal opened from the Library page, so its identifier never appears in a browser.

`.claude/translation.md` section 9 currently says cards use a slug frozen from the English title, and it is rewritten in step 8.

- **A, a slug frozen from the English name.** Readable and shareable in a URL, consistent with products being addressed by a stable string key
- **B, the id.** No generation, no freeze, no uniqueness handling, and no partial unique index to stop a soft-deleted card blocking the recreation of one with the same name

**Why B won.** A slug's entire value is being read by a human in an address bar. With a modal there is no address bar, so what remained was machinery paying for a benefit that does not exist, plus one real trap: a slug frozen from a typo'd name is permanent and only a developer can fix it. The cost accepted is that if per-card URLs are ever wanted, adding a slug then is a migration, a backfill from the names, and a contract change. That is real, and it is smaller than carrying the freeze from here to launch for nothing.

### 6. Do the endpoints return the same shape per card
**Answered: yes, all three identical, long copy included.** Raised after the plan was drafted.

Products split their copy so a listing does not carry four long descriptions, and that logic does not transfer: readings have their own pages, so the second request happens anyway, while a card modal opens from a page that already holds every card.

- **A, index carries everything, `show` stays and returns the same shape.** One request renders the whole Library, and every modal opens with no round trip
- **B, index carries everything and `show` is dropped.** Marginally less code, and M4 rebuilds it under another name for the video

**Why A.** The whole Library is roughly 25KB of text before compression, against an audit that found 230MB of video loading on arrival, so the payload argument does not survive contact with the numbers. B loses because the endpoint deleted now is the one rebuilt in M4 with a signed video URL and a pass check attached, and the frontend would have to move to it. What must not change is that **signed video URLs never enter the index** when M4 arrives: they are short lived, they are what the old site got wrong, and 22 of them in an edge-cached response is a leak and a caching bug at once.

## Dependencies

- **You:** approval of this plan. The six decisions are taken and nothing else is blocked on you
- **Jennifer:** card copy, and the confirmed English names if any differ from the standard Major Arcana titles. Not blocking, since the seeder places the names and the copy boxes start empty, but blocking anything on staging looking finished
- **Frontend partner:** the card shapes in step 7, and still no acknowledged contract. Two things they need told rather than left to find out. The Library has no artwork until M4, so what they can build from this milestone is its text. And the one per session limit on the reveal is theirs, because the endpoint does not remember anyone
- **Carried, not blocking:** no deploy has happened, Laravel Cloud is unmeasured for cold start, and R2 credentials do not exist. Deciding artwork into M4 keeps all three off this milestone's path

## Verification

- `migrate:fresh --seed` clean, `cards` present with three `jsonb` copy columns, a unique number, and 22 rows
- Full suite green, including the denial paths: a card untranslated in Spanish absent from the Spanish index, a draft locale 404, a preview locale served only to an admin, and the random draw never returning a card that is not on the website
- Against the API: the index carries everything a modal renders, `show` and `random` return the same shape, `/cards/random` returns a different card across repeated calls and carries `no-store`
- Through the panel: create a card, rename it, fill the Spanish tab, and see the summary line move
- With Spanish forced live in a test: publish a new card in English only and confirm Spanish is taken down, logged, and reappears once translated
