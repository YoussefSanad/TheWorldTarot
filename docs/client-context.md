# Client and commercial context

Background for tasks that touch what the client sees or expects. Not loaded automatically, read it when relevant.

## The client

Jennifer Eisenberger, creator of The World Tarot, a cinematic tarot brand. She is a designer, not a developer.

She spent five rocky months with a previous agency before this engagement, and the relationship ended badly. Reliability and visible progress matter more to her than technical cleverness. Anything she is asked to do herself must be self-explanatory.

## How this engagement started

She hired me on Upwork for a small technical audit of her existing Viewing Room. That audit found an unenforced paywall, roughly 230MB of video loading on page arrival, a mobile LCP of 21.4 seconds, no CDN, and missing security headers. It led directly to this rebuild.

**The whole commercial position rests on that audit.** Performance and access control are not incidental quality concerns here, they are the reason the project exists. A rebuild that ships with the same class of problem would be worse than not rebuilding.

## The product

- A free one-card reveal on the homepage, limited to one per session
- A members-only **Viewing Room** with 22 cinematic Living Tarot videos, unlocked by a one-time purchase
- Four paid reading products: One Card, Three Card, Month Ahead, In Depth
- A **Library** of all 22 Major Arcana with informational pop-ups
- An optional **AI fulfillment of the One Card Reading**, scoped and priced separately as a piece of work. Not a fifth product

## Fulfillment, confirmed with her

Three Card, Month Ahead, and In Depth readings are fulfilled **manually**. Jennifer receives an order notification with the customer's question, writes the reading herself, and emails it as a PDF. The system notifies and records. It does not fulfill.

The **One Card Reading is also manually fulfilled today.** The AI experience is **not a separate product**. It is a later change to how that same One Card Reading is fulfilled: instead of Jennifer writing and emailing the reading herself, it is generated and sent automatically by the system. Same product, same page, same purchase, different fulfillment.

Revised 25 July 2026. This previously recorded the AI experience as a separate product with its own page and purchase. That is no longer the position, and anything written before this date treating them as two products is stale.

Modelled as a `fulfillment` value on the product row, so switching the One Card Reading from manual to AI is a data change, not a new product. How the AI fulfillment actually works is deliberately unresolved until M7 is commissioned.

Gift a reading uses **code-based redemption**, not link-based. Decided explicitly, and it is **required at launch**, confirmed 25 August 2026.

The shape settled later and no longer matches the word "coupon", which an earlier version of this line used. **A code names a product rather than an amount or a percentage**, so it is worth the right money in every currency without anything converting, and **redeeming it is a way to pay rather than a discount on a price**. What Jennifer sees is unchanged: a GIFT A READING button on the reading page, and a code the recipient enters. `docs/plans/gifting-plan.md` has the reasoning, and `docs/CHECKPOINT.md` records what is settled.

## Assets and decisions she owns

- All 22 Living Tarot card videos and the looping back-of-card animation are supplied by her
- Design source of truth is her PSDs at 1920px artboard, 1320px content container, 150px margins. The previous agency shrank the container to 1140px, which caused a zoomed-in feel and is a known mistake to avoid
- Brand colors: Mist Silver `#D0D1D4`, Soft Cream `#FDF4DA`, Champagne `#FFF3D7`, Gold `#E4C46A`, Blue `#0A1421`
- Mailchimp is her decision and is not open for debate
- Per-currency prices are set by her, manually, in the admin panel

## Commercial frame

Eight milestones, 16 weeks, payment released on milestone completion. Scope creep is not just a schedule problem, it is unpaid work.

International expansion is already priced into the engagement and distributed across milestones. It is in scope, not a future phase.

Future native iOS and Android apps are a stated direction, which is why the API is versioned and Sanctum carries token auth from day one.

## Writing for her

- Plain language, no technical stack names, ever
- Bullet points over prose
- Never expose internal costs, day rates, or effort estimates
- No em dashes
