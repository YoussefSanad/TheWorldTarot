# Fixing Bunny playback

> **Fixed 3 August 2026. Steps 1 to 4 and 6 done, step 5 waiting on a deploy.**
> A defect in M4, found while wiring the homepage draw. Playback had never worked in any environment.
> Companion to `docs/plans/BunnyIntegraiton.md`, which is the pre-M4 architecture note and was right about the things that mattered here.

---

## Close-out, 3 August 2026

**Playback works, proved rather than assumed:** master manifest, rendition and segment all 200 from the live zone, reached through `/api/v1/en/cards/draw` rather than by hand. 433 tests, 1009 assertions, green.

### What it actually was

Not one fault but three, each producing an identical 403:

1. **The token was scoped to the manifest, not the video's directory.** HLS resolves renditions and segments relative to `playlist.m3u8`, so even a correct file-scoped token loads the manifest and refuses everything it points at
2. **The viewer's IP was missing from the hash**, because the zone has `ZoneSecurityIncludeHashRemoteIP` on, which nothing in the code or the notes knew
3. **`token_path` has to appear twice, encoded differently.** Raw inside the hash, percent-encoded in the query

### What was not wrong, despite several confident claims that it was

- **The secret.** The zone's `ZoneSecurityKey` is byte-identical to the library's token key. Six rounds were spent asking for a different key that does not exist, because both dashboard screens label their field "Token Authentication Key"
- **The algorithm family.** SHA256 of a concatenated string was right all along. 240 orderings and encodings were tried against the live zone before the IP turned out to be the missing ingredient, and none of them could ever have worked without it

Getting the zone's configuration required an account-level API key, which is the only credential that can read it. That, not another guess, is what ended this.

### Decisions this changed

- **IP binding is now on**, reversing M4's explicit decision to decline it. Taken deliberately: the draw serves full films publicly, so a token that works for one person is worth more than the interruption it costs a viewer changing network
- **`BlockNoneReferrer` stays on for now**, so a playback URL cannot be opened in a browser tab and a privacy-mode browser that strips referrers gets an unexplained 403. Still open

### Proved on staging, 3 August 2026

Deployed at `f2aea7a`, exercised against the real zone from a real address:

- `/draw` returns a card and a film, and the film plays: master manifest, rendition and segment all 200
- **The app sees the viewer's real address in a deployed environment**, not Laravel Cloud's load balancer. This was the open risk in the whole design, since `ViewerIp` falls back to `Request::ip()` when Cloudflare's header is absent, and every token would then be signed for the proxy and refuse everybody. Cloudflare's header arrives. Nothing needs trusted proxies
- The referrer requirement is real and enforced: the same URL is 200 with a referrer and 403 without
- Embed token authentication was switched on the same day and verified closed, by page body rather than status code, since Bunny answers 200 and renders a forbidden page. No token, a wrong token and an expired token all give the forbidden page; a correct one gives the player

### Still owed

- **The Viewing Room path on staging.** Only the public draw has been exercised there. The gated endpoint needs a pass holder token minted by hand, and its denial paths, 401, 403 and 404, have never been walked against a deployed environment
- **A decision on `BlockNoneReferrer`**
- **Embed token authentication is still off, and this is now a real hole rather than a theoretical one.** Anyone holding a video guid can watch that film through `iframe.mediadelivery.net/embed/{library}/{guid}` with no token, no expiry and no IP binding, and share it freely. The two hour life and the IP binding apply to the CDN surface only, and the embed surface ignores both.

  **The draw made this live.** Its public response carries the guid inside the playback URL, so every anonymous homepage visitor now receives one. That is exactly the "one careless response shape away" case listed as open question 3 below, and it was answered by shipping the draw. The only thing still limiting it is the library id, which we publish nowhere and which is not a secret either.

  **The fix is one toggle in the library's Security tab**, and it costs nothing because nothing we build uses embed URLs. Do this before staging.

---

---

## What is actually true today, measured rather than assumed

Every fact below was checked against the real library on 3 August 2026, not inferred.

- **No signed URL this application produces plays.** Direct CDN URLs return 403 while their expiry is hours in the future
- **Direct CDN access is blocked even with no token at all.** An unsigned `playlist.m3u8` is also 403, so something on that surface refuses anonymous access
- **It is not a referrer restriction.** 403 with no referrer, and with the production, staging and localhost origins
- **It is not the hash input.** Nine schemes were tried against the real library: hex and base64url, plain hash and HMAC, video guid and URL path, query-string and path-based `bcdn_token`. All 403
- **Embed playback is wide open.** `iframe.mediadelivery.net/embed/716292/{guid}` returns 200 **with no token at all**. Anyone holding a guid can watch, permanently
- **The library holds two real videos**, both status 4, both titled `0. The Fool`, left over from M4's staging work
- **The test suite cannot see any of this.** `BunnyStreamTest` asserts the URL contains the token our own code produced. It agrees with the implementation rather than with Bunny, which is why it stayed green throughout

## What the architecture note gets right, and we got wrong

`BunnyIntegraiton.md` line 109 says the string-to-sign format "has changed between versions, don't hand-roll it from a remembered example without checking current docs first."

That is exactly what happened. `BunnyStream::playbackUrl()` carries a docblock admitting the scheme "has never been proved against a real library" and predicting that a 403 with a future expiry means the fault is in that method. The prediction was correct and the M4 close-out overwrote its conclusion without rerunning it.

Two further corrections the note forces:

- **The Token Authentication Key lives on the library's Security tab**, per line 62, and "must be enabled and retrieved." Not on the pull zone. Line 56 warns explicitly against editing the pull zone through Manage, since it can break Stream. Advice given on 3 August to go and fetch a pull zone security key was wrong and is withdrawn
- **The public reveal serving a full card animation was always the intent.** Line 23 describes the homepage reveal as public, unauthenticated and session-limited, and line 32 says the security budget "is intentionally loose here, this content is meant to be revealable without a purchase." The decision taken on 2 August restated an existing position rather than conceding a paid asset, and the wording in `CLAUDE.md` and `docs/CHECKPOINT.md` should be corrected to say so

## What the architecture note is now stale about

Recorded so nobody implements from it by mistake. The code is right and the note is old.

| Note says | Built as | Why |
|---|---|---|
| Verify the webhook signature, like Stripe | Ask Bunny what the real status is | Immune to a forged payload whatever the signing scheme is, and idempotent for free. Better than the note, keep it |
| `slug` on cards, with slug history | The card's id | A card is a pop-up, not a page. Reversed in M3 |
| `status` | `video_status`, plus separate live and incoming video columns | A replacement must not empty the card while it transcodes |
| `thumbnail_path` | `poster_path` | Naming only |
| "Gallery Pass" | The viewing room pass, and `viewing_room_access_at` | Naming only |

## Goal

Playback works, proved against the real library rather than against ourselves, on both surfaces that need it: the Viewing Room's gated player and the homepage draw's bare video element. The signing method is verified before it is trusted, the tests can no longer agree with the implementation, and the record says what actually happened in M4.

## Explicitly out of scope

| Not here | Owned by |
|---|---|
| Changing video delivery away from Bunny | A separate decision, if you want it, with a measurement behind it |
| The draw's pool, resource shape, rate limit, or the `RevealVideoSource` seam | Already built and correct. Only what `playbackFor()` returns changes |
| Buying the pass, member accounts | M5 |
| The player skin, the thumbnail rail, the reveal animation | Frontend repo |

## Steps

### Step 1, the dashboard truth pass. No code, and I cannot do this one

This is the step `BunnyIntegraiton.md` prescribes under "Dashboard level, before touching Laravel" and it has never been walked. It isolates configuration from code, and until it is done every code change is a guess.

In the Bunny dashboard, on library `716292`:

1. **Security tab.** Is Token Authentication enabled? Turn it on. Copy the Token Authentication Key
2. **Find out which playback surfaces the library permits at all.** Embed, direct HLS, MP4 fallback. Something is refusing anonymous direct access and we do not yet know what, which is the single most important unknown here
3. **Construct one signed URL by hand**, from Bunny's current documentation, for video `295593e0-b166-4f90-aded-eff7d9ba794c`. Confirm it plays in a browser
4. **Keep that URL.** It becomes the fixture in step 4

**Output:** a known-good URL, the key that signed it, and the exact string that was hashed. Nothing after this step can start without those three.

**Risk:** the dashboard may show that direct HLS is not available on this library's plan or configuration, in which case step 2 has only one answer and the frontend contract changes.

### Step 2, decide the delivery surface

Driven by what step 1 finds, and a real decision rather than an implementation detail, so it comes to you with options.

The two consumers want different things. The Viewing Room can live with a hosted player. The draw cannot: `RevealStage.tsx` crossfades a bare `<video>` into the card face, and an iframe with third-party chrome is not that. `BunnyIntegraiton.md` line 113 already flags HLS versus MP4 as needing the frontend partner's answer.

**Output:** one decision, or two if the surfaces genuinely diverge. The `RevealVideoSource` interface already allows them to.

### Step 3, rewrite the signing helper

- `playbackUrl()` rebuilt against the scheme step 1 proved, not against documentation read second-hand
- Config keys named for the layer each one signs, since holding two secrets called almost the same thing is how this went wrong. The existing `BUNNY_TOKEN_KEY` gets a comment saying which surface it is for
- If the surfaces diverged in step 2, a second method rather than a flag, so neither can be quietly used for the other
- **Tests:** the known-good fixture from step 1 reproduced exactly, plus the existing shape assertions
- **Risk:** low, once step 1 has produced a fixture. This is transcription at that point

### Step 4, make the tests capable of failing

The current tests are the reason this shipped. They assert our output against our own algorithm.

- Pin the step 1 fixture as a **known-answer test**: this key, this video, this expiry, this exact token string. It fails the moment the algorithm drifts from what Bunny accepted
- A comment saying in plain words why a self-consistent assertion is worthless here, so nobody helpfully replaces it later
- **Tests:** this step is the tests
- **Risk:** a fixture pins one moment. If Bunny changes their scheme it goes red, correctly, and that is the point

### Step 5, prove it on staging

- Both endpoints, both auth states, against a real environment: a pass holder plays a film, a stripped signature is refused, an expired one is refused, the draw returns something that plays for an anonymous visitor
- **If the surface is HLS, watch a segment load, not just the manifest.** A playlist that loads and then stalls on every segment looks identical to success from `curl`
- `QA_CHECKLIST.md` gains these as permanent manual checks, since no automated test can answer them

### Step 6, correct the record

- `docs/plans/milestone-4-plan.md`: the close-out claims signed playback was proved on staging and a tampered token refused. It was not. Say so, and say what was probably tested instead, which is the embed player that plays with no token
- `docs/CHECKPOINT.md`: M4 moves from complete to complete-with-a-known-defect until step 5 passes
- `CLAUDE.md` and `docs/CHECKPOINT.md`: reword the draw decision to say the public reveal was the documented intent from the start, per `BunnyIntegraiton.md` line 32
- The traps list gains the one that cost the most: **a test that asserts an external service's format against your own implementation of it proves nothing**

## Decisions needed from you

1. **Step 2's surface**, once step 1 reports. Cannot be decided before then
2. **What happens to the draw work sitting uncommitted.** It is finished and green, and its payload is a URL that does not play. Commit it now and fix playback underneath it, or hold it until playback works
3. ~~**Whether embed token authentication being off is a separate urgent item.**~~ **Answered by shipping the draw, on 3 August 2026.** It is urgent. The draw publishes video guids to anonymous visitors, which is the "one careless response shape" this question anticipated. Turn embed token authentication on before anything deploys

## Dependencies

- **You, for step 1.** I have no dashboard access, and every subsequent step is blocked on its output
- **Frontend partner**, for step 2 if the answer is HLS versus MP4. Still no acknowledged contract, and this is now the second question waiting on them
- **Nothing from Jennifer.** The two test videos already in the library are enough to prove all of this

## Verification

- The known-good URL from step 1 still plays after being regenerated by our code, which is the whole point
- A stripped signature and an expired signature both refused, checked by hand against the real CDN
- A segment loads, not only the manifest
- Full suite green, with the known-answer test in it
- The draw returns a URL an anonymous visitor can play, and the Viewing Room refuses everyone without a pass
