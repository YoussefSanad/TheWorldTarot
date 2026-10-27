> **Status: approved by the user 27 October 2026, building. Steps 1 and 2 committed; step 2a built, uncommitted; step 3 is next.** ~~Steps 1 and 2 built and uncommitted; step 2a is next.~~ ~~Revised after critique, awaiting approval, 27 October 2026.~~ ~~Drafted 27 October 2026.~~ ~~Revised the same day with the user's answers to the four open questions (see "Decisions recorded"). Status: revised, awaiting critic.~~ Revised again the same day after the critic's review, verdict "ready after fixes" (see "Critique resolved"). ~~Not approved.~~ Approved 27 October 2026. Issue #95 on `YoussefSanad/TheWorldTarot`, pictures only.

# Card pictures, seeded by command (#95), plan

## Progress

- Each step adds a line here when it lands: date, commit, tests added, suite count, docs touched
- **Plan approved by the user, 27 October 2026.** Defaults kept: a conflict exits non-zero, `unchanged` rewrites the file but not the row, and step 9 falls back to an API check if no card can play its film on staging. Work is on branch `feat/95-seed-card-images`, cut from `origin/staging`
- **Step 1 built, 27 October 2026, not committed.** `App\Support\MediaDiskGuard::refusalFor()` holds the two rules, `CheckMediaStorage` calls it. Tests added: `tests/Unit/MediaDiskGuardTest.php` (6 cases, 3 red against a null stub for the right reason). `MediaStorageCheckTest` untouched and green (12). Suite: 1575 passed, 3 skipped, 0 failed. Reviewer: approve, no findings. Docs touched: this file only
- **Step 2 built, 27 October 2026, not committed.** `app/Console/Commands/SeedCardImages.php` (`--source` only), `CardSeeder::MAJOR_ARCANA` made public. Tests added: `tests/Feature/SeedCardImagesTest.php` (11 cases, all red against a no-op stub for the right reason). Mutation checks went red for: guard removed, `hasImage()` skip removed, trashed lookup, false return, thrown exception, `save()` instead of the conditional update (both conflict cases), observed-path condition dropped, content hash dropped, `'visibility' => 'public'` dropped (the last needed a private-default fake disk in the test, added after the first mutation run found a gap). Dropping the explicit `deleted_at` condition alone stays green by design: the soft-delete scope on the query already supplies it. Suite: 1586 passed, 3 skipped, 0 failed. Reviewer: approve, one nit (stale test header comment, fixed). Docs touched: this file only; `QA_CHECKLIST.md` row is left to step 8. Committed 27 October 2026 (steps 1 and 2 as two commits, `fa64222` and `ed602d2`)
- **Step 2a built, 27 October 2026, not committed.** `SeedCardImages.php` now reports `missing card` (exit 0), `missing file` and `more than one file` (exit non-zero, nothing uploaded for a duplicate) and one warning listing stray files as ignored (`.gitkeep` silent; `22-extra.webp` is a stray, not `missing card`). Added `database/seed-images/cards/.gitkeep` and `README.md` (naming rule, spec, name-in-image line). Tests added: 5 in `tests/Feature/SeedCardImagesTest.php`, all red on assertions before the change. Mutation checks went red for each of the four rules (missing card, missing file, duplicate, stray). Suite: 1591 passed, 3 skipped, 0 failed. Reviewer: approve, no findings. Docs touched: this file only

## Goal

Jennifer currently has to upload 22 card pictures by hand in the panel before any card can reach the website, because `Card::availability()` (`app/Models/Card.php:215-228`) refuses a card with no `image_path`. This plan adds `php artisan cards:seed-images`, which takes 22 committed webp files from `database/seed-images/cards/`, matches each to a live card by its number, puts it on the `filesystems.media` disk under `cards/` with public visibility exactly as the panel's `FileUpload` does (`app/Filament/Resources/Cards/Schemas/CardForm.php:59-67`), and writes the path to `cards.image_path` ~~.~~ (27 Oct 2026, critique) only if the row still holds the value the command read and the card is still live, so a picture Jennifer replaces, or a card she deletes, while the upload is in flight is never overwritten. It never overwrites a picture she has already uploaded unless told to, never touches a deleted card, refuses to write to a filesystem that does not survive a deploy, and can prove from outside that each picture actually answers. The command is built and tested against fake files first, so it does not wait for the real artwork. ~~The artwork does not exist yet and is sourced in its own step.~~ (27 Oct 2026) The user supplies a folder of the 22 pictures with each card's name already printed on its plaque. Step 6 receives, checks and renames them, step 7 commits them.

## Out of scope

- **Card videos.** Issue #112. Nothing here reads or writes `video_master_path` or any `video_*` column
- **Viewing Room eligibility on staging.** (27 Oct 2026, critique) `Card::viewingRoom()` (`app/Models/Card.php:332-339`) also needs a playable film, which is #112. This plan proves the pictures, not that every card reaches the Viewing Room
- **The Library grid on the frontend.** It renders its own static artwork from `theworldtarot-frontend/public/figma/library-cards/` and lays the name over the plaque in HTML. Whether it ever switches to the API's `image` is a frontend issue. ~~See open question 3 before it does.~~ (27 Oct 2026) The baked-in name is accepted; if the Library ever reads `image`, the name prints twice and does not translate, which the folder README records
- **Copy and publishing.** A picture is one of five conditions in `Card::availability()`. Writing names and descriptions and publishing stay Jennifer's, in the panel
- **Deleting pictures replaced by `--force`.** The old object stays in the bucket, as it does when she replaces one in the panel. (27 Oct 2026, critique) So does an object uploaded for a card that then reports `conflict`. No step of this plan removes any object, automatically or otherwise. Cleaning orphans is a separate housekeeping issue if it is ever wanted
- **Minor Arcana.** `CardSeeder` places the 22 Major Arcana only
- **A change to `API_CONTRACT.md`.** `image` keeps its shape (absolute URL, never null on anything the API returns). The `.jpg` in its example is illustrative and stays. Revisit only if a step finds otherwise

## Acceptance criteria

Observed with `composer test` unless stated. Each criterion names its test or its walk.

1. `php artisan cards:seed-images` with a full set of 22 files and 22 live cards with no picture uploads 22 objects to the media disk under `cards/`, each with public visibility, and each card's `image_path` equals the stored path. Test: fake disk `assertExists`, `getVisibility` is `public`, row read back
2. A card whose `image_path` is already filled is left alone: no object is written for it and its `image_path` is unchanged, and the table says `skipped`. Test
3. ~~With `--force`, that same card gets the new picture and its `image_path` changes to the new path. Test~~ (27 Oct 2026, critique) With `--force` and a file whose bytes differ from the card's current picture, the card's `image_path` changes to the new content-hash path, the table says `replaced`, and the object at the old path is still on the disk. Test
4. A soft-deleted card is never written, with or without `--force`, including when a live card shares its number. Test: trashed row's `image_path` unchanged, only the live row changes
5. A file whose number has no live card is reported `missing card` and does not fail the run. Test: exit code 0
6. A card number from `CardSeeder::MAJOR_ARCANA` with no file is reported `missing file` and the run exits non-zero. Test
7. Two files for one number are reported and the run exits non-zero, with nothing uploaded for that number. Test
8. A write the disk rejects is reported `failed`, leaves that card's `image_path` unchanged, and the run exits non-zero. Test with a disk mock whose `putFileAs` returns false, and one that throws
9. `--dry-run` writes no object and changes no row, and the table says `would upload` and `would skip`, (27 Oct 2026, critique) and with `--force` `would replace` or `unchanged`. Test
10. Outside the `local` and `testing` environments, a media disk whose driver is `local` is refused before any file or row is touched, with the same wording `media:check` uses. Test with `app()->detectEnvironment(fn () => 'production')`. Also an `s3` media disk with no bucket is refused. Test. (27 Oct 2026, critique) Holds from the first commit that can write, step 2
11. `--verify` sends one HEAD request to `Storage::disk(media)->url($path)` for every matched card that has a picture after the run, reports each status, and exits non-zero if any answer is not 2xx. Test with `Http::fake`, one 200 and one 403
12. Each test in criteria 1 to 11 (27 Oct 2026, critique) and 15 to 17 goes red when the rule it pins is deleted from the command. Checked by hand at each step (delete the rule, run the one test, watch it fail on an assertion, restore) and reported in the step's progress line
13. `database/seed-images/cards/` holds exactly 22 files named `NN-slug.webp`, one for each number 0 to 21, each a real webp ~~of 640x1060~~ (27 Oct 2026) at least 640 wide and 1060 tall with a width-to-height ratio ~~within 2% of 640:1060~~ (27 Oct 2026, critique) passing `abs((width / height) / (640 / 1060) - 1) <= 0.02`, and each under 300KB. Test reads the committed files with `getimagesize`, added in step 7. This is asset validation only: it does not prove that a frame on the site leaves the plaque uncropped, which the user checks by eye on staging (criterion 14). That the name on each plaque is legible is not tested; the user confirms it by eye in step 6
14. ~~On staging (and then production, struck 27 Oct 2026), `cards:seed-images --verify` exits 0 and the Viewing Room shows each card with its picture. Walk, step 9.~~ (27 Oct 2026, critique) On staging, the `cards:seed-images --verify` table accounts for every number 0 to 21: each row is `uploaded`, `skipped` or `unchanged` with a 2xx in `Verify`, or `missing card` for a card the step report names as deliberately deleted on staging (checked against the panel's trash). No row is `missing file`, `more than one file`, `conflict` or `failed`. An exit code of 0 alone is not enough, because `missing card` exits 0. Then one representative card that `Card::viewingRoom()` admits on staging is opened in the Viewing Room, its picture shows, and the user confirms by eye that the rendered frame does not crop the plaque. Walk, step 9. Production is run by the user when the pictures land and is not walked by this plan
15. (27 Oct 2026, critique) If, between the command reading a card and writing its row, the card's `image_path` changes (Jennifer replaces the picture in the panel) or the card is soft-deleted, the row is not written: `image_path` and `deleted_at` keep what the other writer left, the table says `conflict`, the object the command uploaded stays on the disk, and the run exits non-zero. Holds with and without `--force`. Test: a disk double whose `putFileAs` makes the other writer's change and then returns the path, four cases (replacement and soft-delete, each with and without `--force`)
16. (27 Oct 2026, critique) With `--force` and a file whose bytes are identical to the card's current seeded picture, the stored path is the same as the card's `image_path`, the table says `unchanged`, the row is not written (its `updated_at` is unchanged), and the object exists at that path afterwards. Test
17. (27 Oct 2026, critique) After Jennifer replaces a seeded picture in the panel (`image_path` now a panel-generated name), a rerun without `--force` says `skipped` and keeps her path; a rerun with `--force` says `replaced`, sets the seed's hash path, and her object is still on the disk. Test

## Contract changes

No API, schema or event change. One new console command, written out exactly:

```
cards:seed-images
    {--dry-run : Say what would happen and write nothing}
    {--force   : Replace a picture a card already has}
    {--verify  : HEAD every card picture's public URL afterwards}
    {--source= : Directory to read from, default database_path('seed-images/cards')}
```

- **Input file name**: `^(\d{2})-[a-z0-9-]+\.webp$`. The two digits are the card number and the only thing matched on. The slug is for humans and is never compared with the card's name, since she can rename a card
- **Stored path**: `cards/{NN}-{slug}-{first 8 hex of sha1 of the file}.webp`, on `config('filesystems.media')`, visibility `public`. (27 Oct 2026, critique) Identical bytes always give the identical path; different bytes give a different path
- **Row write** (27 Oct 2026, critique). The command remembers the `image_path` it read for the card (`$observed`, possibly null), uploads, then writes the row with one conditional statement, never `$card->save()`:

  ```php
  $written = Card::query()
      ->whereKey($card->getKey())
      ->whereNull('deleted_at')
      ->when(
          $observed === null,
          fn ($q) => $q->whereNull('image_path'),
          fn ($q) => $q->where('image_path', $observed),
      )
      ->update(['image_path' => $stored]);
  ```

  One row affected means `uploaded` (observed was null) or `replaced` (observed was another path). Zero rows means `conflict`. The `SoftDeletes` scope already adds the `deleted_at` clause; the explicit one is there for the reader. The Eloquent builder sets `updated_at`. A builder update skips model events, which is safe because both hooks in `Card::booted()` (`app/Models/Card.php:87-117`) act only on `video_master_path`; the code comment says so
- **Table columns**: `#`, `Card`, `File`, `Result`. Results: `uploaded`, ~~`would upload`, `skipped`, `would skip`, `missing card`, `missing file`, `more than one file`, `failed: <reason>`~~ (27 Oct 2026, critique) `replaced`, `unchanged`, `would upload`, `would replace`, `skipped`, `would skip`, `missing card`, `missing file`, `more than one file`, `conflict`, `failed: <reason>`, and with `--verify` a `Verify` column carrying the HTTP status or `unreachable`
- **`unchanged`** (27 Oct 2026, critique): only with `--force`, when the stored path equals the card's current `image_path`. The object is written again at the same key (same bytes, so nothing a cache holds goes stale, and a missing object is restored), the row is not written. In a dry run it is reported the same way and nothing is written
- **Exit code**: non-zero on refusal, `missing file`, `more than one file`, `failed`, (27 Oct 2026, critique) `conflict`, or any verify answer that is not 2xx. Zero otherwise, including `missing card`, `skipped` and `unchanged`

Sub-decisions made here, change them at approval if wrong:

- **Content hash in the stored name.** A deterministic `cards/00-the-fool.webp` would be overwritten in place by `--force`, and a public bucket behind a cache would go on serving the old picture. The hash gives a new picture a new URL and an unchanged one the same URL
- **`--source` is a real option, not a test hook.** It lets the tests point at a temp directory of fake files, and lets an operator trial a set before committing it
- **No image decoding in the command.** It matches names and moves bytes. Format, size and dimensions are pinned once, against the committed files, by the step 7 test
- **Files that match no number in `MAJOR_ARCANA`, or match no pattern, are listed as a warning and ignored.** They are not a failure, since `.gitkeep` and a README live in the same folder
- **`CardSeeder::MAJOR_ARCANA` becomes public.** The command iterates the numbers from it rather than keeping a second list of 0 to 21
- **The guard is extracted, not copied.** Step 1 moves the local-disk and missing-bucket checks out of `CheckMediaStorage` so both commands answer from one place
- **(27 Oct 2026, critique) A conflict exits non-zero.** What the operator asked for did not happen for that card. A rerun shows the card's real state (`skipped` after her upload, `missing card` after her delete), and the command is run by hand, never from a deploy hook, so a non-zero exit only stops a person, who then reads the table
- **(27 Oct 2026, critique) A conflict keeps the uploaded object.** Deleting it would race the same way the row did, and orphans are already accepted (Out of scope)
- **(27 Oct 2026, critique) `unchanged` rewrites the object but not the row.** Rewriting the same bytes at the same key is harmless and repairs a missing object; skipping the row write keeps `updated_at` honest

## Steps

All in `theworldtarot-backend`. Each step is one commit, follows the inner loop in `.claude/testing.md` (test written whole, red for the right reason, then code), updates this plan's Progress list, and comments on issue #95.

Order (27 Oct 2026, critique): 1, 2, 2a, 3, 3a, 5, 6, 7, 8, 9. Step 4 is folded into step 2. Step 6 needs 3a.

### Step 1. The media disk guard is one class both commands use

- **Builds**: `App\Support\MediaDiskGuard` (name open to the builder), one method returning the reason a named disk must not be written to from this environment, or null. Two rules lifted verbatim from `CheckMediaStorage::checkDisk()` lines 80-102: `s3` driver with blank bucket, and `local` driver outside `local` and `testing`. `CheckMediaStorage` calls it and prints its own surrounding output (`reportEnvironment()` stays in the command)
- **Tests**: `tests/Feature/MediaStorageCheckTest.php` passes untouched, which is the proof the refactor changed nothing. New `tests/Unit/MediaDiskGuardTest.php`: local driver in `production` refused, local driver in `local` allowed, `s3` with no bucket refused, `s3` with a bucket allowed. Delete each rule in turn and watch its test go red
- **Could go wrong**: the error strings are asserted by `MediaStorageCheckTest` (`wiped on every deploy`, `no bucket configured`), so the wording must move with the rule. The guard must read `filesystems.disks.{name}.driver` from config and not build the disk, or the missing-bucket case throws from Flysystem as the existing comment warns
- **Acceptance**: criterion 10's rule exists in one place. Suite green, count reported
- **Docs**: none beyond Progress

### Step 2. The command uploads and skips behind the disk guard, and writes a row only if no one else has, against fake files

- **Builds**: `CardSeeder::MAJOR_ARCANA` made public. `app/Console/Commands/SeedCardImages.php` with `--source` only. (27 Oct 2026, critique) First line of `handle()` calls the step 1 guard, before the directory is read or any row loaded; a refusal prints the guard's wording and fails. Then: list the directory, group files matching the pattern by number, and for each number in `MAJOR_ARCANA` that has exactly one file, find the live card (`Card::where('number', $n)->first()`, default scope so trashed rows never match), skip it if `hasImage()`, otherwise remember `$observed = $card->image_path`, `putFileAs('cards', ..., $name, ['visibility' => 'public'])` on the media disk, and ~~save `image_path` only after the write returns a path~~ (27 Oct 2026, critique) only after the write returns a path, run the conditional update written out under Contract changes, reporting `conflict` on zero rows. Prints the table. ~~`database/seed-images/cards/.gitkeep` and a short `README.md` ...~~ (27 Oct 2026, critique) moved to step 2a
- **Interim behaviour until step 2a**: a number with no file, no live card, or two files is passed over silently: nothing uploaded, no row, exit unaffected. That is the safe default, and 2a only adds the reporting and the exit codes
- **Tests**: new `tests/Feature/SeedCardImagesTest.php`. A `beforeEach` makes a temp directory and a helper writes fake files (any bytes, named `NN-slug.webp`), `afterEach` removes it. `Storage::fake(config('filesystems.media'))`. Cases: criterion 1 (all 22, path and visibility and row), 2 (skipped), 4 (trashed, and trashed plus live at one number), ~~5, 6, 7~~ (27 Oct 2026, critique, moved to 2a), 8 (put returns false, and put throws, row unchanged, exit non-zero), the stored name carries the content hash, (27 Oct 2026, critique) 10 (both rules, each asserting the failure wording, `assertFailed()`, an empty fake disk and every card's `image_path` unchanged), and 15 without `--force` (a disk double whose `putFileAs` first sets the card's `image_path` to `cards/panel-upload.webp` through a query, then returns the path; and one that soft-deletes the card, then returns the path; each asserts `conflict`, the row as the other writer left it, the uploaded object present, exit non-zero). ~~a stray file is warned and ignored~~ (moved to 2a)
- **Could go wrong**: the `Card` `saving` hook only reacts to `video_master_path`, so a picture write dispatches nothing, but assert `Queue::assertNothingPushed()` once to pin it. A card whose `image_path` points at a file that no longer exists still counts as having a picture; that is deliberate and matches the panel. Writing the row before the upload succeeds would publish a broken picture, which is what criterion 8 pins. (27 Oct 2026, critique) Moved from step 4: on Laravel Cloud the bucket replaces the disk named `public`, so the guard judges by driver, never by disk name; `Storage::fake` does not change the configured driver, which is why the refusal is testable at all, and the test's comment says so. A `$card->save()` creeping back in place of the conditional update would pass criteria 1 to 8 and fail only 15, which is why 15 lands here and not later. The disk double for 15 must wrap the fake disk (call through for the actual write) so the "object present" assertion means something
- **Acceptance**: criteria 1, 2, 4, 8, 10, and 15 without `--force`. Red-on-delete checked for each, including replacing the conditional update with `save()` for 15
- **Docs**: Progress. `QA_CHECKLIST.md` section 6a gains a row: run the command locally against the committed folder and see the table

### Step 2a. The command reports a set it cannot fully apply (27 Oct 2026, critique, split from step 2)

- **Builds**: the reporting the step 2 interim left silent: `missing card`, `missing file`, `more than one file`, the warning for stray files, and the exit codes for them per Contract changes. `database/seed-images/cards/.gitkeep` and a short `README.md` there giving the naming rule, ~~the 640x1060, under 300KB webp spec~~ (27 Oct 2026) the spec (webp, at least 640x1060 with the ratio rule of criterion 13, under 300KB each), and one line noting that the card's name is printed into each picture, so it will not translate and will print twice on any screen that also lays the name in HTML
- **Tests**: in `SeedCardImagesTest.php`: criterion 5 (missing card, exit 0), 6 (missing file, exit non-zero), 7 (two files for one number, nothing uploaded for it, exit non-zero), a stray file (`README.md`, `notes.txt`, `22-extra.webp`) is warned and ignored and does not fail the run
- **Could go wrong**: the stray-file warning must not fire for `.gitkeep` in a way that reads as a problem; it is listed once, as ignored. A file `22-...webp` matches the pattern but no number in `MAJOR_ARCANA`; it is a stray, not a `missing card`
- **Acceptance**: criteria 5, 6, 7. Red-on-delete checked for each
- **Docs**: Progress

### Step 3. ~~`--force` and `--dry-run`~~ (27 Oct 2026, critique) `--force` replaces a picture, and leaves identical bytes alone

- **Builds**: ~~the two options. `--force` drops the skip. `--dry-run` computes every row and writes nothing, printing `would upload` and `would skip`~~ (27 Oct 2026, critique) `--force` drops the skip. When the stored path equals the card's current `image_path`, the object is written again and the row is not, result `unchanged`. Otherwise the conditional update runs against the observed path, result `replaced` or `conflict`
- **Tests**: criterion 3 (~~`--force` replaces, new path differs from old, old object still on the disk~~ (27 Oct 2026, critique) seed once, change the fake file's bytes, rerun with `--force`: `replaced`, new hash path, old object still on the disk). Criterion 16 (seed once, rerun with `--force` on the same bytes: `unchanged`, `image_path` and `updated_at` unchanged, the object exists; delete the object from the fake disk first in a second case and assert it is back). Criterion 17 (seed once, set `image_path` to a panel-style name with an object behind it, rerun without `--force`: `skipped`, her path kept; rerun with `--force`: `replaced`, seed hash path, her object still on the disk). Criterion 15 with `--force` (panel replacement during the write, and soft-delete during the write, both `conflict`, row as the other writer left it). `--force` never reaches a trashed card (extends criterion 4). ~~Criterion 9 (...). `--dry-run --force` writes nothing either. A dry run with a missing file still exits non-zero, so a dry run is a real check~~ (moved to 3a)
- **Could go wrong**: comparing paths, not bytes, is enough only because the path carries the hash; say so in the comment. Under `--force` the observed value is a path, not null, so the `when()` branch for a filled value is the one that guards against a panel replacement, and only the `--force` conflict test exercises it
- **Acceptance**: criteria 3, 15 with `--force`, 16, 17, and 4 extended. Red-on-delete checked for each
- **Docs**: Progress

### Step 3a. `--dry-run` says what would happen and writes nothing (27 Oct 2026, critique, split from step 3)

- **Builds**: `--dry-run` computes every row and writes nothing, printing `would upload`, `would skip`, and with `--force` `would replace` or `unchanged`. The disk guard still runs first, deliberately: a dry run is how an operator checks an environment before the real run
- **Tests**: criterion 9 (`Storage::assertDirectoryEmpty` on `cards`, rows unchanged). `--dry-run --force` writes nothing either and shows `would replace` and `unchanged`. A dry run with a missing file still exits non-zero, so a dry run is a real check. A dry run on a refused disk is refused
- **Could go wrong**: an early return for dry-run placed before the missing-file branch would make a dry run report success on a broken set. A dry run must never report `conflict`, since it writes no row
- **Acceptance**: criterion 9
- **Docs**: Progress

### ~~Step 4. The command refuses a disk that does not survive a deploy~~

(27 Oct 2026, critique) Folded into step 2, so no commit exists in which the command can write without the guard. Its builds, tests and notes moved there word for word. Kept here, struck, so references to "step 4" still resolve.

- ~~**Builds**: the step 1 guard called first in `handle()`, before the directory is read or any row loaded~~
- ~~**Tests**: criterion 10, both rules. Each asserts the failure wording, `assertFailed()`, an empty fake disk and every card's `image_path` unchanged~~
- ~~**Could go wrong**: on Laravel Cloud the bucket replaces the disk named `public`, so the guard must judge by driver, never by disk name. `Storage::fake` does not change the configured driver, which is why the refusal is testable at all; say so in the test's comment~~
- ~~**Acceptance**: criterion 10~~
- ~~**Docs**: Progress~~

### Step 5. `--verify` proves each picture answers the public

- **Builds**: after the run, for every matched live card with a filled `image_path` (uploaded or skipped), `Http::head(Storage::disk($media)->url($path))` with a short timeout, a `Verify` column, and failure on any status outside 2xx or a connection error. Runs on a dry run too, against what is already there. (27 Oct 2026, critique) The rows are read fresh after the run, so a `conflict` row verifies whatever picture the other writer left, and an `unchanged` or `replaced` row verifies the current path
- **Tests**: criterion 11. `Storage::fake(media, ['url' => 'https://media.test'])` so `url()` is absolute. `Http::fake` with one path 200 and one 403, `Http::assertSent` checks method `HEAD` and the exact URL. A 403 row names the likely cause (bucket not public), matching `media:check`'s warning. A card with no picture is not requested. Without `--verify`, `Http::assertNothingSent()`
- **Could go wrong**: a fake local disk with no `url` config returns a relative `/storage/...`, which `Http` cannot request; the test must pass the url. Never a real network call from the suite
- **Acceptance**: criterion 11, and criterion 12 confirmed across steps 2 to ~~5~~ (27 Oct 2026, critique) 5, including 2a, 3 and 3a
- **Docs**: Progress

### Step 6. The user's 22 pictures are received, checked and renamed (operational, no commit)

~~Step 6. The 22 named pictures are sourced (decision, then artwork): option A, the client delivers a named set; option B, we composite the names onto the plaque by script, with plaque measurements and a Cinzel 700 face taken from the frontend.~~ Dropped 27 Oct 2026: the user supplies the named set, so no script, font or plaque measurement work is done here.

This step produces files in a scratch folder, not a commit. Step 7 commits its output. ~~It can run at any time after step 2, since it uses the command's `--dry-run` as one of its checks.~~ (27 Oct 2026, critique) It depends on step 3a, since it uses the command's `--dry-run` as one of its checks, and on step 2a, since that dry run must exit non-zero on a missing file to mean anything.

- **Input**: the user gives the folder's absolute path to the lead in the session. The builder writes that path into the step's comment on issue #95 so the source is on record. The user's folder is only ever read, never renamed or edited in place
- **Copy**: every file is copied to `$TMPDIR/card-seed-images/` (on this machine `/private/var/folders/77/qz85b3hx6n72b05g5j3xq6n80000gn/T/opencode/card-seed-images/`), and all renaming happens on the copies
- **Mapping and renaming**: the builder proposes a table of original file name to `NN-slug.webp`, the number read from the original name where it carries one, otherwise left blank for the user. The slug is the card's name from `CardSeeder::MAJOR_ARCANA`, lowercased and hyphenated (`05-the-hierophant.webp`), whatever the plaque says. The user confirms the table, and the copies are renamed to it
- **Checks the builder runs and reports** (one table, one row per file): exactly 22 files; numbers 0 to 21 each exactly once; `getimagesize` says `image/webp`; width at least 640 and height at least 1060; ~~width-to-height ratio within 2% of 640:1060~~ (27 Oct 2026, critique) `abs((width / height) / (640 / 1060) - 1) <= 0.02`, with the computed value in the table; file size under 300KB. Then `php artisan cards:seed-images --source=<scratch> --dry-run` on the local database, which must exit 0 with 22 `would upload` (or `would skip` for a card already pictured locally)
- **Check the user runs**: opens the 22 renamed copies side by side and confirms by eye that each plaque's name is legible and that each file sits at the right number. No check in the repo can catch a picture filed under the wrong number, so this confirmation is required before step 7
- **On a failed check**: the builder reports the file and the rule it breaks and stops. It does not re-encode, resize or crop the artwork; the user supplies a corrected file, or tells the builder in so many words to re-encode it
- **Acceptance**: the check table is all pass, the dry run exits 0, and the user has confirmed legibility and mapping. All three recorded on issue #95
- **Could go wrong**: the originals carry no number in their names, so the mapping depends wholly on the user's confirmation. A file saved with a `.webp` extension but other contents, which the `getimagesize` check catches. A picture larger than 640x1060 that still fits under 300KB is accepted as is. (27 Oct 2026, critique) Passing the ratio check does not prove the site's frame leaves the plaque whole; that is the user's eye on staging, step 9

### Step 7. The 22 pictures are committed, and pinned

- **Builds**: the 22 renamed files from step 6's scratch folder in `database/seed-images/cards/`
- **Tests**: `tests/Feature/SeedCardImageAssetsTest.php` reads the real folder: exactly 22 matching files, numbers 0 to 21 each once, `getimagesize` says `image/webp`, ~~and 640x1060~~ (27 Oct 2026) width at least 640, height at least 1060, ~~ratio within 2% of 640:1060~~ (27 Oct 2026, critique) `abs((width / height) / (640 / 1060) - 1) <= 0.02`, each under 300KB. The test's comment says it is asset validation and does not stand for the plaque check. Then one run of the command against the real folder with a faked disk exits 0 with 22 `uploaded`
- **Could go wrong**: about 6MB of binaries in git history for good. Acceptable at this size; if the set is ever replaced, the old blobs stay
- **Acceptance**: criterion 13
- **Docs**: Progress

### Step 8. The runbook, the checklist and the checkpoint say how it is run

- **Builds**: docs only. `docs/deploy/media-storage.md` gains a section "Seeding the card pictures": run `media:check` first, then `cards:seed-images --dry-run`, then the real run, then `--verify`, what each result means (27 Oct 2026, critique: including `replaced`, `unchanged` and `conflict`, and that a conflict means Jennifer changed or deleted the card mid-run, her change stands, and a rerun shows the card's state), how to read the `--verify` table against the 22 numbers since exit 0 alone does not prove a full set, why `--force` is rare and what it leaves behind (orphaned objects are kept, nothing cleans them), and one line (27 Oct 2026): "Production is seeded by the user when the pictures land; seeding puts any card that is already written and published on the live site at once." `QA_CHECKLIST.md` section 1 gains the deploy-time rows, section 6a the panel check that a seeded picture shows and can be replaced by hand. `docs/CHECKPOINT.md` records the command under State and removes the hand-upload item from What is next if it is there. `docs/backend-components.md` lists the command. `API_CONTRACT.md` unchanged, and the step report says so
- **Tests**: none, docs only. `composer test` still green, since the suite resolves code comments that cite documents (see `tests/Fixtures/Claims/`), and a moved heading can break one
- **Acceptance**: a reader with only the runbook can seed staging
- **Docs**: the ones named

### Step 9. Staging (operational, no commit)

- `media:check`, `cards:seed-images --dry-run`, `cards:seed-images --verify`, on staging. Open three image URLs in a browser. ~~Walk the Viewing Room for a card that is published and written~~
- (27 Oct 2026, critique) Account for the `--verify` table number by number, 0 to 21, as criterion 14 states. Any `missing card` is checked against the panel's trash and named in the report as deliberately deleted, or treated as a failure
- (27 Oct 2026, critique) Pick one card that `Card::viewingRoom()` admits on staging (published, written, and a playable film), open it in the Viewing Room, see its picture, and ask the user to confirm by eye that the rendered frame does not crop the plaque. If no card is eligible on staging because no film plays yet (#112), say so in the report, check the picture through one website-available card's `image` in the API instead, and record the Viewing Room walk and the plaque check as owed to #112's staging walk
- ~~Repeat on production only after open question 4 is answered~~ (27 Oct 2026) Production is the user's, run when the pictures land, per the runbook line added in step 8. Not part of this plan
- Result, with the table output, written to issue #95 and this plan's Progress, and the banner moved to built
- **Acceptance**: criterion 14

## Decisions recorded, 27 October 2026

The user's answers to the four open questions of the first draft:

1. ~~Where do the named pictures come from? Option A (the client letters them) or option B (we composite by script).~~ The user provides a folder of the 22 pictures with the names already on the plaques. No script, no compositing. Step 6 rewritten to receive, check and rename
2. ~~What is printed on plaques V, X and XII, and in which face?~~ Whatever the user's pictures carry. No action in this repo. File slugs come from `CardSeeder::MAJOR_ARCANA` and are never compared with anything, so plaque wording cannot break the command
3. ~~The baked-in name is a deliberate reversal of the frontend's choice; accept it or seed plaque-less pictures.~~ Accepted. One line in the folder README (step ~~2~~ 2a) records the consequence
4. ~~May production run it?~~ The user runs production. One line in the runbook (step 8); step 9 and criterion 14 cover staging only

Sub-decisions made in this revision, change them at critique if wrong:

- **"About 640x1060 or larger" is pinned as**: at least 640 wide, at least 1060 tall, ratio within 2% of 640:1060. ~~The ratio rule is there so a larger picture of another shape is caught before a frame drawn at the card's ratio crops its plaque.~~ (27 Oct 2026, critique) The ratio rule is `abs((width / height) / (640 / 1060) - 1) <= 0.02` and is asset validation only: it catches a file of plainly the wrong shape, and proves nothing about how the site crops it. The plaque is checked by the user's eye on a rendered frame on staging. The 2% figure is a judgement, not a measurement
- **Slugs come from the seeded card names**, not the plaque wording (struck from the old step 6). The slug is for humans only, and this removes any need to read the plaques to name the files
- **The user's folder is never modified.** Copies are renamed in a scratch folder under the session temp directory
- **A failed check is reported, not fixed.** No re-encoding or resizing of the artwork without the user saying so
- **The user confirms the number-to-picture mapping as well as legibility**, because no automated check can tell a picture filed under the wrong number

## Open questions

None outstanding. The first draft's four questions are answered above. The sub-decisions under Contract changes marked "critique" (conflict exits non-zero, `unchanged` rewrites the object but not the row, the staging fallback when no film plays) are open to change at approval.

## Critique resolved, 27 October 2026

Critic's verdict: ready after fixes.

- **Blocking 1, concurrent edits clobbered by a stale save.** Accepted. The row is written by one conditional update keyed on id, `deleted_at IS NULL` and the observed `image_path` (or `IS NULL`), with and without `--force`, written out under Contract changes. Zero rows is `conflict`, the object is kept, the run exits non-zero (sub-decision recorded with its reason). New criterion 15, tested in step 2 (no force) and step 3 (force) by a disk double that makes the panel's change, or soft-deletes, during the write
- **Blocking 2, criterion 3 demanded a new path for identical bytes.** Accepted. Criterion 3 now covers different bytes only (`replaced`, new hash path). New criterion 16: identical bytes with `--force` keep the path and report `unchanged`, object rewritten, row not. New criterion 17: rerun after a panel replacement, with and without `--force`. Orphan retention is stated in Out of scope, including conflict leftovers; no step cleans anything
- **Should fix 1, step order.** Accepted. The guard moved into step 2, so the first writer is guarded; step 4 is struck and kept as a pointer. To keep step 2 small, set reporting moved to a new step 2a, and step 3 split into 3 (`--force`) and 3a (`--dry-run`). Step 6 now depends on 3a and 2a, which is the critic's "depend on step 3" after the split
- **Should fix 2, staging acceptance over-reached into Viewing Room eligibility.** Accepted. Criterion 14 replaced: the `--verify` table accounts for all 22 by number, deliberate deletions named, exit 0 called out as insufficient, plus one eligible card walked. Added a fallback, my own, for a staging with no playable film, which defers the Viewing Room walk to #112 rather than blocking this issue. Viewing Room eligibility added to Out of scope
- **Should fix 3, the 2% rule.** Accepted. Defined as `abs((width / height) / (640 / 1060) - 1) <= 0.02` in criterion 13, steps 6 and 7 and the sub-decision, described as asset validation only, with the plaque left to the user's eye on a rendered staging frame
