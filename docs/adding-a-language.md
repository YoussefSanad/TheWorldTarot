# Adding a language, end to end

The procedure for taking a new language from nothing to publicly served. Rewritten 28 July 2026 for the rebuilt translation system, replacing a version that described a config file, translated slugs and a `?locale=` parameter, none of which exist any more.

Architecture and reasoning live in `.claude/translation.md`. This is the operational runbook. French is the worked example.

---

## The invariants

1. **Adding a language is a row, never a migration.** Translatable fields are `jsonb` keyed by language, so a new language adds a key to columns that already exist. Hard rule 4
2. **A language ships whole.** It cannot go public while anything published is untranslated, and after it is public a record appears only in the languages it is finished in
3. **Taking a language down is instant**, with no deploy

---

## Step 1, the website's own wording

**This is the part that is easy to forget and impossible to work around.** Buttons, navigation, form labels, marketing pages, FAQ and legal text all live in the frontend repository as JSON. None of it comes from this API.

So before anything here matters, the frontend partner adds their French messages and ships them. Without it, the content is translated and every button is still English.

## Step 2, the row

A developer adds it, in a migration or through tinker on the environment:

```php
App\Models\Locale::create([
    'code' => 'fr',
    'english_name' => 'French',
    'native_name' => 'Français',
    'status' => App\Enums\LocaleStatus::Draft,
    'sort_order' => 2,
]);
```

That is the whole registration. A French tab appears on every translatable resource immediately, with no code change and no migration.

**Why a developer and not the client:** a language means nothing until step 1 has shipped, and only a developer can do step 1.

## Step 3, the translations

Jennifer supplies the content, the development team types it into the French tab of each product and card. Partial work saves freely: nothing is required field by field, because writing is incremental.

Progress is visible in two places, both calculated from the copy rather than from anything anyone remembered to tick:

- The **Translated into** column on each list
- The **Languages** screen, showing "2 of 4" for French

## Step 4, review it

On the Languages screen, use **Let me review it**. That moves French to `preview`, which serves it through `/api/v1/fr/...` to a signed-in admin and 404s for everyone else.

Signed into `/admin` in the same browser, the real site can now be walked in French before any customer can reach it. This is the only thing that catches copy that is present but wrong, since completeness counts fields, not quality.

## Step 5, publish it

**Show on the website**, on the same screen. Refused while anything published is untranslated, naming what is missing. Once it succeeds, French appears in `/api/v1/languages` and its URLs answer for everyone.

## Step 6, if it goes wrong

**Take it down.** Immediate, no conditions, no deploy, and nothing is deleted. The translations are still there when you put it back.

This only works end to end because the frontend builds its language switcher from `/api/v1/languages`. If they hardcode the list, taking a language down leaves a dead link on their site.

---

## After it is live

Content added later is handled per record, not by pulling the language. A reading published tomorrow appears in English straight away and joins the French site when its French copy exists. So the two lists can differ in length, which is honest, and no page ever renders half translated.

## What a new language costs

| Step | Who | Deploy |
|---|---|---|
| Frontend JSON for the language | Frontend partner | Yes, theirs |
| The `locales` row | Developer | Not necessarily |
| Translating the content | Development team, from the client's copy | No |
| Review, publish, take down | The client, on the Languages screen | No |

Zero migrations in this repository, which is the property the design exists to guarantee.
