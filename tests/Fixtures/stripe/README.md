# The captured Stripe webhook vector

**Nothing in this directory has been captured yet.** `StripeWebhookVectorTest`
skips while it is empty, and it says so when it skips. A skip is a skip and is
counted as one, per the lesson of 23 August 2026: a test that never executes
looks exactly like one that passes.

## Why this exists

`tests/Feature/Api/StripeWebhookTest.php` signs its own payloads with its own
secret and `stripe-php` verifies them. That proves our plumbing, and it proves
nothing about whether Stripe's signatures look like that, because it agrees with
our own code whatever our own code does.

This is the same trap that cost five days in M4. `BunnyStreamTest` asserted our
token format against our own implementation of it, playback returned 403 for
every viewer in every environment, and the suite stayed green throughout.

**Only Stripe can say a Stripe signature is valid. So ask Stripe, by hand,
once.**

## Capturing it

You need the Stripe CLI and access to Jennifer's Stripe account in **test
mode**, as a team member with the Developer role.

1. `stripe login`
2. `stripe listen --print-secret` and keep the `whsec_...` it prints. **This is
   the CLI's own test mode secret**, not an endpoint secret from the dashboard.
   Never capture a live mode secret and never commit one
3. In one terminal:

   ```
   stripe listen --forward-to localhost:8000/api/v1/webhooks/stripe
   ```

4. Place a real order locally and pay for it with the test card
   `4242 4242 4242 4242`, so the payload carries a real `metadata.order_id`.
   Failing that, `stripe trigger payment_intent.succeeded` produces a real
   signed event with no order id, which still proves the signature
5. Capture **the exact raw body Stripe sent and the exact `Stripe-Signature`
   header it sent**. Log them from the controller before verification, or read
   them from `stripe listen`'s verbose output. Whitespace, key order and every
   byte matter, because a signature covers bytes rather than meaning

Write three files here:

| File | What goes in it |
|---|---|
| `payment-intent-succeeded.json` | The raw body, byte for byte, with no trailing newline added |
| `payment-intent-succeeded.sig` | The `Stripe-Signature` header, verbatim |
| `payment-intent-succeeded.secret` | The `whsec_` the CLI printed |

Then record the date it was captured in the test file's header.

## Regenerating this

**Send a new event through the live CLI and prove it is accepted before changing
a single byte here.** Do not recompute the signature from the code this fixture
exists to check. That turns a red test green while removing the only thing in
the repository with any authority over Stripe's format, and it is exactly how
video playback stayed broken for five days behind a green suite.

See `.claude/testing.md`, and `tests/Feature/MailchimpVectorTest.php` for the
same idea against a different vendor.
