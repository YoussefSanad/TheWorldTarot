# Removing a video properly, and dropping the poster

Post-M4 corrective work, 7 August 2026. Four things asked for together, and they
turn out to be one change: the poster is what is breaking the draw on staging,
and removing a video is what leaves the panel lying about a card's state.

---

## Goal

Removing a card's video should mean the video is gone: gone from Bunny, gone
from the bucket, and gone from what the panel says about the card. It currently
means none of those. The row keeps its status, the film keeps playing from
Bunny's library, and the master sits in the private bucket forever.

At the same time the poster frame leaves the system entirely. It was a second
per-card upload gating the Viewing Room and the draw, the frontend already stopped
reading it, and on staging it is the reason `GET /{locale}/cards/draw` returns a
404 with an empty body.

## Out of scope

- Any change to what a playback URL is or how it is signed. That was fixed on
  3 August and nothing here touches it
- Deleting the Bunny video a replacement supersedes. Related, real, and its own
  decision. See open question 2
- The Viewing Room's own QA walk, still owed from M4
- M5, M6, M7

---

## What is actually wrong, in order of how much it costs

**1. The draw 404s on staging, and the empty body says nothing.**

Confirmed against staging: `GET /api/v1/en/cards` returns three cards, all with
`"poster": null`. The draw narrows that pool with
`whereNotNull('bunny_video_id')->whereNotNull('poster_path')`, so the poster
condition alone empties it, and `throw new NotFoundHttpException` with no message
renders as `{"message": ""}` once debug is off.

Dropping the poster condition fixes it **if at least one of those three cards has
a finished film**. Nothing public exposes that, and I cannot query staging from
here, so this is stated as a condition rather than a promise. If the draw still
404s afterwards, no card on staging has a video that reached ready, which is a
content state and not a bug.

The empty body is worth fixing on its own account. A 404 that says nothing is
indistinguishable from a routing mistake, and this endpoint has two very
different reasons to give one.

**2. Removing a video changes almost nothing.**

Clearing the file field nulls `video_master_path` on save and nothing else.
`video_status` keeps whatever it held, which is why the list still says "Ready to
watch". `bunny_video_id` keeps pointing at a film that is still in Bunny's
library and still costs money to store. The master stays in the private bucket.
Filament does not delete it either: `deleteUploadedFileUsing` is null by default,
so the X in the file field removes the path from the form and leaves the object
in the bucket.

**3. "On the website" does not need updating, and this is worth saying plainly.**

It is computed live from `isAvailableIn()`, which is published, has a picture, and
has its copy in the original language and the one being viewed. No part of it
reads the video. Removing a film cannot change it and the column is already
correct. The same is true of `in_viewing_room` on the API, which is computed per
request rather than stored. The only thing that goes stale is `video_status`,
because it is the one genuinely stored fact, and that is step 2's job.

---

## Steps

### Step 1: The poster leaves the system

One coherent removal, all of it or none:

- `CardForm`: drop the `poster_path` upload. The video section then holds one
  field, so its description stops promising two
- `Card`: drop `poster_path` from `#[Fillable]`, drop `hasPoster()` and
  `posterUrl()`, drop `whereNotNull('poster_path')` from `scopeViewableIn`, drop
  `hasPoster()` from `isViewableIn`, drop the poster line from
  `whatTheViewingRoomIsWaitingFor`
- `FullFilmRevealVideo::eligible`: drop the poster condition. **This is the line
  that fixes staging**
- `CardResource`: drop `'poster'`
- `CardFactory`: drop `poster_path` from `watchable()` and drop the
  `withoutPoster()` state
- Migration dropping the `poster_path` column, literals only per the trap list
- Tests: `CardVideoTest`, `CardResourceTest`, `RevealVideoSourceTest`,
  `CardsEndpointTest`, `ViewingRoomTest`, `SendCardVideoToBunnyTest` all name it.
  The cases asserting a poster keeps a card out of the Viewing Room are deleted
  rather than rewritten, since the rule they prove no longer exists
- `API_CONTRACT.md`: remove the `poster` field from the card shape and correct
  the three sentences that make the Viewing Room and the draw depend on a poster
  frame

**Frontend cost: none.** `src/lib/api.ts` already types `poster` as optional with
a comment saying it is being removed and nothing reads it, and
`toTarotCard` already uses `card.image` for the restored-visit still. Its README
says the same. So this is a contract narrowing the consumer has already absorbed.

**Orphaned poster files are accepted.** All three staging cards have none, and
Jennifer has uploaded no posters anywhere. If any exist in production they are a
handful of jpgs in the media bucket, and a migration that reaches into a bucket
is worse than the mess it cleans.

**What proves it:** the existing Viewing Room and draw tests, with the poster
conditions gone, still refuse a card whose film is not ready. Then staging.

### Step 2: The draw says why it has nothing

Give the 404 a message, so the two reasons it fires can be told apart in a log or
a browser: nothing on the website in this language, versus nothing with a film
yet. The frontend already treats any 404 here as "nothing to draw" and falls back
to the bundled card, so this changes no behaviour on its side.

Small enough to ride with step 1 if preferred. Listed separately because it is a
response body, and response bodies are the contract.

### Step 3: Bunny learns to delete

`BunnyStream::deleteVideo(string $videoId): void`, the fifth and last thing this
application knows how to do to Bunny.

`DELETE /library/{id}/videos/{guid}`. **A 404 counts as success**, because a job
that runs twice must not fail the second time, and "the video is not there" is
exactly the outcome being asked for. Any other failure throws
`BunnyRequestFailed` like the rest of the client.

**Tests:** `Http::fake` for the happy path and the 404-is-fine path, plus the
misconfigured-credential path the other methods already cover. Per `.claude/testing.md`
this is our side of the wire only, and it is the honest limit of what a fake can
prove: it will not tell us Bunny's delete endpoint is shaped the way we think.
Step 6 is where that gets confirmed.

### Step 4: Removing a video means removing it

**On the model, beside the upload rule it mirrors.** The existing `saving` hook
turns a new master into a pending video. A second branch turns a cleared master
into no video at all:

```
saving:  video_master_path went from filled to blank
         -> capture what is about to be lost
         -> null bunny_video_id, bunny_incoming_video_id,
            video_status, video_uploaded_at
saved:   dispatch ForgetCardVideo(ids, master path)->afterCommit()
```

On the model rather than the edit page, for the reason already written there: it
should hold however the removal arrived, panel or console or test.

`ForgetCardVideo` takes **plain strings, never the card**. This is the whole
trick. By the time the job runs the row no longer knows what it used to point at,
so a job holding a `Card` would have nothing to delete. It carries the live video
id, the incoming one, and the master path, and it deletes whichever of them it
was given.

Written for at-least-once delivery, per `CLAUDE.md` section 2:

- Deleting an already-deleted Bunny video is a 404, which step 3 treats as done
- Deleting an already-deleted object from the bucket is a no-op in Flysystem
- So the whole job is safely re-runnable, and nothing it touches is read by
  anything else by the time it runs

Failure is logged and does not retry forever. Three tries, then a log line naming
the card, the video and the path, because the residue of a failed cleanup is a
file and a Bunny row somebody has to remove by hand and they need to know which.

**The master is deleted here, and that is a narrowing of an existing decision
rather than a contradiction of it.** `SendCardVideoToBunny::failed()` keeps the
master deliberately, on the grounds that it is the only copy not on her laptop
and re-uploading is how she retries. That still holds, and nothing in this step
changes it: a *failed* upload keeps its master. What changes is that a master she
has explicitly removed is removed, which is what she asked for by clearing the
field.

**Tests:**

- Clearing the master nulls the four columns and dispatches the job once
- The job deletes the live video, the incoming video, and the master object
- The job with a card that only ever had a master, and never reached Bunny,
  deletes the file and calls Bunny not at all
- Running the job twice is harmless, with Bunny answering 404 the second time
- Removing the video does not touch `image_path`, and does not change whether
  the card is on the website
- The panel: after removal the list column reads "None yet" rather than "Ready to
  watch", and the edit screen says the Viewing Room is waiting for a video

The list column needs no code change. `video_status` is nulled, and the column
already carries `->placeholder('None yet')` for cards that never had a video, so
a card whose video was removed reads the same as one that never had one. The test
is there to hold that, since it is the symptom that was reported.

### Step 5: The race worth naming

She uploads a master, then removes it before the ingestion job runs. Two orders
are possible and only one is a problem:

- Job runs first, finishes, then removal: fine, the removal sees the ids and
  deletes them
- Removal first, then the job runs: the job reloads the card, finds
  `video_master_path` blank, and returns without touching Bunny. Already correct,
  already tested
- Removal lands *between* the job creating the Bunny video and it storing the id:
  a genuinely orphaned video in her library, with nothing pointing at it

The third is a window of one HTTP call and the cost is one stray row in a Bunny
library. **Accepted, not fixed**, because closing it needs a lock or a tombstone
table and both are heavier than the thing they prevent. Written here so it is a
known cost rather than a future mystery. A test pins the second case, which is
the one that actually happens.

### Step 6: Deploy to staging and prove it

Nothing here is proved until this runs, and step 3's fake proves the least of
anything in this plan.

1. Deploy the branch
2. `GET /api/v1/en/cards/draw` returns a card and a film, or returns a 404 that
   now says why. If it still 404s, no staging card has a finished film, and that
   is a content state
3. In the panel, remove a card's video and save. The list goes to "None yet"
4. Check Bunny's library. The video is gone. **This is the only evidence that
   step 3 is real**
5. Check the private bucket. The master is gone
6. `GET /api/v1/en/cards` no longer carries `poster`

---

## Decisions taken, 7 August 2026

**1. The field says what saving costs.** A line of helper text on the video
upload, saying the film comes off the website and is deleted when she saves.
Clicking the X stays recoverable by navigating away without saving, and the
warning sits where the point of no return actually is. A confirmation step was
declined as awkward inside a file field, and leaving it silent was declined
because it depends on her knowing the save is what commits.

**2. Replacement cleans up after itself, in this plan rather than after it.**
Added as step 5 below, and it turns out to be two separate leaks rather than one.

## Steps, continued

### Step 5: A replacement stops leaving its predecessor behind

`promoteIncomingVideo()` overwrites `bunny_video_id` with the incoming one and
drops the old id on the floor, so every replacement ever done has left a film in
Bunny's library that nothing points at. The old master leaks separately and at a
different moment: Filament overwrites the column on save and leaves the object in
the bucket.

Two moments, therefore, not one:

- **On save**, a master replacing another master hands the superseded path to
  `ForgetCardVideo`. The video ids are untouched here, because the old film is
  still playing and must keep playing until the new one is ready
- **On promotion**, the superseded `bunny_video_id` is handed to
  `ForgetCardVideo`, after the row has been updated to point at its replacement

Which makes promotion destructive, and that is the cost being accepted. It runs
from a webhook and a scheduled sweep rather than from anything Jennifer does, so
a promotion firing on a card it should not have used to be recoverable and no
longer is. Worth it against a library that accumulates an unreferenced film per
replacement forever.

The order matters and the tests pin it: the row is saved pointing at the new
video **before** anything is dispatched to delete the old one. Reversed, a job
running early would delete a film the card still points at.

**Tests:**

- Promotion dispatches the cleanup for the superseded id, and for nothing else
- A first promotion, where there is no superseded video, dispatches nothing
- Replacing a master dispatches cleanup for the old master and leaves both video
  ids alone, so the card stays watchable throughout
- The card points at the new video before the job carrying the old id exists

---

## Dependencies

- None on the frontend partner. The contract only narrows, and their code already
  treats `poster` as gone
- None on Jennifer, though whether staging has a card with a finished film decides
  what step 6 can show
- Bunny's delete endpoint has never been called from this application. Step 6 is
  the first time it is exercised for real
