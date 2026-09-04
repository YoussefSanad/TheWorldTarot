<?php

namespace App\Mail;

use App\Enums\Sender;
use App\Models\Reading;
use Illuminate\Mail\Mailables\Content;

/**
 * Telling the querent her reading was asked for, and when it will arrive.
 *
 * **The one surface allowed to make the delivery promise**, B8 (#74), and the
 * three that are not are the reason this exists at all:
 *
 * - The **buyer's receipt** does not, because on a gift line nothing is being
 *   delivered to the person reading it. B6 (#72) put the recipient's address in
 *   the slot the promise used to stand in, for that reason
 * - `GiftToRedeem` does not, because nobody has asked yet. A reading with no
 *   question is not owed on any clock
 * - `/checkout/complete/` states the window as a property of the reading rather
 *   than as a commitment, which is what `API_CONTRACT.md` asks of it, and the
 *   confirmation it renders dies with the tab. It is where `/redeem/` sends the
 *   querent once the code is spent — the frontend's third ADR carries that
 *   amendment, dated 3 September 2026 — and the record it draws from lives in
 *   one tab's `sessionStorage`
 *
 * So until this message the promise was made only on a screen she can close,
 * and to a person with no account, no order and no lookup to find it again.
 * **This is the durable record**, and it is durable because it is in her inbox
 * rather than because anything here is stored.
 *
 * ## Counted from `asked_at`, which is the whole of it
 *
 * The frontend's `CONTEXT.md` says it under **Ask**: "within 24 hours" of a
 * gift bought three months earlier is a promise about nothing. A gift parts the
 * day money arrived from the day somebody asked, so the clock starts at the
 * second one, and `Reading::dueAt()` is where that sum already lives.
 *
 * **It is asked for rather than recomputed here.** A second expression of due
 * is the fault `docs/adr/0006-one-screen-per-question.md` refuses a `due_at`
 * column over, and a mailable adding its own would go stale the day a delivery
 * window changes while the panel beside it stayed right.
 *
 * ## Both halves of the promise, and why the moment is one of them
 *
 * The window on its own is what the receipt says, and it would leave this
 * message no more durable than the screen it replaces: nothing in it would
 * record when the counting started. The moment on its own is exact and reads
 * like a machine. Together they are the same fact twice, one phrased as the
 * catalogue sells it and one she can hold us to.
 *
 * **The zone is named because this application runs in UTC** and she may be
 * anywhere. An unlabelled hour is a promise that quietly means something
 * different in every country it lands in.
 *
 * ## A document rather than a text part, since issue #85
 *
 * It was text and nothing else until 4 September 2026, which left the querent
 * holding the last plain-text message in a flow whose other two are drawn: the
 * buyer's receipt since #53 and the recipient's present since #81. She is also
 * the reader with the least standing to check any of it, for the reasons above.
 * It is drawn in the receipt's own frame now, `mail.html.frame`, and the text
 * part still sends beside it.
 *
 * ## From noreply@
 *
 * As `GiftToRedeem` is, and for the reason CLAUDE.md section 8 gives: nothing
 * here can receive a reply. Fulfillment@ is Jennifer's own mailbox and this is
 * not addressed to her. `ReadingToWrite` is the message that is.
 *
 * ## Rendered in the gift's language
 *
 * `gifts.locale`, exactly as `GiftToRedeem` is, so both messages the same
 * person receives arrive in one language. It is not the locale segment she
 * redeemed under, which would be truer to what she chose and is not reachable
 * from here: this is built inside `AskForReading`, which settlement also calls
 * and which has no request. **Nothing here is translated yet**, since this
 * application has no `lang/` directory, so today it decides date formatting
 * and nothing else.
 */
class ReadingOnItsWay extends QueuedMail
{
    public function __construct(public readonly Reading $reading)
    {
        $this->locale($reading->gift?->locale ?? config('app.locale'));
    }

    protected function sender(): Sender
    {
        return Sender::NoReply;
    }

    protected function subjectLine(): string
    {
        return 'Your reading is on its way';
    }

    /**
     * **Multipart since issue #85, and the text part is not a leftover.**
     *
     * This was a text part and nothing else until then, which left the least
     * designed message in the gifting flow with the reader least able to check
     * it. The designed body is the HTML one now, drawn in `mail.html.frame`,
     * the same document the receipt and the present are drawn in. **The text
     * one is what shipped before it, unchanged**, and it stays for the two
     * reasons `OrderPaid::content()` gives: a client that will not render
     * markup still has to be able to read this, and a message offering no
     * alternative part scores worse with the filters that decide whether any of
     * it arrives at all.
     *
     * **The promise is built once and handed to both parts.** It is the one
     * value here the two documents could come to disagree about, and a delivery
     * promise that differs between the two halves of one message is the half
     * nobody renders being the wrong one.
     *
     * **The two views obey opposite escaping rules and that is enforced by
     * path.** `mail.reading-on-its-way` is text and echoes raw.
     * `mail.html.reading-on-its-way` is markup and escapes, and her name and
     * her own typed question both reach it. `MailBodyTest` holds both.
     */
    public function content(): Content
    {
        // Loaded rather than reached for, the discipline both mailables beside
        // this one follow. A queued mailable is rehydrated from the database,
        // so the reading arrives here holding nothing but its own columns
        // however fully the sender had it loaded.
        $line = $this->reading->loadMissing('orderLine.product')->orderLine;

        return new Content(html: 'mail.html.reading-on-its-way', text: 'mail.reading-on-its-way', with: [
            'querentName' => $this->reading->querent_name,

            // **`readingName` and not `reading`, which is not a preference.**
            // Laravel merges a mailable's public properties over its `with:`
            // array, so the `$reading` this class carries wins the name and the
            // view prints the whole model as JSON where the reading should be
            // named. It is the same trap `GiftToRedeem` documents next door for
            // `$message`, one collision along, and it fails quietly rather than
            // loudly: the message still sends and still reads as prose.
            //
            // The catalogue's name, in this gift's language, because the
            // enclosing `withLocale` is open while this runs. What it falls
            // back to when a product has no name left in it is
            // `OrderLine::displayName()`'s to say, and is said there once.
            'readingName' => $line->displayName(),

            // Hers, and never `order_lines.question`, which is null on a gift
            // line. `CONTEXT.md` keeps those two apart as the question of
            // record and what the buyer typed.
            'question' => $this->reading->question,

            'promise' => $this->promise(),

            // The three the HTML part alone reads, and the text part is
            // deliberately left exactly as it was.
            //
            // **The subtitle is the catalogue's second line and not a
            // description of the reading**, the same one the receipt's card and
            // the present's card carry. Off the key rather than the product,
            // for the reason `OrderLine::displayName()` gives: a reading
            // withdrawn since it was bought is still owed, and the row naming
            // it may be gone.
            'subtitle' => $line->product_key->emailSubtitle(),
            'assets' => config('mail.assets.url'),
            'site' => rtrim((string) config('frontend.url'), '/'),
        ]);
    }

    /**
     * The delivery promise, as the one sentence she is owed.
     *
     * **Built here rather than interpolated in the view**, the way
     * `ReadingToWrite` builds who a reading is from, because it has a branch
     * and a view holding a branch this shape is where the two halves come
     * apart.
     *
     * **Null is "there is no promise to make", not a blank.** It is what
     * `ProductKey::deliveryWindow()` already means by null, and the receipt
     * omits the line rather than blanking it for the same reason: a delivery
     * promise on something with no window is not a missing value, it is a
     * sentence that should not be in the document.
     *
     * **Unreachable today and kept anyway.** Every giftable reading has a
     * window. `three-card` and `month-ahead` promise 24 hours and `in-depth`
     * promises 48, and the two keys with no window cannot be gifted, which
     * `ProductKey::isGiftable()` is what says. `Reading::dueAt()` is defensive
     * in exactly this place and for exactly this reason.
     */
    private function promise(): ?string
    {
        $due = $this->reading->dueAt();
        $window = $this->reading->orderLine->product_key->deliveryWindow();

        if ($due === null || $window === null) {
            return null;
        }

        // `translatedFormat` and not `format`, so the locale set in the
        // constructor is a fact about this message rather than a decoration.
        // The time is not translated because digits and a colon are the same
        // in every language this sells in.
        return "It will be with you within {$window}, by "
            .$due->translatedFormat('j F Y').' at '.$due->format('H:i').' UTC.';
    }
}
