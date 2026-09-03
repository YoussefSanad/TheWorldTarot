# Milestone 4, the Living Tarot and the Viewing Room

> **Shipped 30 July 2026.** Written and approved 29 July. The close-out is immediately below.
> Everything after it is the plan as approved, kept as written so the deviations mean something.
>
> **Corrected 3 August 2026. Part of this close-out was wrong.** Playback never worked, in any
> environment, from the day this shipped until 3 August. The correction is the first thing in the
> close-out and the original claim is left in place, struck, rather than quietly deleted.

---

## Correction, 3 August 2026

**This milestone was closed on a claim that was not true.** The close-out below says Bunny accepted our signed playback URLs and that a tampered token was refused, and calls it the one claim no test could make. It could not be made, and it was made anyway.

What was actually true for the five days after this shipped:

- **Every signed playback URL returned 403.** The Viewing Room served nothing to anyone, pass or no pass
- The signing method carried a docblock saying the scheme "has never been proved against a real library" and predicting that a 403 with a future expiry meant the fault was there. That prediction was correct, and this close-out overwrote its conclusion without rerunning it
- **The test suite could not see it.** `BunnyStreamTest` asserted the URL contained the token our own code had produced, so it agreed with the implementation rather than with Bunny and stayed green throughout

The most likely way it passed review: the iframe embed player plays with no token at all, so a video that played in a browser looked like proof while the direct signed URL was never fetched.

Found on 3 August while wiring the homepage draw, which shares the same signing method. Fixed the same day, and this time proved: master manifest, rendition and segment all 200 from the live zone, with the working vector pinned as a known-answer test. Full account in `docs/plans/bunny-playback-fix-plan.md`.

**What the fix changed that this milestone had decided otherwise:**

- **Playback URLs are now bound to the viewer's IP.** This close-out and the plan below both record IP binding as offered and declined, in favour of never interrupting a viewer who changes network mid-film. The zone had it switched on the whole time, which is part of why nothing worked, and on 3 August the decision was reversed deliberately: the draw now serves full films publicly, which raises the value of a token that only works for one person. A viewer moving from wifi to mobile data mid-film will be cut off, and that is accepted
- **A playback URL cannot be opened in a browser tab.** The zone refuses requests with no referrer, so these only work from inside a page. Support cannot debug by clicking a link

### The lesson worth keeping

**A test that asserts an external service's format against your own implementation of it proves nothing.** It is not a weak test, it is a test of the wrong thing, and it will stay green through total failure. Anything only the other side can confirm needs a vector captured from the other side. Now in `.claude/testing.md`.

---

## Close-out, 30 July 2026

**All ten steps shipped, plus two things the plan did not name.** 419 tests, 951 assertions, green. Running on staging, where the whole chain has been exercised with real files and a real transcode.

### Proved on staging rather than assumed

- A 40MB upload clears the platform's request limit, which was the plan's largest unknown. The upload does not need to move to a direct signed upload
- The job reaches Bunny and the video appears in the library named after the card
- The transcode callback flips a card to ready with nobody touching anything
- ~~**Bunny accepts our signed playback URLs**, and a tampered token is refused. That was the one claim in the milestone no test could make~~ **False. See the correction above.** Every signed URL 403d until 3 August 2026

### What deviated, and why

| Deviation | Why |
|---|---|
| **Step 3's model was wrong and was rebuilt in step 5** | Being watchable depended on the video status, so uploading a replacement would have emptied the card while Bunny transcoded, breaking a decision that had already been taken. The live video and the one being prepared are now separate columns |
| **A daily reconciliation sweep, not in the plan** | The webhook is fast and unreliable. A lost callback would strand a card silently, and one stuck card is a twenty-second of the Viewing Room missing. Made daily rather than five-minutely because uploads are rare |
| **`in_viewing_room` and `poster` added to the Library** | The Viewing Room could not be built from the API at all: no poster, and no way to tell which cards were filmed without asking for every video and reading the refusals. Found while writing the documentation, which is late but not too late |
| **The second bucket needed code after all** | The plan said attaching one should need none. The platform names disks after the bucket, and those names carry the environment in them, so the private bucket is found by not being the default one |
| **Product structure moved into the catalogue** | Seeding the pass meant the seeder needed to know what a pass is. Type, fulfilment and whether a question can be attached are facts about a product, so `ProductKey` states them and the seeder only makes rows |
| **The test suite sets its own memory limit** | Four hundred Filament tests do not fit in PHP's default, and the suite passed on CI while dying on a laptop |

### Standing rules this milestone reversed

- `CLAUDE.md` section 4, which described saving the upload and forwarding it from there. That cannot work here: managed queue workers are a different container, so a file written beside the request is not there when the job runs

### Faults found in my own tests, not in the code

Recorded because each one produced a green test that proved nothing.

- The queue runs inline in tests, so uploading a master reached Bunny during setup
- `Http::assertSent` cannot read a body from a stream the job has already closed, and closing it is correct
- `expectsOutputToContain` consumes the line it matches, so a second expectation against the same line always fails
- A test asserted "nothing in this environment names a bucket" by hoping nothing did. It passed locally and failed on CI
- A denial-path case used the state for a missing picture while claiming to cover a missing video

### Still owed

- **No manual QA pass has been walked**, for M3 or M4. Both have sections in `QA_CHECKLIST.md`
- **Production is behind staging.** None of the video work is deployed there
- **The contract is still unsent**, and now carries the strictest handling rules in the API
- **Jennifer owes** 22 pictures, 22 videos, 22 poster frames, and copy for five products including the pass
- **The scheduler's tick cadence is unverified**, and the cold start is still unmeasured
- **Playback itself, which was the thing this close-out wrongly claimed.** Fixed 3 August 2026, still unproved on staging because production and staging are both behind. See the correction above

---

## Current state, read from the code as it is

- **No video code of any kind.** No Bunny block in `config/services.php`, no job, no webhook route, no status column
- **Cards exist and carry a picture.** M3 shipped the table, the panel Jennifer creates rows in, the language gate, and three public endpoints. A card is on the website when it is published, pictured and written
- **`ProductKey::ViewingRoomPass` exists and is unseeded.** The enum declares the whole catalogue, the seeder creates readings only
- **`Customer` has no access stamp.** No column, no gate, and no member login until M5
- **Storage works and is understood.** The platform publishes attached buckets as a blob naming the disk each replaces, and `config/filesystems.php` applies it. One public bucket is attached today
- **Production and staging both exist**, as of 29 July 2026. Queues are managed, so workers run in a container of their own
- 309 tests, 695 assertions, green

## Goal

Give every card its Living Tarot video, ingested through the same panel Jennifer already uses, and put those videos behind a paywall that actually holds. She uploads a file and is told immediately that it is processing; the work happens on a queue; Bunny transcodes and calls back; the card flips to ready. A visitor with no pass gets nothing, a visitor with one gets a playback URL that expires, and no video is reachable by guessing a URL at any point in the chain. That last sentence is the audit finding this whole project exists to fix.

## Explicitly out of scope

| Not here | Owned by |
|---|---|
| Buying the pass, checkout, payment, and **setting** the access stamp | M5. This milestone creates the stamp and reads it |
| Member login, registration, password reset | M5. Tests here use seeded customers and tokens |
| The 24 hour rush add-on | M5 |
| FAQ, legal, marketing copy | M6 |
| AI fulfilment | M7 |
| The Viewing Room's layout, player skin, and playlist behaviour | Frontend repo |
| Analytics on what gets watched | M8, if at all |

## Steps

Each step is one commit, tests in the same commit, reported before the next begins.

### Step 1, this plan
No code.

### Step 2, the private bucket
- A second bucket, attached in the dashboard, private, naming its own disk. The blob is a list, so this should need no code at all beyond a name to read it by
- `filesystems.uploads` alongside `filesystems.media`, so nothing guesses which disk a file belongs on
- `media:check` learns to check both, and to fail loudly if the private one is publicly readable
- **Tests:** the blob applies to both disks, the private disk is not the public one, `media:check` fails when the private disk is missing
- **Risk:** the platform may not allow two buckets, or may not let one be private. If so, this step reports that rather than working around it, because the alternatives change the design

### Step 3, video columns on cards
- `bunny_video_id`, `video_status`, `video_uploaded_at`, `poster_path`, and the master's path on the private disk
- `video_status` is a real column driving real behaviour, per `.claude/database.md`: `pending`, `processing`, `ready`, `failed`. A string column with a PHP enum cast
- **A card is in the Viewing Room when its video is ready and it has a poster.** Calculated like everything else, never switched on
- Being in the Viewing Room is independent of being in the Library. A card with copy and a picture but no video is public and not viewable
- **Tests:** the states cast both ways, a card with no video is not viewable, one still processing is not viewable, one ready and postered is, and none of this changes whether it is in the Library
- **Risk:** none material. This is the smallest step and everything after it depends on the vocabulary being right

### Step 4, the Bunny client
- `config/services.php` gains a bunny block: library id, API key, token authentication key, CDN hostname. Per environment, because staging and production get separate libraries
- A `BunnyStream` service with four methods: create a video, upload its bytes, fetch its status, and sign a playback URL. Nothing else talks to Bunny
- **Tests:** every method against `Http::fake()`, including a rejected upload and a failure response. **Never a real request**, per `.claude/testing.md`
- **Risk:** Bunny's API shape is documented but unverified by us. If the client turns out to need a fifth method, that is a small change; if it needs a different upload protocol, the ingestion step changes with it

### Step 5, the upload panel
- Two fields on the card: the video, and the poster frame, both to the private disk. **Both live on the original language tab only**, since a file field on a translation tab is emptied by the language switcher, which cost us a picture in M3
- Saving dispatches the ingestion job and returns immediately. She sees "processing" and is never left on a spinner
- Status is visible in the list at a glance, per `.claude/filament.md`, with plain wording: waiting, processing, ready, something went wrong
- **Tests:** the upload lands on the private disk, the job is dispatched with `Queue::fake()`, status shows in the list, the fields are absent on a translation tab
- **Risk:** the platform's request size limit is unverified against a 40MB file. If it refuses, the upload moves to a direct signed upload and this step grows

### Step 6, the ingestion job
- **Creates the Bunny video first, stores its id, then uploads the bytes.** Managed queues are at-least-once and a job slower than the visibility timeout is redelivered while still running, so a retry has to reuse the same video rather than making another
- Reads the master from the private bucket, never from a local path. The worker is a different container from the web application, and a file on the web container's disk is not there when the job runs
- Marks the card failed, visibly, when Bunny refuses. A silent failure looks identical to a slow transcode
- **Tests:** dispatch, then the effect separately, per `.claude/testing.md`. Running the job twice produces one Bunny video, a failure marks the card and does not throw away the master, and the master survives so a re-run is possible
- **Risk:** this is the step where the queue's at-least-once delivery stops being theoretical. If the upload takes longer than the visibility timeout, the same job runs twice concurrently, and the create-then-upload order is what makes that survivable rather than expensive

### Step 7, the transcode webhook
- `POST /api/v1/webhooks/bunny`, outside the locale prefix, unauthenticated by necessity and untrusted by design
- **The payload is a hint, not data.** On receipt, ask Bunny for that video's real status and act on the answer, so a forged callback achieves nothing whatever Bunny's signing scheme turns out to be
- Idempotent: the same callback twice leaves the card ready once, and a callback for a video we do not know about is a 200 and a log line, not an error
- Rate limited, since it is a public endpoint that triggers an outbound API call
- **Tests:** a real callback flips the card to ready, a forged one changes nothing because Bunny disagrees, a replayed one is harmless, an unknown video id is handled, and a malformed body does not 500
- **Risk:** local development needs a public URL for Bunny to reach, so Cloudflare Tunnel is required from this step onward, as `CLAUDE.md` section 6 anticipated

### Step 8, the pass and the stamp
- Seed the viewing room pass product row, the key that has been in the enum since M2
- `viewing_room_access_at` on `customers`, nullable, a timestamp rather than a boolean so support can see when it was granted
- Nothing sets it in this milestone. M5 does, at payment
- **Tests:** the seeder is idempotent and does not overwrite her copy or price, the stamp defaults to null, a stamped customer reads as having access
- **Risk:** none. The row is the same shape as four that already exist

### Step 9, the playback endpoint and the gate
- `GET /api/v1/{locale}/cards/{id}/video`, behind `auth:sanctum`, returning a signed, expiring playback URL
- **401 unauthenticated, 403 signed in without a pass**, since the Viewing Room is advertised and its existence is not a secret. 404 only for a card with no ready video
- Signed URLs expire after two hours and are not IP bound, per the answers below
- **The frontend must never cache or store one.** It is a credential with a timer, and putting it in local storage or a service worker turns a two hour exposure into a permanent one. This goes in the contract
- **Tests:** the denial paths with the same care as the success path, per `.claude/testing.md`: no token, a token with no pass, a pass and a card whose video is not ready, an expired signature, and a URL with its signature removed. Both cookie and token auth, since every protected route must work under both
- **Risk:** the highest-value code in the milestone. If anything here is wrong, the paywall is decorative, which is precisely what the audit found on the old site

### Step 10, documentation
- `API_CONTRACT.md`: the playback endpoint, the shape, the expiry, and that the URL must never be cached or stored by the frontend
- `.claude/api.md`, `.claude/database.md`, `.claude/filament.md`: the video columns, the webhook rule, the two disks
- `CLAUDE.md`: correct section 4's ingestion description, which describes saving locally and forwarding from there. That does not work on this platform
- `docs/CHECKPOINT.md` and `QA_CHECKLIST.md`

## Decisions taken before drafting, 29 July 2026

**Video delivery is Bunny Stream.** The alternative considered was plain MP4 files in a private bucket with presigned URLs, which would have deleted the transcode webhook, the status column and the ingestion job entirely. Bunny wins on adaptive bitrate: a phone on a poor connection gets a smaller rendition instead of stalling on a 40MB progressive download. Settled by you rather than by measuring a real file, which was the alternative route to the same answer.

**The upload lands in a private bucket and the master is kept.** About 900MB for the whole deck, and it is the only copy that is not on Jennifer's laptop or inside a vendor. Re-encoding, or leaving Bunny, stays possible.

**The poster is a separate upload per card**, not the Library picture and not a frame Bunny generates. It costs her 22 more uploads and a second file field, and it buys deliberate control over the frame the player rests on.

**The webhook is verified by asking Bunny**, not by checking a signature. Immune to a forged payload whatever the signing scheme turns out to be, and naturally idempotent.

**The gate ships in M4**, ahead of the login that would make it reachable. The denial path is the most important thing in this milestone and the thing the audit found broken, so it is not shipping under M5's payment pressure.

## Answered, 29 July 2026

1. **A playback URL lives two hours**, and is not bound to the viewer's IP. Long enough that nobody is interrupted mid-video, including on a phone that leaves wifi for mobile data, which IP binding would have cut off

2. **Downloading is not prevented, and that is understood.** Worth stating plainly here because it is easy to assume otherwise: signing stops people without a key, not the person holding one. A pass holder can save what they are watching, and a URL copied out of developer tools works from anywhere until it expires. What signing does fix completely is the audit's actual finding, a video anyone could fetch by guessing a URL with no account and no payment.

   The accepted exposure is therefore one paying customer sharing a working link for up to two hours. IP binding and a fifteen minute token were both offered and declined in favour of never interrupting a viewer. DRM was considered and rejected: it raises the effort and never makes copying impossible, and these videos are the experience rather than the product.

3. **Replacing a video never leaves a gap.** The card returns to processing, the existing video keeps playing, and the new one takes over only when Bunny says it is ready. A failed replacement leaves the old video serving and the card marked failed, so nothing disappears from the Viewing Room because an upload went wrong

## Dependencies

- **You:** approval of this plan. The three questions are answered
- **The dashboard, partly done.** Bunny is set up and the staging library's API key is in the local `.env` as `BUNNY_API_KEY`. Still needed: the library id, the token authentication key from that library's settings, and its CDN hostname, all three per environment. And the second bucket, private, attached to both environments, which step 2 cannot start without
- **Local development:** Cloudflare Tunnel from step 7, so Bunny can reach a callback
- **Jennifer:** the 22 videos and 22 poster frames. Nothing blocks the build, but the Viewing Room is empty without them, and she still owes the card pictures from M3
- **Frontend partner:** still no acknowledged contract, and this milestone adds the endpoint with the strictest handling rules in the whole API

## Verification

- `migrate:fresh --seed` clean, with the pass row present and the video columns on cards
- Full suite green, with particular attention to the five denial paths in step 9
- Through the panel: upload a video, see processing immediately, watch it become ready without refreshing anything by hand, and see a failure reported as a failure
- Against the API: a signed URL plays, the same URL with its signature stripped does not, and an expired one does not
- On staging: a real Bunny callback reaches a real environment, which is the thing that has never been tested anywhere
