# QA checklist

Manual tests to run against a deployed environment after each milestone. Extend it per milestone rather than starting a new one.

Run it against **staging first**. Anything that fails, record the exact request or click path, the environment, and what happened instead. A vague report costs more to chase than the bug costs to fix.

```bash
API=https://staging-api.theworldtarot.com
```

---

## 1. The deploy itself

| Check | Expected |
|---|---|
| `curl -s -o /dev/null -w "%{http_code}" $API/up` | `200` |
| Push a commit to `main` | A deploy starts and finishes without manual steps |
| Deploy log | Migrations applied, `ProductSeeder` ran |
| Laravel Cloud, Queues tab | The managed queue exists, workers at zero, nothing failed |
| Environment variables | `APP_DEBUG` is **false** |
| Visit `$API` in a browser | No stack trace, no environment variables on screen |

**Cold start.** Leave staging idle 15 minutes, then run three times and record all three:

```bash
curl -o /dev/null -s -w "%{time_total}\n" $API/up
```

---

## 2. Admin access

| Check | Expected |
|---|---|
| `$API/admin` signed out | Redirected to the login page |
| Correct credentials | Reaches the dashboard |
| Wrong password | Refused, with no hint about which field was wrong |
| `$API/docs/api` signed out | **403** |
| `$API/docs/api` signed in as admin | The reference loads, listing `/v1/languages`, `/v1/currencies`, `/v1/{locale}/products` |

---

## 3. Readings in the admin panel

Navigation is **Readings and passes**.

| Check | Expected |
|---|---|
| Open the list | Four readings, in order, all showing as not on the website |
| Prices | `$0.00`, `€0.00`, `£0.00` with symbols |
| **Translated into** column | Empty, showing "English only" |
| Look for **Create** and **Delete** | **Neither exists.** Products are seeded, and taking one off sale is unpublishing it |
| Look for `key`, `type`, `fulfilment` | **None appear.** They are structure, not content |
| Look for a web address field | **It does not exist.** A reading's link is fixed and cannot be broken |

### Editing

| Check | Expected |
|---|---|
| Change the name, save | Saves, list shows the new name |
| Clear the name and save while unpublished | **Saves.** A half finished draft must be saveable |
| Type `19.00` into GBP, save, reopen | Still `19.00` |
| Type `19.99`, save, reopen | Still `19.99`, not `19.98` |
| Type `nineteen`, save | Refused, asking for an amount like 19.00 |
| Save without touching prices | Prices unchanged to the penny |

### Publishing

| Check | Expected |
|---|---|
| Publish with any price at `0.00` | Refused, naming the currency with no price |
| Publish with copy missing | Refused, naming what to add |
| Set all three prices and all copy, publish | Saves, shows as on the website |
| Switch to the Spanish tab | The publish toggle is disabled, telling you to switch to English |

### Languages

| Check | Expected |
|---|---|
| Open a reading, switch to Spanish and back, changing nothing | **No errors.** Looking and changing your mind is not an edit |
| Fill only the Spanish name, save | Saves. Translating is incremental |
| Switch back to English | English copy exactly as it was |
| Fill every Spanish field, return to the list | **Translated into** now shows "Spanish" |

---

## 4. The Languages screen

| Check | Expected |
|---|---|
| Open **Languages** | English and Spanish. English shows "Original", Spanish shows how many readings are done, for example "1 of 4" |
| English row | No publish, review or take-down actions. It is the original |
| Spanish, **Show on the website**, while anything is untranslated | **Refused**, naming what is missing, for example "Three Card Reading needs a name, short description and full description" |
| Spanish, **Let me review it** | Allowed at any time, even unfinished |
| Translate everything, then **Show on the website** | Succeeds |
| **Take it down** | Immediate, no conditions, and the translations survive |

---

## 5. Languages and currencies over the API

```bash
curl -s $API/api/v1/languages
curl -s -H "CF-IPCountry: GB" $API/api/v1/currencies
curl -s -H "CF-IPCountry: DE" $API/api/v1/currencies
curl -s -H "CF-IPCountry: DE" "$API/api/v1/currencies?currency=USD"
```

| Check | Expected |
|---|---|
| `/languages` | `[{"code":"en","label":"English"}]` while Spanish is unpublished |
| After publishing Spanish | Spanish appears **immediately**, with no deploy |
| After taking Spanish down | Spanish disappears immediately |
| `CF-IPCountry: GB` | `detected` is `GBP`, `DE` is `EUR`, `JP` is `USD`, absent is `USD` |
| `?currency=USD` with a German header | `USD`. **An explicit choice is never re-detected** |

---

## 6. Readings over the API

**Publish a reading with prices first, or this section reads like a failure.** Seeded readings start unpublished at zero.

```bash
curl -s $API/api/v1/en/products
curl -s $API/api/v1/en/products/one-card
```

| Check | Expected |
|---|---|
| Listing | Published readings only, in the panel's order |
| Each item | `key`, `type`, `name`, `short_description`, `allows_question`, `price` |
| Each item does **not** have | `fulfilment`, `long_description` |
| `price.amount` | A whole number, never `19.00` or `"1900"`, matching the panel to the penny |
| Single product | Same, **plus** `long_description` |
| An unpublished reading's key | **404**, not 403 |
| `/api/v1/en/products/nothing-here` | **404** |
| `/api/v1/en/products/viewing-room-pass` | **404** until the pass exists in M4 |
| `-H "CF-IPCountry: DE"` | The EUR price, unconverted |

---

## 7. Languages end to end

| Check | Expected |
|---|---|
| `/api/v1/es/products` while Spanish is being translated | **404**, for everyone |
| The same URL signed into `/admin`, with Spanish set to review | **200**, in Spanish |
| The same URL signed out, Spanish still in review | **404** |
| After publishing Spanish | **200** for everyone, Spanish copy, same `key` as English |
| `/api/v1/fr/products` | **404**. French does not exist |
| Publish a new reading in English only, with Spanish live | It appears in `/api/v1/en/products` and **not** in `/api/v1/es/products`, and `/api/v1/es/products/{key}` 404s |
| Translate it fully | It appears in Spanish as well |

---

## 8. Authentication

| Check | Expected |
|---|---|
| `/api/v1/user` with a valid token | `200` |
| Without a token, or with a nonsense one | `401` |
| `GET /sanctum/csrf-cookie` from the frontend origin | Cookie scoped to `.theworldtarot.com` |
| A credentialed request from the registered origin | Succeeds |
| The same from an unregistered origin | Blocked by CORS |

---

## 9. Things that are not bugs

- `CF-IPCountry` does nothing until Cloudflare fronts the domain. Everything falls back to USD
- A language that is not public **404s rather than falling back to English**. That is the design, not an outage
- A reading with no Spanish translation is missing from the Spanish list while present in English. Also the design
- In Spanish preview, the site's own buttons and navigation stay English until the frontend ships its Spanish wording. Only content comes from here
- `/api/v1/user` returns a raw record. It is scaffolding, not part of the contract
- Seeded readings start unpublished at zero, so `/products` is empty until one is published
- There is no Create, Delete, or web address field for readings, and no way to add a language in the panel

---

## 10. Not built yet

Cards and the Library, video playback and signed URLs, the viewing room pass, checkout and orders, member accounts, gift codes, the 24 hour rush, FAQ and legal content, the newsletter, and AI fulfilment.
