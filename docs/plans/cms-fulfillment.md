# Fulfilling a reading through the panel

**Written 25 August 2026. This is a decisions record, not a plan.**

It is deliberately not a plan. This milestone comes after everything else, described by the client as an improvement rather than a launch requirement, and a ten step ordered plan written today would be planning against requirements that will have moved by the time anybody reads it. Section 0.2 asks for an approved plan **before implementation**, not before a milestone is named.

What is worth writing down today is the part that is cheap now and expensive later: **this milestone collides with M7, and one of the two is going to set a table shape the other has to live with.**

---

## What it is

Today Jennifer writes the three hand written readings wherever she likes and emails them from her own mailbox. This system's only involvement is telling her one is owed, through `ReadingToWrite`.

The proposal, as described on 25 August 2026:

1. From an order in the panel, she opens a form
2. She records **the cards drawn**, however many that reading calls for
3. She writes the reading
4. The system generates a **PDF**, emails it to the customer, and **marks the order fulfilled**

`Fulfillment::ManualPdf` has been the value on all four readings since phase A, so the schema named this before anybody planned it.

**It is also the parked half of a parked document.** `docs/plans/journeys-redesign.md`, proposed 22 August and not approved, argued for delivering hand written readings through the panel rather than from a private mailbox, and gave the reason: a reading emailed from her own account is unstored, unsearchable and invisible to everything else. That argument survives the document being parked, and this milestone is where it lands.

## What it collides with

**M7 already designs the reading store.** `docs/plans/milestone-7-plan.md`, step 2:

> One table. `order_line_id` unique and restricted on delete, `customer_id`, `card_id` **nullable** and restricted, `locale`, the question captured as asked, the text, a `status`, `written_at`, `failed_at`, and the writer and voice version that produced it.

Plus a `ReadingStatus` enum of `pending`, `writing`, `ready`, `failed`, and `App\Contracts\ReadingWriter` behind `config/reading.php`.

**That is the same store this milestone needs.** The difference is only who writes the text: OpenAI in M7, Jennifer in a form here. Same row, same status machine, same delivery.

M7's plan says so itself, twice, without knowing this milestone would exist:

> *"What those readings actually are is deliberately undecided until there is a reason to decide it."*

> *"Nothing in the system holds a reference to a reading yet, so this is the last cheap moment to get the key right."*

**This milestone is that reason, and that moment is still open.**

## Decided 25 August 2026

### One reading store, and a reading has many cards

**`readings` gets a `reading_cards` pivot**, carrying position, and **M7's plan is amended to build it that way** whichever of the two ships first.

M7's `card_id` is a single nullable foreign key because M7 automates the One Card Reading and nothing else. This milestone covers the Three Card, the Month Ahead and the In Depth, which draw three, five and more. Her own page calls the Month Ahead a "5 Card Forecast". **One column cannot hold five cards.**

Two tables was the alternative and it was declined. It would mean two things called a reading, two status enums, two delivery paths, and eventually a frontend asking which kind it received. **A single table for everything of one kind is the decision this project already made for `products`**, for exactly this reason, and it has held through four milestones.

The cost is one extra table in M7, for a product that draws one card. That is the cheaper half of the trade by a wide margin, and it fixes something the current design cannot express regardless: **`card_id` singular asserts that a reading is about one card, and three of the four products it will eventually serve are not.**

### Position on the pivot, not meaning

The pivot carries an ordinal. It does not carry "past, present, future" or a month name. **What a position means is a property of the product and belongs to the layout**, and putting it in the join table would mean the database holding presentation for a document the frontend does not even render.

### The panel replaces her mailbox, and that needs her agreement

The journeys document flagged this and it is not a technical question: *"Does Jennifer accept delivering through the panel rather than from her own mail."* It costs her one step in a screen she is already in to mark an order fulfilled, and it replaces sending an email, so it is close to even. **But it is a change to how she works and it should be asked rather than assumed.**

## Still to be designed, deliberately

**What the form actually looks like is deferred**, at the client's instruction on 25 August. Specifically: whether she writes one block of prose per reading, or a passage per card, or both. That decides whether the text lives on the reading or on the pivot, so it is the next thing to settle when this milestone starts, and it is not settled now.

Everything else below is open and unargued:

- **The PDF itself.** Which library, and what a template made of her artwork looks like. Worth noting against hard rule 1: **a PDF is a document, not customer-facing UI**, and it is generated here because it is a fulfillment artifact rather than a page. That reading of the rule should be confirmed rather than assumed, because it is the first thing this repo would render that a customer sees
- **Where the PDF lives afterwards.** Attached to the mail and forgotten, or stored in the bucket and retrievable. The second is the order page idea from the parked journeys document arriving by a different route
- **What happens when she is halfway through.** A draft that survives her closing the tab, or a form that must be completed in one sitting
- **Whether the customer can be sent a corrected version**, and what that does to `fulfilled_at`

## What is free to do before this starts

**`products` needs a card count.** It carries `allows_question` and nothing that says a Month Ahead draws five, and today that number lives only in prose on her page. It is a nullable integer column, it costs nothing whenever it is added, and both this milestone and M7's draw logic want it.

Nothing else here blocks anything, and nothing else here is blocked.
