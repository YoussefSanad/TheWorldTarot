{{--
  The designed message a present arrives in. Issue #81.

  **The recipient's mail was the only plain-text message this application
  sent**, and it went to the one person here who has never bought anything and
  may never have heard of the brand, carrying a code and a link.
  `App\Mail\GiftToRedeem`'s docblock says that message is shaped exactly like
  phishing and that the named sender is the strongest signal it is real. This
  document is the second: a frame the reader can go and see for themselves on
  the site the domain at the foot of it names.

  It is the same frame the receipt is drawn in, `mail.html.frame`, and drawn in
  classes that frame already had. **The text part is unchanged and still
  sends**, for the two reasons `OrderPaid::content()` gives: a client that will
  not render markup still has to be able to read this, and a message offering
  no alternative part scores worse with the filters that decide whether any of
  it arrives.

  ## Three things this document must not carry

  **No price and no total.** The receipt sets the price in gold at 24px inside
  the product card and this card stops at the name and the subtitle. The
  recipient is the one person in the transaction who must not be told what was
  paid for their present.

  **Nothing of the buyer but the signature.** Not their address, not the name
  on their card. `GiftToRedeem`'s docblock decides that and this does not
  reopen it: a present is not an introduction between two people.

  **No delivery promise.** Nobody has asked anything yet, so nothing is owed on
  any clock. `deliveryWindow()` is deliberately not read here. That promise is
  `ReadingOnItsWay`'s alone, counted from the day the querent asks, per B8
  (#74). A gift bought in June and spent in September is the whole reason it
  is counted from there.

  **This directory is markup and escapes.** The signature, the buyer's note and
  the reading's name are all typed by a customer and all three are in here.
  `MailBodyTest` enforces it by path.
--}}

@extends('mail.html.frame')

{{--
  **The signature last, and not for style.** Blade's directive pattern is
  anchored with `\B`, so `reading@endsection` would not compile and the word
  would ship into the document as typed. An interpolation ends the line
  instead. `MailBodyTest` scans for the shape; the receipt's `<title>` is where
  it was found, having sent Blade source to customers since #53.
--}}
@section('title')A gift from {{ $signature }}@endsection

{{--
  The inbox preview, which sits beside a subject already naming who this is
  from. It says the one thing the subject cannot: that there is no hurry, so an
  unfamiliar mail carrying a code does not read as something that expires if it
  is not acted on now.
--}}
@section('preview')Open it whenever you like. A gift code does not expire.@endsection

{{--
  **Not "Your Reading Is On Its Way", which is the heading one row of this
  frame away.** Nothing is on its way: the reading is written when this person
  asks for it, which may be months from now or never. What has happened is that
  somebody bought them one.

  ~~Given A Reading~~ **A gift, from 3 September 2026** (frontend #82), which is
  the same correction the subject line took: a reading is a document written by
  hand against a question somebody has asked, and this reader has asked nothing.
  What has arrived is a code, and it stays one until they spend it. The reading
  is named on the card below, which is the honest place for it — that is which
  reading the gift is *for*, not what is in the envelope.
--}}
@section('heading')
                You Have Been<br>Given A Gift
@endsection

@section('body')
              {{--
                Greeting, and there is no name to put in it. The buyer typed an
                address for somebody else and was never asked what they are
                called, so `Greeting::line(null)` is the honest answer and the
                same one the text part gives. The name the reader will
                recognise is the signature, one row down.
              --}}
              <tr><td align="center" class="white body" style="padding:18px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:22px;letter-spacing:0.03em;color:#FFFFFF;">{{ \App\Support\Greeting::line(null) }}</td></tr>

              {{--
                **The signature is the whole argument that this is real**, so it
                opens the body rather than closing it. It is what the buyer
                signed the present with rather than the name on their card, so
                "Mum" is a truer answer than "Jodie O'Reilly" and it is the only
                name in this transaction the reader can be expected to know.
                **A gift and not a reading**, as the heading and the subject
                both say: nothing has been asked and nothing is being written.
                What the buyer bought is named one row down, on the card.
              --}}
              <tr><td align="center" class="white body" style="padding:10px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:22px;letter-spacing:0.03em;color:#FFFFFF;">{{ $signature }} has bought you a gift.</td></tr>

              {{--
                The gold box, and one of them, because a gift is one line.

                **The receipt's card with the price row taken out.** Name,
                subtitle, rule, and then the buyer's words where the receipt
                reads back the customer's own question. A card that ends at the
                rule is the shape a Viewing Room pass already leaves on the
                receipt, so it is not a hole where something was removed.
              --}}
              <tr><td align="center" style="padding:47px 10px 0 10px;">

              <table role="presentation" class="card" width="355" cellpadding="0" cellspacing="0" border="0" align="center" style="width:355px;border:2px solid #E4C46A;border-radius:10px;border-collapse:separate;">
                <tr>
                  <td class="card-pad" style="padding:16px 22px 28px 22px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<tr><td align="center" class="prod" style="padding:0;font-family:'Cinzel', 'Cormorant Garamond', Georgia, 'Times New Roman', Times, serif;font-size:24px;line-height:28px;letter-spacing:-0.01em;color:#FFFFFF;text-transform:uppercase;">{{ $reading }}</td></tr>
<tr><td align="center" class="sub" style="padding:3px 0 0 0;font-family:'Cinzel', 'Cormorant Garamond', Georgia, 'Times New Roman', Times, serif;font-size:18px;line-height:22px;letter-spacing:-0.01em;color:#E4C46A;text-transform:uppercase;">{{ $subtitle }}</td></tr>
<tr><td style="height:14px;line-height:14px;font-size:0;">&nbsp;</td></tr>
<tr><td align="center" style="padding:0;font-size:0;line-height:0;"><img src="{{ $assets }}/card-rule.png" srcset="{{ $assets }}/card-rule@2x.png 2x" width="287" height="3" alt="" border="0" class="crule" style="display:block;border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;width:287px;height:3px;"></td></tr>
@if ($note)
{{--
  What the buyer wrote, in the slot the receipt reads a customer's question back
  in, and set the same way: quoted, italic, cream rather than white.

  **Optional, because the field is.** A buyer who left it blank has not written
  an empty message, so nothing is drawn.

  It is the value in this document most likely to carry a `<`, which is why the
  escaping this directory enforces is not theoretical here either.
--}}
<tr><td align="center" class="soft" style="padding:15px 6px 0 6px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:14px;line-height:19px;letter-spacing:0.03em;font-style:italic;color:#F1EEE5;">&ldquo;{{ $note }}&rdquo;</td></tr>
@endif
                    </table>
                  </td>
                </tr>
              </table>
              </td></tr>

              {{--
                **The link and the code are one credential in two forms**, and
                both are printed for the reason the text part prints both: a
                reader who will not click an unfamiliar link can still type the
                code into a page they navigated to themselves.
              --}}
              <tr><td align="center" class="white body" style="padding:30px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:22px;letter-spacing:0.03em;color:#FFFFFF;">Open it here, and you can ask your own question:</td></tr>

              {{--
                **The button, from 3 September 2026, and from later that day the
                only rendering of the link this document draws.**

                ~~The address is shown rather than hidden behind a phrase.~~
                ~~The button, and the address under it rather than instead of
                it.~~ **Struck: the printed address came out.** It was the same
                string as the button's `href` set twice, one line apart, and a
                redemption URL is longer than a phone is wide — so what it
                bought a cautious reader cost every reader a wrapped, broken
                line of machine text under the one control this document has.

                **The credential is still printed in two forms**, which is the
                part that was load-bearing and did not move: the code below is
                what a reader who will not press an unfamiliar control types
                into a page they navigated to themselves. That reader was never
                served by a URL they would have had to retype either.

                **The text part still prints the address**, because it has no
                button to carry it.

                **Gold on black inverted**, which makes this the one control in
                the document that is dark on light. That is why `.btn` exists in
                the frame: a phone width, and the fill and the ink restated for
                the two dark modes, which recolour halves independently and
                would otherwise leave ink the colour of its own background.

                **The `mso` half is VML and is not optional.** Word draws
                Outlook on Windows and ignores `border-radius`, `padding` on an
                anchor, and a background on a table cell it did not paint
                itself — so the one client most likely to belong to a reader
                being sent an unexpected present is the one that would render
                this as a bare blue link. `arcsize` is the radius as a
                percentage of the height: 10px of 49 is 20%.
              --}}
              <tr><td align="center" style="padding:20px 24px 0 24px;">
              <!--[if mso]>
              <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $link }}" style="height:49px;v-text-anchor:middle;width:280px;" arcsize="20%" stroke="f" fillcolor="#E4C46A">
                <w:anchorlock/>
                <center style="color:#101B2E;font-family:Georgia, 'Times New Roman', Times, serif;font-size:17px;font-weight:normal;letter-spacing:0.08em;">OPEN YOUR GIFT</center>
              </v:roundrect>
              <![endif]-->
              <!--[if !mso]><!-->
              <table role="presentation" class="btn" width="280" cellpadding="0" cellspacing="0" border="0" align="center" style="width:280px;border-collapse:separate;">
                <tr><td align="center" bgcolor="#E4C46A" style="background-color:#E4C46A;border-radius:10px;padding:14px 18px;"><a href="{{ $link }}" style="display:block;font-family:'Cinzel', 'Cormorant Garamond', Georgia, 'Times New Roman', Times, serif;font-size:17px;line-height:21px;letter-spacing:0.08em;color:#101B2E;text-decoration:none;text-transform:uppercase;">Open Your Gift</a></td></tr>
              </table>
              <!--<![endif]-->
              </td></tr>

              <tr><td align="center" class="white body" style="padding:22px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:22px;letter-spacing:0.03em;color:#FFFFFF;">Or go to that page and type this in:</td></tr>

              {{--
                **Printed in fours and spaced out, because it is read off one
                screen and typed into another.** The grouping is
                `Gift::printedCode()`'s and the alphabet is Crockford base32
                less the glyphs people mistype, both decided in B6. The letter
                spacing here is the last part of that job: it is what stops two
                characters being read as one.
              --}}
              <tr><td align="center" class="gold" style="padding:10px 24px 0 24px;font-family:'Cinzel', 'Cormorant Garamond', Georgia, 'Times New Roman', Times, serif;font-size:24px;line-height:30px;letter-spacing:0.14em;color:#E4C46A;">{{ $code }}</td></tr>

              {{--
                `docs/adr/0005-gift-codes-do-not-expire.md`, said to the person
                it is a promise to. An unsolicited mail carrying a code reads as
                something that lapses if it is not acted on now, and this one
                does not.
              --}}
              <tr><td align="center" class="soft" style="padding:22px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:14px;line-height:19px;letter-spacing:0.03em;font-style:italic;color:#F1EEE5;">There is no hurry. A gift code does not expire.</td></tr>
@endsection
