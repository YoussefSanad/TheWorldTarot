# Media storage setup

Where anything the client uploads lives: card pictures now, video thumbnails from M4. Videos themselves go to Bunny in M4 and are not this.

Written 28 July 2026, when card pictures became a requirement for a card to be on the website.

---

## The decision

**Laravel Cloud's managed bucket**, read through Laravel's stock `s3` disk. Chosen 28 July 2026 over configuring Cloudflare R2 by hand, which had been the plan.

The reason is that the platform provisions the bucket and injects its credentials into the environment, so there is no account to create, no API token to copy into a dashboard, and no endpoint to get subtly wrong. `CLAUDE.md` still names R2 as the storage, and that remains true in spirit: the managed bucket is object storage behind an S3 API, and if it ever needs replacing, the `s3` disk points at anything S3 compatible with no code change.

**Nothing in the application knows which bucket it is.** `config/filesystems.php` names one disk, `filesystems.media`, and the model stores a path rather than a URL, so moving providers is environment variables and a file copy.

## What has to be true per environment

| Environment | `MEDIA_DISK` | Why |
|---|---|---|
| Local | `public` | This machine's own filesystem. Uploads have no reason to travel |
| Staging | `s3` | Laravel Cloud's filesystem is ephemeral |
| Production | `s3` | The same |

**The failure this arrangement exists to prevent is silent.** A deployed environment left on `public` accepts every upload, displays every picture, and loses all of it on the next deploy. Nobody notices until the Library is full of broken images, by which point Jennifer has uploaded 22 of them.

## Steps

0. **`league/flysystem-aws-s3-v3` must be installed.** It is, as of 28 July 2026. Laravel Cloud refuses to deploy an application with a bucket attached and no adapter, which is how this was found: `aws/aws-sdk-php` alone is not enough, since that is the API client and this is the driver Laravel talks to it through
1. **Create the bucket** in the Laravel Cloud dashboard, one per environment. Two buckets rather than one shared, so a staging mistake cannot overwrite a production image
2. **Attach the bucket to the environment**, and set its visibility to Public. Card pictures are fetched by an ordinary `<img>` tag on a public website. The CORS settings on that screen do not apply and can be left alone

3. **Set nothing else.** There is no `MEDIA_DISK` to change and no `AWS_` variable to paste, and this is the part that cost a day to learn:

**The platform does not publish `AWS_BUCKET` or any other `AWS_` variable.** It publishes a single blob, `LARAVEL_CLOUD_DISK_CONFIG`, a JSON list where each entry names the disk it replaces:

```
{ "disk": "public", "is_default": true, "bucket": "fls-<uuid>",
  "default_region": "auto", "endpoint": "https://<account>.r2.cloudflarestorage.com",
  "url": "https://fls-<uuid>.laravel.cloud", "access_key_id": "...", "access_key_secret": "..." }
```

`config/filesystems.php` reads it and replaces the disk it names, so `public` is a local directory here and the bucket there, with no per-environment setting at all. The endpoint gives away that the managed bucket is Cloudflare R2 underneath, and `auto` is R2's region convention.

4. **Deploy after attaching.** Environment changes reach the application on a new deployment, not when they are saved, so a console command run against the previous deployment reports everything missing while the dashboard plainly shows it set. That is the most confusing state in this whole setup

5. **Run the check** from the environment's console:

```bash
php artisan media:check
```

It writes a file, reads it back, prints the public URL, deletes the file, and fails outright if a deployed environment is writing to its own filesystem.

6. **Open the URL it prints in a browser.** A 403 means the bucket is not public, from step 2. The command cannot tell the difference from the inside: writing works, reading works, and every visitor sees a broken image

## Seeding the card pictures

Run these by hand from the environment's console, after attaching the bucket and deploying. The default source is `database/seed-images/cards/`, with one `NN-slug.webp` file for each card number 00 to 21. The number matches the card; the slug is for people, not a match on its name. The command does not create cards and does not touch deleted cards.

1. Run `php artisan media:check` first and stop if it fails. Its temporary file is deleted before the command exits, so use the Verify column below to check public access
2. Run `php artisan cards:seed-images --dry-run`. Read all 22 rows and resolve missing or duplicate files before proceeding. This writes no objects and changes no cards
3. Run `php artisan cards:seed-images`. It uploads pictures for cards without one and leaves existing pictures alone
4. Run `php artisan cards:seed-images --verify`. This is another real run, not a read-only check: it uploads for any matched card still without a picture, then sends a HEAD request to the current public picture URL of each matched live card with a picture

Production is seeded by the user when the pictures land; seeding puts any card that is already written and published on the live site at once.

**Read the Result column:**

| Result | Meaning |
|---|---|
| `uploaded` | A card with no picture got the seed picture |
| `skipped` | The card already had a picture, so it was left alone |
| `replaced` | With `--force`, the seed picture replaced the existing path |
| `unchanged` | With `--force`, the seed path already matched. The object was written again at the same path, but the card row was not changed. In a dry run nothing is written |
| `would upload`, `would skip`, `would replace` | The action a dry run would take. `would replace` requires `--force` |
| `conflict` | Jennifer changed the picture or deleted the card during the upload. Her change stands, the uploaded object is kept, and the run exits non-zero. Rerun without `--force` to see the card's state: normally `skipped` after her replacement or `missing card` after her deletion |
| `missing card` | The file has no live card at that number, including a card in the trash. Nothing is uploaded for it and the run still exits 0 |
| `missing file` | No matching file for that number. The run exits non-zero |
| `more than one file` | More than one file has that number. Nothing is uploaded for it and the run exits non-zero |
| `failed: <reason>` | The file could not be read or hashed, or the upload failed. The run exits non-zero |

Stray files, including names outside the pattern or numbers outside 00 to 21, are listed in one warning and ignored. They do not fail the run; `.gitkeep` is silent. The folder's README is a stray too.

**Read the Verify column against every number 0 to 21.** For staging, each row must say `uploaded`, `skipped` or `unchanged` and have a 2xx status, or say `missing card` for a deliberately deleted card ~~checked against the panel's trash~~ (6 Nov 2026) checked in the trash with tinker, see "A card that says missing card" and named in the report. Resolve `missing file`, `more than one file`, `conflict` and `failed` before signing off. Exit 0 alone does not prove a full set: `missing card` exits 0 and has no picture to check. A blank Verify cell is not a successful check. A non-2xx response or `connection error` fails the run; a 403 means the bucket is probably not publicly readable. A dry run with `--verify` checks only pictures already present, not the pictures it would upload.

**A card that says `missing card`.** Added 6 November 2026, after card 0 said it on staging. It means no card with that number is live. **The panel cannot show a deleted card**: the Cards screen can delete one but has no view of deleted cards and no restore, and `CardSeeder` never recreates a deleted card, so a card deleted in the panel stays missing on every deploy. Check from the environment's console:

```sh
php artisan tinker --execute="echo App\Models\Card::onlyTrashed()->where('number', 0)->get(['id','number','deleted_at','name'])->toJson(JSON_PRETTY_PRINT);"
```

- **One row with a `deleted_at` date**: it is in the trash. If it was deleted on purpose, leave it and name it in the report as deliberately deleted. If not, restore it, keeping what it held: `php artisan tinker --execute="App\Models\Card::onlyTrashed()->where('number', 0)->first()?->restore();"`, then rerun both commands
- **`[]`**: it was deleted for good. `php artisan db:seed --class=CardSeeder --force` recreates it with an empty description, then rerun both commands
- Replace `0` with the card's number

**Use `--force` rarely.** It replaces existing pictures, including Jennifer's hand uploads. The old objects remain in the bucket, as do objects uploaded before a conflict. Orphaned objects are kept and nothing cleans them. To try a different directory, add `--source=/path/to/folder` to each command, including the dry run and verification.

Cards 00 and 01 are placeholders until the originals arrive. Replace those source files with the originals using the same names, then rerun with `--force`, knowing it applies to every matched card and can replace hand uploads too. Check the result and verification table afterwards.

## Seeding the card copy

Added 6 November 2026 (#95). The first English short and long description of the 22 Major Arcana come from one committed file, `database/seed-copy/cards.en.json`, keyed by card number `"0"` to `"21"`, and are written by `php artisan cards:seed-copy`. Run it by hand from the environment's console, **after the pictures**. No deploy, seeder or composer script runs it, and `DatabaseSeeder` leaves card copy alone.

**Seeding the copy puts every card that has a picture on the live site at once, including cards 00 and 01 with their placeholder pictures. There is no `--except`.** A card is on the website once it is published, written in English and pictured, and `is_published` is true by default.

1. Run `php artisan cards:seed-copy --dry-run`. It decides every card exactly as a real run would and writes nothing: no row, no backup. Read all 22 rows. Note every `keep` cell and the text that card holds in the panel's English tab, because a `keep` field is left as it is
2. Run `php artisan cards:seed-copy`. Without `--force` it fills only empty English fields; text already there is kept
3. Run it once more. Every card should say `unchanged`, or `kept` for a card with a kept field. Nothing is written
4. **Account for every number 0 to 21.** Each row must say `written`, `kept` or `unchanged`, or `missing card` for a card deliberately deleted, ~~checked against the panel's trash~~ (6 Nov 2026) checked in the trash with tinker, see "A card that says missing card" and named in the report. Exit 0 alone does not prove a full set: `missing card` exits 0
5. Check `GET /api/v1/en/cards`: it lists exactly the cards whose `Live?` said `yes`

The command never writes a name, another language, `is_published`, a picture or a deleted card. It matches cards by number only.

**Card 5's name is changed by hand.** Its picture's plaque reads The High Priest, and the seeder names it so on a fresh deck, but an existing card keeps the name it has, because neither `CardSeeder` nor this command writes a name to an existing row. On staging and production, open card 5 in the panel and set the English name to `The High Priest`, and the Spanish name to `El Sumo Sacerdote` if it has one.

**Read the columns:**

| Cell | Meaning |
|---|---|
| `fill` (Short or Long) | The English field is empty and will be written from the file |
| `keep` | The English field holds other text and is left alone. Only `--force` replaces it |
| `replace` | With `--force`, the English text differs from the file and will be replaced |
| `same` | The English text already equals the file |
| `written` | The card's row was written |
| `would write` | A dry run: a real run would write this card |
| `kept` | Nothing to fill, and at least one field was kept. No write |
| `unchanged` | Both fields already equal the file. No write |
| `conflict` | Someone edited the card's descriptions, in any language, or deleted it, while the command ran. Their change stands, nothing was written for it, and the run exits non-zero. Rerun to see the card's state |
| `missing card` | No live card has that number, including a card in the trash. Nothing is written and the run still exits 0 |
| `no entry` | The file has no entry for that number. Nothing is written for it and the run exits non-zero |
| `failed: unexpected value in <field>` | The field holds a value the command does not rewrite (for example a bare string instead of a set of languages). Nothing is written for that card and the run exits non-zero. Fix the card in the panel |
| `Live?` `yes` | The website shows this card now (on a dry run: would show it after the run) |
| `Live?` `no: ...` | What the card still lacks, in the panel's words, for example `no: a picture` or `no: to be published`. Empty for `missing card` |

A file that is missing, unreadable, not valid JSON, not an object, or holds an entry without two non-blank strings `short` and `long` is refused before any card is read, with one error naming the entry and field. Keys that are not 0 to 21 are listed in one warning and ignored.

**`--force` replaces English copy, Jennifer's included.** Use it only on an explicit decision to overwrite what she wrote. Every English field that differs from the file becomes `replace`. A conflict still wins: her edit made during the run stands.

**The backup a forced run makes, in full.** Before any row is written, a forced run that will write at least one card saves the old values of every card it is about to write to `storage/app/private/seed-copy-backups/cards-copy-<YYYYmmdd-HHMMSS>.json` on the server's `local` disk. It creates the file exclusively, writes it, reads it back and compares the bytes, and only then writes rows. If any of that fails, it writes no row, removes the half-written file and exits non-zero. It prints the backup's absolute path and a warning.

- **Every deploy wipes the `local` disk.** So straight after a forced run, and before any deploy, copy the backup off the server to a private place off the host (not the repository, not a public bucket). The run is not finished until that is done, and the report says where it went
- **Reading it.** `cards` lists, for every card written, its `id`, its `number` and both fields whole, every language, exactly as they were before the run. `taken_at`, `environment` and `file` say when, where and from what
- **Restoring from it.** There is no restore command. Per card, find the row by `id` and check its `number` matches, then write both fields back whole: in the panel, each language tab's short and long description; or in tinker, `Card::find($id)->setTranslations('short_description', [...])->setTranslations('long_description', [...])->save()` with the values from the backup
- A forced run that writes nothing makes no backup, and a run without `--force` never does

## Verifying it end to end

The only test that actually proves this is a deploy.

1. Upload a picture to a card in the panel. It appears in the list and the card goes on the website
2. Deploy again
3. Reload the panel

If the picture survives, the disk is genuinely the bucket. If it vanished, `MEDIA_DISK` did not take effect and the upload went to the ephemeral filesystem.

## Things that are not problems

- **No bucket CORS is needed.** Uploads go browser to application to bucket, server side, and an `<img>` tag needs no CORS to load a picture
- **`media:check` printing a `localhost` URL locally** is correct. That is the `public` disk doing what it should
- **An empty Library on staging** is expected until pictures are uploaded. A card is on the website only once it has both its copy and its picture

## If uploads fail with a checksum error

Recent AWS SDK versions send flexible checksum headers that some S3-compatible providers reject. It presents as an upload failing outright rather than as a permissions error. The fix is a disk-level option rather than anything to do with credentials, so bring the exact error rather than changing keys.
