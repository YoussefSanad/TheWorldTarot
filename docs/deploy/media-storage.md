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

**Read the Verify column against every number 0 to 21.** For staging, each row must say `uploaded`, `skipped` or `unchanged` and have a 2xx status, or say `missing card` for a deliberately deleted card checked against the panel's trash and named in the report. Resolve `missing file`, `more than one file`, `conflict` and `failed` before signing off. Exit 0 alone does not prove a full set: `missing card` exits 0 and has no picture to check. A blank Verify cell is not a successful check. A non-2xx response or `connection error` fails the run; a 403 means the bucket is probably not publicly readable. A dry run with `--verify` checks only pictures already present, not the pictures it would upload.

**Use `--force` rarely.** It replaces existing pictures, including Jennifer's hand uploads. The old objects remain in the bucket, as do objects uploaded before a conflict. Orphaned objects are kept and nothing cleans them. To try a different directory, add `--source=/path/to/folder` to each command, including the dry run and verification.

Cards 00 and 01 are placeholders until the originals arrive. Replace those source files with the originals using the same names, then rerun with `--force`, knowing it applies to every matched card and can replace hand uploads too. Check the result and verification table afterwards.

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
