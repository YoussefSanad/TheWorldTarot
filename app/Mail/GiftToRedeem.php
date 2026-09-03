<?php

namespace App\Mail;

use App\Enums\Sender;
use App\Models\Gift;
use App\Support\FrontendUrl;
use Illuminate\Mail\Mailables\Content;

/**
 * Telling somebody they have been given a reading, and how to spend it.
 *
 * **The only message this application sends to a person who has never bought
 * anything**, at an address a third party typed for them. Everything unusual
 * about it follows from that.
 *
 * ## The subject names who it is from
 *
 * An unsolicited mail carrying a code and a link, from a brand the reader has
 * never heard of, is shaped exactly like phishing. **A named sender is the
 * strongest signal it is real**, and the signature is the only name in this
 * transaction the recipient can be expected to recognise: it is what the buyer
 * signs the present with rather than what is on their card, so "Mum" is a truer
 * answer than "Jodie O'Reilly" and both are what the migration says that column
 * is for.
 *
 * ## The buyer's address is not in here
 *
 * Not the address, not the name, nothing but the signature they chose. A
 * present is not an introduction between two people, and the buyer typed the
 * recipient's address rather than the other way round.
 *
 * ## Rendered in the gift's language
 *
 * `gifts.locale` and not the buyer's, because the person this is sent to never
 * chose it, and an order carries no language of its own. Set in the constructor
 * rather than at the call site, so a second caller cannot send this one in
 * whatever language the request happened to be in. **Nothing here is translated
 * yet**: this application has no `lang/` directory and every mail view in it is
 * English, so today this decides date formatting and nothing else. It is set
 * anyway because the day the copy is translated is not the day anybody should
 * be working out which locale a gift belonged to.
 *
 * ## A document rather than a text part, since issue #81
 *
 * It was text and nothing else until 3 September 2026, which made this the
 * least branded message the application sent and the one going to the person
 * with the least reason to trust it. It is drawn in the receipt's own frame
 * now, `mail.html.frame`, and the text part still sends beside it.
 *
 * ## From noreply@
 *
 * The same address the receipt leaves from, and for the same reason: nothing
 * here can receive a reply, per CLAUDE.md section 8. Fulfillment@ is Jennifer's
 * own mailbox and this is not addressed to her.
 */
class GiftToRedeem extends QueuedMail
{
    public function __construct(public readonly Gift $gift)
    {
        $this->locale($gift->locale);
    }

    protected function sender(): Sender
    {
        return Sender::NoReply;
    }

    protected function subjectLine(): string
    {
        return "{$this->gift->signature} has sent you a reading";
    }

    /**
     * **Multipart since issue #81, and the text part is not a leftover.**
     *
     * This was a text part and nothing else until then, which made the least
     * branded message this application sends the one going to the only person
     * in it who has never bought anything. The designed body is the HTML one
     * now, drawn in `mail.html.frame`, the same document the receipt is drawn
     * in. The text one is what shipped before it, **unchanged**, and it stays
     * for the two reasons `OrderPaid::content()` gives: a client that will not
     * render markup still has to be able to read this, and a message offering
     * no alternative part scores worse with the filters that decide whether
     * any of it arrives at all.
     *
     * **The two views obey opposite escaping rules and that is enforced by
     * path.** `mail.gift-to-redeem` is text and echoes raw. `mail.html.
     * gift-to-redeem` is markup and escapes, and the signature, the buyer's
     * note and the reading's name are all typed by a customer and all three
     * reach it. `MailBodyTest` holds both.
     */
    public function content(): Content
    {
        // Loaded rather than reached for, the same discipline the receipt beside
        // this one follows. A queued mailable is rehydrated from the database,
        // so the gift arrives here holding nothing but its own columns however
        // fully the sender had it loaded.
        $line = $this->gift->loadMissing('orderLine.product')->orderLine;

        return new Content(html: 'mail.html.gift-to-redeem', text: 'mail.gift-to-redeem', with: [
            'signature' => $this->gift->signature,

            // **`note` and not `message`, which is not a preference.** Laravel
            // injects the `Illuminate\Mail\Message` being built into every mail
            // view under `$message`, so passing `gifts.message` under its own
            // name silently loses the buyer's words and renders the mailer's
            // object instead. It fails loudly here, having tried to print an
            // object, and would fail quietly on any view that only tested it
            // for truth.
            'note' => $this->gift->message,

            // The catalogue's name, in this gift's language, because the
            // enclosing `withLocale` is open while this runs. `product_key` is
            // the fallback for a reading retired since it was bought: **a code
            // for a withdrawn product still redeems**, so refusing to name it
            // would be refusing to hand it over.
            'reading' => $line->product?->name ?? $line->product_key->value,

            // **The link and the code are one credential in two forms**, which
            // is why they are built from the same printed string. A link is
            // never safer than the code inside it, so the entropy is in the
            // code and neither form is treated as the secret half.
            'link' => FrontendUrl::redeem((string) $this->gift->printedCode()),
            'code' => $this->gift->printedCode(),

            // The three the HTML part alone reads, and the text part is
            // deliberately left exactly as it was.
            //
            // **The subtitle is the catalogue's second line and not a
            // description of the gift**, the same one the receipt's card
            // carries. Off the key rather than the product, because a reading
            // withdrawn since it was bought still redeems and the row naming
            // it may be gone.
            'subtitle' => $line->product_key->emailSubtitle(),
            'assets' => config('mail.assets.url'),
            'site' => rtrim((string) config('frontend.url'), '/'),
        ]);
    }
}
