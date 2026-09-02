# Bunny.net Stream Video Integration Plan

> **Status, 3 August 2026. The pre-M4 architecture note, kept as written.**
> It was right about the things that mattered most, and parts of it are now stale.
> `docs/CHECKPOINT.md` holds the current state. Read that first.
>
> **What this document said, and we did not do:**
>
> - "Confirm the exact string-to-sign format against Bunny's current documentation at implementation time, this has changed between versions, don't hand-roll it from a remembered example without checking current docs first." It was hand-rolled from a remembered example, and playback returned 403 everywhere for five days
> - The testing plan opens with a dashboard-level pass, manually building a signed URL and confirming it plays **before touching Laravel**, to isolate configuration from code. That step was never walked. Walking it would have caught this on day one
> - "Token Authentication Key ... must be **enabled** and retrieved." Embed token authentication is still off today
>
> **What is now stale here.** The code is right and this note is old:
>
> | This says | Built as | Why |
> |---|---|---|
> | Verify the webhook signature, like Stripe | Ask Bunny what the real status is | Immune to a forged payload whatever the signing scheme is, and idempotent for free. Better than this note, keep the code |
> | `slug` on cards, with slug history | The card's id | A card is a pop-up, not a page. Reversed in M3 |
> | `status` | `video_status`, plus separate live and incoming columns | A replacement must not empty the card while it transcodes |
> | `thumbnail_path` | `poster_path` | Naming only |
> | "Gallery Pass" | The viewing room pass, and `viewing_room_access_at` | Naming only |
> | Reveal URL "signed or unsigned, to be decided" | Signed, two hours, IP bound | Decided 2 to 3 August 2026 |
>
> **What it called correctly and is worth repeating:** the reveal is public and session-limited, and
> the content "is meant to be revealable without a purchase". That settled a question we later
> reopened and answered the same way.

## Note to Claude Code

This plan documents the intended architecture and a worked example from the Bunny.net dashboard. The exact env variable names, config keys, endpoint routes, controller names, and file paths below are illustrative, not final. Before implementing, map these to this project's actual conventions:

- Check `CLAUDE.md`, `.claude/api.md`, and `.claude/database.md` for naming and structure conventions already established in this codebase.
- Confirm actual env variable names against what already exists in `.env.example`, don't assume the names below are what should be used.
- Confirm route naming and grouping against the existing API route structure.
- Confirm the `cards` table doesn't already have some of these fields before adding migrations.
- Treat the dashboard values and code samples below as a reference for what data is needed and how the flow works, not literal instructions to paste in unchanged.

## Problem

The old WordPress build served all 22 Viewing Room videos as public MP4 files from a single origin, fully loaded on page arrival, with no access control. This project needs to replace that with:

1. A paywall that actually enforces Gallery Pass ownership before video playback.
2. Video-on-demand loading, adaptive bitrate, fast time-to-first-frame.
3. A self-serve upload flow through Filament so Jennifer can add cards herself without a developer, per her existing FAQ commitment.

## Where video is used

- **Homepage reveal.** A small looping back-of-card MP4 (ambient, not gated) that transitions into one randomly selected Living Tarot card animation on click. Public, unauthenticated, session-limited to one reveal (soft-enforced, accepted risk, no hard token gating needed).
- **Viewing Room.** The paid core. All 22 (growing) card animations, gated behind Gallery Pass ownership, video-on-demand with a featured player and a thumbnail rail.

Both paths draw from the same pool of 22 card video assets. There is no third video-related purchase path, the AI reading feature is fully out of scope and has no video component.

## Entitlement model

Two serving paths only:

1. **Public path (homepage reveal).** No auth required. Server picks a random `ready` card and returns a longer-lived signed URL (or unsigned, to be decided against Bunny's current token auth options). Security budget is intentionally loose here, this content is meant to be revealable without a purchase.
2. **Gated path (Viewing Room).** Requires an authenticated user with a completed Gallery Pass order. Server mints a short-lived signed token per video, scoped narrowly, requested fresh each time a user selects a different card in the thumbnail rail.

There is exactly one entitlement check needed: does the authenticated user own an active Gallery Pass. No per-order or per-video grants beyond that.

## Data model

Extend (or confirm existing) `cards` table with:

| Field | Purpose |
|---|---|
| `bunny_video_id` | Bunny's GUID for the asset, set once the video is created on Bunny |
| `status` | `processing` or `ready`. Gates both Viewing Room listing and homepage reveal pool |
| `slug` | Per existing project convention, slug history captured from day one |
| `thumbnail_path` | Storage path for the thumbnail image, separate from the video asset itself |

`status` is the single field both serving paths read from. It should not be duplicated or tracked separately for the two paths.

## Bunny.net dashboard setup (already completed for this project, documented for reference)

The video library has already been created in the Bunny dashboard. Actual values captured:

- **Video library ID:** `716292`
- **CDN hostname:** `vz-9f79c10d-ea5.b-cdn.net`
- **Pull zone name:** `vz-9f79c10d-ea5` (the underlying pull zone behind the CDN hostname, do not edit this directly through "Manage", the dashboard warns this can break Stream or cause data loss, and nothing in this project's requirements needs raw pull zone access)
- **API Key:** available under the library's API tab, full read/write access, needed for the ingestion job (creating and uploading videos)
- **Read-only API Key:** also available, not used in this build, the ingestion job needs write access

Still to confirm before implementation starts:

- **Token Authentication Key**, lives under the library's Security tab (separate from the API access screen above), this is the HMAC secret used to sign playback URLs. Must be enabled and retrieved before the signing helper can be built.
- **CORS / allowed referrers**, must include the frontend's production and staging domains, or playback will fail silently in a way that looks like a token bug.
- **Webhook**, must be configured to point at this project's backend (exact route TBD per this project's routing conventions) for the encoding-finished event. Note the webhook signing secret shown in the dashboard when this is set up, needed to verify incoming webhook payloads the same way Stripe webhooks are already verified in this project.

## Backend implementation, by piece

**Config**

Add whatever this project's env convention is for:
- Bunny library ID
- Bunny API key
- Bunny token auth key
- Bunny CDN hostname / pull zone
- Bunny webhook secret

Reference names used in this plan (`BUNNY_STREAM_LIBRARY_ID`, `BUNNY_STREAM_API_KEY`, `BUNNY_STREAM_TOKEN_AUTH_KEY`, `BUNNY_STREAM_PULL_ZONE`, `BUNNY_WEBHOOK_SECRET`) are illustrative only, map to whatever naming pattern the rest of this project's `.env` already follows.

**Ingestion job** (triggered after a Filament upload, per the existing upload pipeline already documented in the infra stack decision)

1. Create the video on Bunny via the create-video endpoint, passing the card title, receive back a GUID.
2. Save that GUID as `bunny_video_id` on the card, `status` stays `processing`.
3. Upload the file bytes to Bunny via the upload endpoint for that video ID.
4. Bunny transcodes asynchronously.

**Webhook receiver**

1. Verify the incoming request signature against the Bunny webhook secret before processing anything, same discipline already used for Stripe webhook verification in this project.
2. On an encoding-finished event matching a known `bunny_video_id`, flip that card's `status` to `ready`.
3. If verification fails, reject and log, don't process.

**Public reveal endpoint**

- No auth middleware.
- Selects one random card where `status = ready`.
- Returns a signed (or long-lived) Bunny playback URL for that card.
- Exact route path to match this project's existing API route conventions, not necessarily `/api/reveal` as written in earlier discussion.

**Viewing Room token endpoint**

- Requires authentication (Sanctum, per the existing auth decision for this project).
- Checks the authenticated user has an active Gallery Pass order. Reuse whatever entitlement check pattern already exists from the Stripe/Order work, don't build a second one.
- Checks the requested card's `status` is `ready`.
- Returns a short-lived signed Bunny playback URL scoped to that specific video.
- Should be callable per video selection in the thumbnail rail, not once per session, keep the exposure window small.

**Signing helper**

HMAC-based token generation, needs the token auth key, the video path, and an expiry timestamp. Confirm the exact string-to-sign format against Bunny's current documentation at implementation time, this has changed between versions, don't hand-roll it from a remembered example without checking current docs first.

## Decision needed before building the signing helper

HLS (`.m3u8`, adaptive bitrate) versus direct MP4 playback. HLS delivers the adaptive bitrate benefit that's part of the original requirement, but the signing path and the frontend player integration differ. Confirm with the frontend partner which the Next.js video player component expects before finalizing the URL format in the signing helper.

## Testing plan

**Dashboard level, before touching Laravel**
- Upload one test video manually through the Bunny dashboard, confirm it reaches `Ready`.
- Manually construct a signed URL using Bunny's current documentation example, confirm it plays in a browser directly. This isolates dashboard configuration issues from backend code issues.

**Ingestion pipeline**
- Upload a card through Filament, confirm `status` starts as `processing`.
- Confirm the video appears in the Bunny dashboard and begins encoding.
- Confirm the webhook fires once Bunny finishes, check logs, confirm `status` flips to `ready` without manual intervention.
- Test the failure path: temporarily break the webhook endpoint, confirm the card stays visibly stuck in `processing` in the admin panel rather than failing silently.

**Public reveal path**
- Call the endpoint with no auth headers, confirm a playback URL is returned.
- Load that URL directly, confirm it plays before expiry and fails cleanly after expiry (if using a signed URL for this path).

**Viewing Room path**
- Call the token endpoint unauthenticated, confirm a 401.
- Call it authenticated as a user without a Gallery Pass, confirm a 403.
- Call it authenticated as a user with a completed pass order, confirm a token is returned and the resulting URL plays.
- Let the token expire, confirm replaying the same URL fails. This is the test that actually proves the security boundary works, not just that the happy path works.

**Webhook signature verification**
- Send a request to the webhook endpoint with a tampered or missing signature, confirm it's rejected. Don't only test Bunny's legitimate requests.

## Explicitly out of scope

- Any AI reading video gating. The AI reading feature has no video component and no entitlement path of its own.
- Per-order video grants of any kind.
- Editing the Bunny pull zone configuration directly through "Manage."
