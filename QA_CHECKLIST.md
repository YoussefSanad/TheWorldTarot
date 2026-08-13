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
| Deploy log | Migrations applied, `ProductSeeder` and `CardSeeder` ran |
| Laravel Cloud, Queues tab | The managed queue exists, workers at zero, nothing failed |
| Laravel Cloud, scheduler | It runs `schedule:run` **every minute**. The frequency of each task lives in the code, so a daily tick would make every scheduled task daily |
| Environment variables | `APP_DEBUG` is **false**. `MEDIA_DISK` is not set at all: the attached bucket replaces the disk it names |
| `php artisan media:check` from the environment's console | Passes, names the bucket, and prints a public URL |
| `php artisan config:cache`, then `media:check`, then `config:clear` | It says the cache is hiding everything, rather than listing variables as missing. This branch cannot be covered by an automated test |
| That URL, opened in a browser | Loads. A **403** means the bucket is not publicly readable |
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
| `$API/docs/api` signed in as admin | The reference loads, listing `/v1/languages`, `/v1/currencies`, `/v1/{locale}/products`, `/v1/{locale}/cards`, and the member and order routes: `/v1/register`, `/v1/login`, `/v1/me`, `/v1/orders` |

---

## 3. Readings in the admin panel

Navigation is **Readings and passes**.

| Check | Expected |
|---|---|
| Open the list | Four readings, in order, all showing as not on the website |
| Prices | `$0.00`, `€0.00`, `£0.00` with symbols |
| **Translated into** column | Empty, showing "English only" |
| Look for **Create** and **Delete** | **Neither exists.** Products are seeded, and taking one off sale is unpublishing it |
| Look for `key`, `type`, `fulfillment` | **None appear.** They are structure, not content |
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
| Each item does **not** have | `fulfillment`, `long_description` |
| `price.amount` | A whole number, never `19.00` or `"1900"`, matching the panel to the penny |
| Single product | Same, **plus** `long_description` |
| An unpublished reading's key | **404**, not 403 |
| `/api/v1/en/products/nothing-here` | **404** |
| `/api/v1/en/products/viewing-room-pass` | **404** until the pass exists in M4 |
| `-H "CF-IPCountry: DE"` | The EUR price, unconverted |

---

## 6a. Cards in the admin panel

Navigation is **Tarot cards**.

| Check | Expected |
|---|---|
| Open the list | 22 cards, numbered 0 to 21, in deck order, none on the website, no pictures |
| Above the list | "Spanish: 0 of 22 translated" |
| Open a card with no picture | It says it needs a picture |
| Upload a picture on a written card | It appears in the list and the card goes on the website |
| Upload a picture, then deploy again and reload | **The picture is still there.** If it vanishes, the environment is writing to its own filesystem instead of the bucket |
| Switch to the Spanish tab | **The picture section is not there.** One picture serves every language |
| Save on the Spanish tab, then return to English | The picture is unchanged |
| **Add a card** | Exists. This is the only screen in the panel with it |
| Add a card with no name | Refused, saying to give it a name first |
| Add a card numbered the same as an existing one | Refused, saying there is already a card with that number |
| Add a card with a name and a number | Saved, and the notice says to write its description next |
| While adding | **No language tabs.** A card is added in English and translated afterwards |
| Look for a web address field | **It does not exist.** A card is a pop-up, not a page |
| Look for a switch to take a card off the website | **It does not exist.** It is on the website once it is written |
| Write both descriptions | The list shows it as on the website |
| Empty the English name and save | Refused, saying to change the wording rather than remove it |
| Rename a card | Allowed, and nothing about its link changes, since it has none |
| **Remove** a card, then add one with the same number | Both allowed. The number is free again |

### Translating a card

| Check | Expected |
|---|---|
| Switch to the Spanish tab, write only the name, save | Saved without complaint |
| Switch back and forth between tabs | No errors either way |
| The summary line | Moves as cards are finished, counting cards only, not readings |
| Finish one card in Spanish | **Translated into** shows Spanish on that row only |

---

## 6b. Cards over the API

**Write a card's name and both descriptions first, or this section reads like a failure.** Seeded cards start with a name and no copy.

```bash
curl -s $API/api/v1/en/cards
curl -s $API/api/v1/en/cards/1
curl -s -D- $API/api/v1/en/cards/draw
```

| Check | Expected |
|---|---|
| Listing | Written and pictured cards only, in number order, lowest first |
| Each item | `id`, `number`, `name`, `short_description`, `long_description`, `image` |
| `image` | An absolute URL that loads in a browser, never `null`. If it 403s, the bucket is not public |
| A card with no descriptions | Absent from the listing, and **404** by its id |
| A card with no picture | The same. Absent, and **404** by its id |
| Single card | Identical to its entry in the listing, field for field |
| `/api/v1/en/cards/999` | **404** |
| `/api/v1/en/cards/the-fool` | **404**. Cards are addressed by number, not by name |
| `/api/v1/en/cards/draw`, ten times | Different cards, only ever ones that are written and filmed |
| Its `Cache-Control` header | Contains **`no-store`** |
| The listing's `Cache-Control` | Does **not** contain `no-store` |
| `/api/v1/en/cards/draw` with nothing written yet | **404** |
| Its `video.url` | Plays. It is the full film, served publicly, by decision. Same handling rules as section 6d, read the note there first |
| That URL with one character of its token changed | **403 from Bunny** |
| A segment under that URL's prefix | **200.** The manifest alone proves nothing |
| `/api/v1/en/cards/draw` with cards written but no films uploaded | **404**, never a card with nothing to play |
| The listing and a single card | **No `video` field on either.** A signed URL in a cacheable response is the fault the separate resource exists to prevent |
| `/api/v1/en/cards/draw` thirty-one times inside a minute | **429** on the last one |

---

## 6c. Video in the admin panel

| Check | Expected |
|---|---|
| Upload a video on a card, save | Returns immediately, status shows **Waiting to be sent**, no spinner |
| Within a minute | Status becomes **Being prepared**, and the video appears in the Bunny library named like `0. The Fool` |
| Once Bunny finishes | Status becomes **Ready to watch** with no action from you. If it does not, the webhook URL is not registered in that library |
| `php artisan cards:reconcile-videos` | Finishes anything the callback missed. It should normally print "Nothing is waiting" |
| Remove the video from a card that is ready, save | Status goes back to **None yet**, the card leaves the Viewing Room, and the film is gone from the Bunny library and the master from the private bucket within a minute |
| The same card afterwards | Still **on the website**. Removing a film never takes a card out of the Library |
| Upload a replacement, wait for it to be ready | The superseded film is gone from the Bunny library, and the old master is gone from the bucket |
| Upload a replacement on a card that is ready | Status returns to being prepared, and **the card stays in the Viewing Room the whole time** |
| Upload something that is not a video | Refused, saying an MP4 or QuickTime file is expected |
| Switch to the Spanish tab | **No video field.** One film serves every language |

---

## 6d. The Viewing Room over the API

**Since M5 you can sign in as a customer rather than minting a token by hand**, and a pass holder is made by settling an order in the panel. Section 12 is how. Doing it that way is better than a hand-made token, because it walks the path a real customer walks.

> **Read this before testing a playback URL, or every check below fails and tells you nothing.**
> The CDN refuses requests with no referrer. So:
>
> - **Pasting the URL into a browser tab always 403s.** No referrer. This is not a bug and it is not a broken token
> - Test with `curl -e 'https://theworldtarot.com/' "$URL"`, or from a page
> - **Check a segment, not just the manifest.** A wrongly scoped token returns 200 for `playlist.m3u8` and 403 for everything it points at, which looks exactly like success. This is what M4 got wrong and nobody caught for five days
> - **URLs are no longer bound to the viewer's address**, reversed 7 August 2026 because it never worked for anybody with IPv6. A URL fetched on one machine now works on another, and their life is thirty minutes rather than two hours

| Check | Expected |
|---|---|
| A pass holder asks for a ready card's video | **200**, with `url` and `expires_at` about **thirty minutes** out |
| The response's `Cache-Control` | Contains **`no-store`**. It is a credential, not content |
| Fetch the `url` with a referrer | **200**, and the body starts `#EXTM3U` |
| Fetch a rendition, `<prefix>/360p/video.m3u8` | **200.** If this 403s while the manifest works, the token is scoped to the file rather than the directory |
| Fetch the first segment the rendition lists | **200.** This is the check that proves playback rather than implying it |
| **Ask for the URL over IPv6, fetch it over IPv4** | **200.** This is the exact combination that was broken for five days, and it is the one worth walking deliberately rather than by accident |
| The same URL with one character of the token changed | **403 from Bunny.** This is what proves it is signed rather than merely long |
| The same URL with `expires` set to a past timestamp | **403 from Bunny** |
| The same URL from a different machine or network | **200**, within its thirty minutes. It is not bound to whoever asked, deliberately, and the short life is what limits a copied link |
| No token at all | **401** |
| A signed-in customer with no pass | **403**, not 404 |
| An admin session | **401.** Running the panel is not the same as holding a pass |
| A card still being prepared, or whose film was removed | **404** |
| A card that is not on the website in that language | **404** |

---

## 7. Languages end to end

| Check | Expected |
|---|---|
| `/api/v1/es/products` while Spanish is being translated | **404**, for everyone |
| The same URL signed into `/admin`, with Spanish set to review | **200**, in Spanish |
| The same URL signed out, Spanish still in review | **404** |
| After publishing Spanish | **200** for everyone, Spanish copy, same `key` as English |
| `/api/v1/fr/products` | **404**. French does not exist |
| Publish a new reading in English only, with Spanish live | Spanish is **taken down**: it leaves `/api/v1/languages`, and `/api/v1/es/products` 404s |
| Add a card in English only, with Spanish live | The same. A card counts exactly as a reading does |
| Translate it fully, then put Spanish back from the Languages screen | Both languages serve again |

---

## 8. Authentication, and the wall between the two kinds of account

| Check | Expected |
|---|---|
| `GET /api/v1/me` with a valid token | `200`, the customer |
| Without a token, or with a nonsense one | `401` |
| `GET /api/v1/user` | **404.** It was framework scaffolding and it is gone. `/me` replaces it |
| `GET /sanctum/csrf-cookie` from the frontend origin | Cookie scoped to `.theworldtarot.com` |
| A credentialed request from the registered origin | Succeeds |
| The same from an unregistered origin | Blocked by CORS |

**The guard isolation, which has existed since M1 and had never been walked from the customer side.** Both halves matter, and a failure of either is serious rather than cosmetic.

| Check | Expected |
|---|---|
| Sign into `/admin`, then call `/api/v1/me` in the same browser | **401.** An admin session is not a customer |
| A customer bearer token against `/admin` | The admin login page, never the panel |
| A customer session against `/admin` | The same. Buying a pass does not make somebody staff |
| An admin session against `/api/v1/{locale}/cards/{id}/video` | **401**, as section 6d says |

---

## 9. Transactional email

**Nothing here is provable from the test suite.** Mail that is queued, formatted and asserted against our own mailables can still be silently discarded by a misconfigured transport, and the environment reports success either way. This section exists because that failure is invisible from the inside.

| Check | Expected |
|---|---|
| `php artisan mail:check you@example.com --now` from the environment's console | Sends on the spot, and **the message actually arrives** |
| If it warns that the mailer is `log` | The environment is discarding every message. `MAIL_MAILER` is wrong, and nothing below can pass |
| `php artisan mail:check you@example.com` with no `--now` | Queued, and it arrives shortly after. If `--now` arrives and this does not, the transport is fine and **the queue worker is the problem** |
| The sender address on what arrives | `support@theworldtarot.com`, on a verified domain |
| Where it landed | The inbox rather than spam. A domain missing SPF or DKIM sends perfectly well into junk folders |
| Reply to it | **Goes nowhere, silently, and that is the current state.** Sending is verified, receiving is not. A support address that discards replies is worse than none, so this needs settling before customer mail goes out at volume |

---

## 10. Member accounts over the API

Run this section from a browser on the real frontend origin as well as from `curl`. **The two prove different things**, and the browser is the one that has never been walked: cookies scoped to a shared root domain, `credentials: "include"` and CSRF only genuinely interact on the actual origins.

### Registering and signing in

| Check | Expected |
|---|---|
| `POST /api/v1/register` with a good name, email and matching passwords | **201**, the customer, `has_viewing_room_access` false |
| The response | **No `token` field**, since no `device_name` was sent. A session cookie instead |
| The same again with `device_name` | **201** with a `token`. This is the native app path |
| Register with an email that already exists | **422** on `email` |
| Register with `Jane@example.com` after `jane@example.com` | **422.** Case does not make a second account |
| Register with a weak password, or mismatched confirmation | **422** on `password` |
| `POST /api/v1/login` with the right password | **200**, the customer |
| Sign in with a wrong password | **422**, and note the exact message |
| Sign in with an address that has no account | **422, byte for byte the same message and status.** Any difference makes this a way to ask who has an account here |
| Six wrong passwords in a row for one email | **429** with `Retry-After`, not 422 |
| Wait it out, then sign in correctly | **200.** The lockout is per email and address pair, and it clears |
| `GET /api/v1/me` signed in | **200**, `Cache-Control: no-store` |
| `POST /api/v1/logout`, then `/api/v1/me` | **204**, then **401** |
| On the token path, sign in on two devices and log out of one | The other **still works**. Signing out of a phone does not sign out a laptop |

### In a browser, on the frontend origin

| Check | Expected |
|---|---|
| `GET /sanctum/csrf-cookie`, then register with `credentials: "include"` | Succeeds, and a session cookie is set on `.theworldtarot.com` |
| A page refresh, then `/api/v1/me` | **Still signed in.** The cookie survives, which is the whole point of the shared root domain |
| The same flow from an origin we have not registered | Fails as what looks like a network error, or a **422 on `device_name`** saying this origin cannot hold a session. Both mean the origin is not on the stateful list |
| Sign out, then a write without fetching the CSRF cookie again | **419.** Logging out rotates the token, so the frontend must fetch a fresh one |

---

## 11. Passwords, and claiming an account

| Check | Expected |
|---|---|
| `POST /api/v1/forgot-password` with a real address | **200**, and the mail arrives with a link to the **website**, not to this API |
| The same with an address that has no account | **200, identical body.** No mail is sent, and nothing about the response says so |
| The same address twice within a minute | **200 again**, and only one message arrives |
| Seven requests in a minute from one address | **429** |
| Follow the link and set a new password | **200**, and the new password signs in |
| Use the same link a second time | **422.** Single use |
| A link older than 60 minutes | **422** |
| A token from one account with another account's email | **422** |
| Any bearer token that customer held, after a reset | **Refused.** A reset deletes them all |

---

## 12. Buying something, and the walk that proves the Viewing Room

**This is the section that closes the item carried since M4**: the Viewing Room path had never been walked in a deployed environment, because there was no way to make a pass holder. There is now.

### Placing an order

| Check | Expected |
|---|---|
| `POST /api/v1/orders` as a guest, with name, email, currency and one reading | **201**, `status` `pending`, `payment_url` **null** |
| The amounts | Integer minor units, and `total_amount` is the sum of the lines |
| **Your inbox** | **Nothing at all.** Placing an order sends no mail, deliberately, or the endpoint is an email bomb aimed at whatever address is typed into it |
| The admin panel, Orders | The order is there, awaiting payment |
| Order again with the same email | **One customer**, two orders. No second row |
| Order with a question on a reading that takes one | **201**, question stored |
| Order with a question on the pass | **422** on `lines.0.question` |
| Order with one good line and one unpublished product | **422**, the whole order refused, keyed to the bad line. No order is created |
| Order in a currency we do not sell in | **422**, not a fallback to dollars |
| Order while signed in, sending somebody else's email | The order attaches to **your own account**, and the email is ignored |
| Change a product's price in the panel, then look at the order again | **The order still shows what was charged.** Prices are captured, never re-read |

### Settling it by hand, as Jennifer would

| Check | Expected |
|---|---|
| Orders in the panel | Read only. **No Create, no Delete, no way to edit any money** |
| The sidebar badge | Counts readings waiting to be written |
| **Mark as paid** on a pending order | Confirms first, then the status becomes **Paid** |
| The same action afterwards | **Gone.** An order can only be paid once |
| The customer's record | Viewing room access is stamped, if the order held the pass |
| Mark as paid a second time by any route | Access is granted **once**, the first date is kept, and no second email goes out |
| An order holding only the pass | **Already fulfilled**, with no work left on her list. It delivered itself the moment it was paid |
| An order holding a reading | **Awaiting fulfillment**, and **Mark as fulfilled** appears |
| The wording throughout | Hers: "Awaiting payment", "Paid", "Fulfilled". Never "settled", "pending" or "access grant" |

### The mail that being paid sends

| Check | Expected |
|---|---|
| The customer's inbox | An order confirmation from `orders@theworldtarot.com` |
| A buyer who had **no account** | Also a set-a-password invitation from `support@theworldtarot.com` |
| A buyer who **already had an account** | **The confirmation only.** No claim link, ever, or a stranger who knows an address can cause a password mail to land in it |
| Jennifer's inbox, for an order with a reading | One notification from `fulfillment@theworldtarot.com` carrying the question |
| Jennifer's inbox, for an order with only the pass | **Nothing.** There is no work to do |
| An order with two readings | **One message**, not two |

### Claiming the account

| Check | Expected |
|---|---|
| Try to sign in as the unclaimed customer before following the link | **Refused**, with the ordinary indistinguishable message |
| Try every other route into that account: reset password, then sign in with an empty password, then any credential at all | **Refused every time.** An account with no password cannot be entered, and this is the invariant the whole flow rests on |
| Follow the set-password link | The password is set, and it signs in |
| Follow the same link again | **422.** It works once, and afterwards it is worth nothing even inside its hour |
| Ask for a **reset** on the same account now | Works normally. Once claimed, it is an ordinary account |

### The walk itself, in a browser, on the real frontend origin

Do this in one sitting, in this order, and record where it breaks:

1. Make an account on the website
2. `GET /api/v1/me` says `has_viewing_room_access` is **false**
3. Ask for a card's video: **403**, not 404
4. Sign out, ask again: **401**
5. Place an order for the viewing room pass
6. Mark it paid in the panel
7. The confirmation email arrives
8. `GET /api/v1/me` now says **true**
9. Ask for the video: **200**, and **the film actually plays in a page**, manifest, rendition and segment
10. A card with no film: **404**

---

## 13. Things that are not bugs

- `CF-IPCountry` does nothing until Cloudflare fronts the domain. Everything falls back to USD
- A language that is not public **404s rather than falling back to English**. That is the design, not an outage
- A reading with no Spanish translation is missing from the Spanish list while present in English. Also the design
- In Spanish preview, the site's own buttons and navigation stay English until the frontend ships its Spanish wording. Only content comes from here
- Seeded readings start unpublished at zero, so `/products` is empty until one is published
- There is no Create, Delete, or web address field for readings, and no way to add a language in the panel
- Seeded cards arrive named, unwritten and unpictured, so `/cards` is empty until both are supplied
- A card can be on the website and not in the Viewing Room. Copy and a picture put it in the Library; a prepared video puts it in the Viewing Room. A poster frame was a third requirement until 7 August 2026, when it left the system
- A pass holder can save a video they are watching. Signing stops people without a pass, not the person holding one, and that was decided knowingly
- **A playback URL now works from a machine other than the one that asked for it**, within its thirty minutes. Binding it to an address was tried and refused every visitor with IPv6, so the short life is what limits a copied link instead
- **An order sits at "Awaiting payment" until somebody marks it paid in the panel.** Nothing collects money yet, so this is the whole checkout today rather than a stuck order
- **`payment_url` comes back as null on every order.** There is nowhere to send anybody to pay
- **Placing an order sends no email.** The first message goes out when it is paid, so that typing an address into a checkout cannot be used to mail somebody
- **A buyer who already had an account is never sent a set-a-password link**, only the confirmation. That is the phishing rule working
- **An order for the pass alone shows as fulfilled immediately**, with nothing on Jennifer's list. It delivered itself
- Somebody can buy without an account, and the account created for them cannot be signed in to until they follow the link that arrives once it is paid
- A reply to any of our addresses goes nowhere. Sending is verified, receiving is not set up
- The viewing room pass counts toward translation like everything else, so Spanish needs its copy before Spanish can go live
- Adding anything, a card included, takes a live language down until it is translated. That is the rule working, not a fault

---

## 14. Not built yet

Card payment and the checkout redirect, receipts, VAT, refunds, gift codes, the 24 hour rush, reading an order back over the API, FAQ and legal content, the newsletter, and AI fulfillment.
