# The homepage reveal gets a video

> **Shipped 3 August 2026.** Written and revised 2 August. The close-out is immediately below.
> Everything after it is the plan as approved, kept as written so the deviations mean something.

---

## Close-out, 3 August 2026

**All four steps shipped.** 433 tests, 1009 assertions, green. `GET /api/v1/{locale}/cards/draw` returns a card and a signed film, verified end to end against the live Bunny zone: master manifest, rendition and segment all 200.

### What deviated, and why

| Deviation | Why |
|---|---|
| **Renamed from `/cards/random` to `/cards/draw`** | Asked for on 3 August. Free that day because the contract was unsent and the frontend consumed nothing, a coordination event a week later |
| **It uncovered that playback had never worked** | The draw shares its signing with the Viewing Room, so building it meant looking at a URL nobody had ever successfully fetched. M4 had closed claiming the opposite. Fixed the same day, see `docs/plans/bunny-playback-fix-plan.md` |
| **`playbackFor()` takes the viewer's IP** | Not in the plan, because nobody knew tokens were IP bound. The interface carries it explicitly rather than digging it out of the request, so the decision is visible at every call site |
| **Step 4's documentation was folded into the rename** | The rename touched all four documentation targets anyway, so they were finished in one pass rather than edited twice |

### Corrected after the fact

The plan and the first commits describe this decision as knowingly giving away a paid asset. **That overstated it.** `docs/plans/BunnyIntegraiton.md` already described the reveal as public and session-limited, and said the content "is meant to be revealable without a purchase". The 2 August decision restated an existing position. The consequence is still worth having in writing, and the framing has been corrected in `CLAUDE.md` and `docs/CHECKPOINT.md`.

### Still owed

- **Staging.** Nothing here has run in a deployed environment
- **The frontend**, which still plays a bundled placeholder. Planned in the frontend repo at `docs/plans/reveal-api-migration.md`

---

---

## Current state, read from the code as it is

- **`GET /api/v1/{locale}/cards/random` returns a card and no video.** It draws from `Card::availableIn()`, which is published, written and pictured, and carries `Cache-Control: no-store` so a CDN cannot reveal the same card to everybody
- **The frontend reveal plays no card video today.** `RevealStage.tsx` crossfades a bundled looping back-of-card animation into a still card face, from a hardcoded content file. Nothing there talks to this API yet
- **`Card::viewableIn()` already exists** and means published, written, pictured, with a ready video and a poster. M4 built it for the Viewing Room and it is the pool this endpoint needs
- **`BunnyStream::playbackUrl()` already exists and is proved on staging**, including that a tampered token is refused. Signing is not new work
- **`/draw` has no rate limit.** It is a plain public GET today, which is correct for something that returns copy and a picture
- **`CardResource` is shared** by the listing, the single card and the reveal, which is the contract's promise that one component renders a card wherever it came from
- 419 tests, 951 assertions, green

## Goal

The homepage reveal plays the card it drew. `/draw` picks a card that has a watchable film, and returns a signed, expiring playback URL alongside the card it already returns, so one request produces a complete reveal. Which video that is stays a decision behind an interface, so replacing the full film with a preview or a purpose-made clip later is a swap of one class and a config line, not a change to the endpoint, the response shape or the frontend.

## The decision this rests on, recorded plainly

**The reveal serves the full Living Tarot film, publicly and unauthenticated.** Decided 2 August 2026, after the alternatives were put and the consequence was stated.

The consequence, in writing, because nobody should rediscover it later as a surprise:

- The Living Tarot films are the Viewing Room's paid product. Serving one from a public endpoint gives it away
- The one reveal per visitor limit lives in a frontend cookie by an earlier decision, so **there is no server-side limit**. A visitor who clears a cookie draws again
- 22 requests collect the deck. Rate limiting slows a script and does not prevent collection, and this plan does not claim otherwise
- This is the same class of finding as the audit that started the engagement, arrived at deliberately rather than by accident, which is the whole difference

**The mitigation that is real:** the URL is signed and short lived, so no film is reachable by guessing an address, and a collected link stops working. That was the audit's actual finding and it stays fixed.

**The interface is the point.** The full film is the answer for now. The seam is built so the answer can change without the endpoint changing.

## Explicitly out of scope

| Not here | Owned by |
|---|---|
| The reveal's animation, the player, the one per visitor cookie | Frontend repo |
| Buying the pass, member accounts, the access stamp being written | M5 |
| A separate preview or purpose-made reveal clip, and any second ingestion path | A later swap of the implementation, if commissioned |
| Changing the Viewing Room endpoint, its pass check, or its two hour expiry | Nothing here touches it |
| Serving video anywhere else public | Nothing. This is the only public video endpoint |

## The shape

**`App\Contracts\RevealVideoSource`**, two methods, because a source decides both what it can serve and how to serve it:

- `eligible(Builder $query): Builder` narrows a card query to what this source can play. The full film source adds the ready video and poster conditions. A future clip source would ask about its own column instead
- `playbackFor(Card $card): ?RevealPlayback` returns a URL and an expiry, or null when it cannot produce one

**`App\Services\Reveal\FullFilmRevealVideo`** is the only implementation, signing `bunny_video_id` through the existing `BunnyStream`.

**Bound from config**, `config/reveal.php`, one key naming the class. Structure declared in code and arriving by deploy, per `CLAUDE.md` section 4. Changing the reveal's source is a one line diff and a deploy, with no migration and no endpoint change.

**Its own expiry key, `reveal_ttl`, set to the same two hours the Viewing Room uses.** Decided 2 August 2026. A second key holding the same number rather than reading `playback_ttl` directly, because they are the same figure today and not the same decision: shortening the public reveal later must not reach into what a paying viewer gets, and lengthening the Viewing Room must not lengthen a public credential. One env var apart, with no code change and no way to move one by touching the other.

**A separate resource, `RevealCardResource`**, wrapping the existing card shape and adding a `video` block. `CardResource` is left untouched on purpose: the Library listing carries every card and is meant to be cached, and a credential must be structurally incapable of reaching it rather than kept out by discipline.

## Steps

Each step is one commit, tests in the same commit, reported before the next begins.

### Step 1, this plan
No code.

### Step 2, the interface and the full film source
- `RevealVideoSource` contract, a `RevealPlayback` value object holding a URL and an expiry, and `FullFilmRevealVideo`
- `config/reveal.php` naming the source, bound in a service provider
- `services.bunny.reveal_ttl`, defaulting to 7200, documented in the config the way `playback_ttl` is, including why it is a second key holding the same number
- **Nothing wired to the endpoint yet.** The endpoint still returns what it returns today
- **Tests:** the source narrows a query to cards with a ready video and a poster and excludes ones without either, it signs through `BunnyStream` against `Http::fake()` and never a real request, the expiry comes from `reveal_ttl` and is proved by moving that key alone and leaving `playback_ttl` where it is, a card with no video yields null, and the container resolves whatever the config names, proved by binding a fake
- **Risk:** none material. This is the smallest step and everything after it depends on the seam being in the right place

### Step 3, the endpoint
- `/draw` draws from the source's eligible pool, then asks it for a playback URL
- **The pool is the eligible one and there is no fallback.** Decided 2 August 2026, on the basis that a card without a film will not exist in the system. An empty pool is a 404, which is already what this endpoint does when nothing is on the website. The consequence, recorded rather than argued: a freshly seeded environment, or a film delivered without its poster, gives the homepage a 404 instead of a card
- `RevealCardResource`, the existing shape plus a `video` block of `url` and `expires_at`. Never null, since a card without one is never drawn
- `Cache-Control: no-store` stays, and its comment gains the stronger reason: it now holds a credential, not just a card
- A rate limit on the route, by IP. **Stated honestly in the code comment:** it stops abuse of an endpoint that triggers signing, and it does not prevent a determined visitor from collecting the deck
- **Tests:** a card with a ready video returns a signed URL and an expiry the reveal TTL away; a card whose video is still transcoding is not drawn; one with a video and no poster is not drawn; one not written in the requested language is not drawn; an empty pool is a 404; no card on the website at all is still a 404; the response carries `no-store`; the listing and the single card endpoints still carry no video field at all, asserted directly, since that is the rule most likely to erode; the rate limit answers 429; a fake source is used end to end, which proves the endpoint is genuinely behind the interface
- **Risk:** the assertion that most matters is the negative one, that no credential reaches the Library listing. It is cheap to write and it is the thing a future careless change would break

### Step 4, documentation
- `API_CONTRACT.md`: the new `video` block on `/draw`, the expiry, that it must never be cached or stored, and a correction to the line stating the seven public endpoints carry no authentication and no credentials. That line becomes false with this change and the partner has to know it
- `.claude/api.md`: the reveal source seam, and the rule that credentials live on their own resource
- `docs/CHECKPOINT.md`: what this added and what it decided
- `QA_CHECKLIST.md`: a reveal section, including that a signature stripped from a reveal URL is refused by Bunny

## Answered, 2 August 2026

1. **The reveal expiry reuses the Viewing Room's two hours, for now.** Fifteen minutes was offered and declined. The knob exists as its own key so the two can diverge later without either one reaching into the other. What the two hours accepts, in writing: a reveal URL copied out of developer tools works from anywhere until it expires, and it points at the full film

2. **No fallback. The pool is cards with a watchable film, and an empty pool is a 404.** On the basis that a card without a film will not exist in the system. This means the homepage reveal depends on Jennifer's content in a way it did not before, and a film without its poster does not count

3. **The video is inline in the `/draw` response.** One request, one reveal, nothing to sequence. The alternative would have meant relaxing the paywalled endpoint's pass check to serve a public page, which is the wrong direction. The partner is told rather than asked

## Dependencies

- **You:** approval of this plan and the three questions above
- **Jennifer:** the 22 films and 22 poster frames, both already outstanding. With no fallback, the homepage reveal 404s until at least one card has both. **A film without its poster does not count**, since the pool requires both
- **Frontend partner:** still no acknowledged contract. This adds a credential to a public endpoint, which sharpens the handling rules they have not yet acknowledged
- **Nothing new from Bunny.** Same library, same signing, same key. This spends what M4 already proved on staging

## Verification

- Full suite green, with attention to the negative assertions in step 3
- Against staging: the reveal returns a URL that plays, the same URL with one character of its token changed is refused by Bunny, and one with a past expiry is refused
- Ten calls to `/draw` return different cards, all of them with a video, once staging has more than one film
- The Library listing and the single card endpoint contain no video field, checked by eye against a real response as well as by test
- `migrate:fresh --seed` clean, since nothing here has a migration
