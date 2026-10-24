{{--
  The designed purchase confirmation, issue #53.

  **Built from three supplied files that differed in five strings**: the title,
  the product name, the subtitle, the price and the delivery line. Everything
  else was identical, so there is one view and those five come from the order.

  **The chrome moved to `mail.html.frame` on 3 September 2026, issue #81**, so
  that the gift a stranger receives could be drawn in the same document as the
  receipt a customer does. It moved whole. What is left here is this message's
  own four slots.

  ~~Do not reformat this into a component tree.~~ **Amended 3 September 2026.**
  That sentence was about the table layout, the inline styles and the Outlook
  conditionals, every one of which is exactly as it was and none of which moved
  anywhere. It was never about a shared frame, and once there was one it read
  as though it forbade it. The prohibition stands, over the markup itself, in
  this file and in the frame: it is a document that has to render in clients
  from 2007.
--}}

@extends('mail.html.frame')

{{--
  One of the five strings the three designs varied. It is per product where
  there is one product, and falls back to the subject line where an order
  carries several and no single reading names it.

  **One expression rather than `@if`/`@else`, since 3 September 2026.** It was
  written with directives and the `@else` never compiled: Blade's directive
  pattern is anchored with `\B`, so a directive with a word character
  immediately before its `@` is left in the document as typed. Every order of
  more than one line had been sending `<title></title>`, and every order of
  exactly one the literal string `@else Your order is confirmed`, since this
  view shipped. `MailBodyTest` scans for the shape now.
--}}
@section('title'){{ count($lines) === 1 ? 'Your '.$lines->first()->displayName().' is on its way' : 'Your order is confirmed' }}@endsection

{{--
  The inbox preview, which is the second line a client shows beside the
  subject. The frame follows it with the whitespace that stops body copy
  leaking into the rest of that line.
--}}
@section('preview')Thank you. We&#x27;ve received your order and payment.@endsection

              {{--
                **The design's heading, and the fallback for an order that is
                not a reading.** Every supplied file said "Your Reading Is On
                Its Way", because all three were reading confirmations. The same
                mailable confirms a Viewing Room pass, which is not a reading,
                is not written by hand and is not on its way anywhere: it is
                already open. Sending that buyer a reading's heading is the same
                defect as the delivery line below, one row further up.

                The fallback is the subject line this message already carries,
                so it is copy that has been signed off rather than invented
                here.
              --}}
@section('heading')
                @if ($awaitsReading)Your Reading<br>Is On Its Way @else Your Order<br>Is Confirmed @endif
@endsection

@section('body')
              {{--
                Greeting, which the supplied design did not have.

                It is here because every other message this application sends
                opens with one, and because dropping it would have meant issue
                #52's fix reached the text part of a receipt and not the part
                anybody actually reads. `Greeting::line` carries the comma, for
                the reason written there.
              --}}
              <tr><td align="center" class="white body" style="padding:18px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:22px;letter-spacing:0.03em;color:#FFFFFF;">{{ \App\Support\Greeting::line($name) }}</td></tr>

              {{-- body copy --}}
              <tr><td align="center" class="white body" style="padding:10px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:22px;letter-spacing:0.03em;color:#FFFFFF;">Thank you. We&rsquo;ve received your order and payment.</td></tr>

              {{--
                One gold box per line.

                **The design drew a single product and an order carries up to
                ten**, at a quantity of up to ten each. Repeating the box is the
                shape that stays right at both ends: the ordinary order is one
                reading and looks exactly like the mockup, and the order for
                three does not silently show the price of one.
              --}}
              @foreach ($lines as $line)
              <tr><td align="center" style="padding:{{ $loop->first ? '47' : '18' }}px 10px 0 10px;">

              <table role="presentation" class="card" width="355" cellpadding="0" cellspacing="0" border="0" align="center" style="width:355px;border:2px solid #E4C46A;border-radius:10px;border-collapse:separate;">
                <tr>
                  <td class="card-pad" style="padding:16px 22px 28px 22px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<tr><td align="center" class="prod" style="padding:0;font-family:'Cinzel', 'Cormorant Garamond', Georgia, 'Times New Roman', Times, serif;font-size:24px;line-height:28px;letter-spacing:-0.01em;color:#FFFFFF;text-transform:uppercase;">{{ $line->displayName() }}@if ($line->quantity > 1)<span class="gold" style="color:#E4C46A;">&nbsp;&times;{{ $line->quantity }}</span>@endif</td></tr>
<tr><td align="center" class="sub" style="padding:3px 0 0 0;font-family:'Cinzel', 'Cormorant Garamond', Georgia, 'Times New Roman', Times, serif;font-size:18px;line-height:22px;letter-spacing:-0.01em;color:#E4C46A;text-transform:uppercase;">{{ $line->product_key->emailSubtitle() }}</td></tr>
<tr><td align="center" class="price" style="padding:4px 0 0 0;font-family:'Cinzel', 'Cormorant Garamond', Georgia, 'Times New Roman', Times, serif;font-size:24px;line-height:28px;letter-spacing:0.03em;color:#E4C46A;">{{ $symbol }}{{ \App\Support\Money::toMajor($line->subtotal()) }}</td></tr>
<tr><td style="height:14px;line-height:14px;font-size:0;">&nbsp;</td></tr>
<tr><td align="center" style="padding:0;font-size:0;line-height:0;"><img src="{{ $assets }}/card-rule.png" srcset="{{ $assets }}/card-rule@2x.png 2x" width="287" height="3" alt="" border="0" class="crule" style="display:block;border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;width:287px;height:3px;"></td></tr>
@if ($line->question)
{{--
  The customer's own words, read back to them.

  **The design had nowhere for this and it is added deliberately.** A reading is
  written by hand against exactly this sentence, so it is the one thing on the
  receipt somebody needs to check while there is still time to say it is wrong.
  It is also the value in this document most likely to carry a `<`, which is why
  the escaping this directory enforces is not theoretical.
--}}
<tr><td align="center" class="soft" style="padding:15px 6px 0 6px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:14px;line-height:19px;letter-spacing:0.03em;font-style:italic;color:#F1EEE5;">&ldquo;{{ $line->question }}&rdquo;</td></tr>
@endif
@if ($line->gift)
{{--
  **The address a present went to, in the slot the delivery promise stands in.**
  B6, issue #72.

  A gift is not on its way to the person reading this, so the promise that says
  it is has no business on their receipt: the reading is written when the
  recipient asks for it, which may be months away or never. The same defect the
  pass had, one product along.

  **It is prominent rather than a footnote, and that is the whole reason it is
  on the receipt at all.** The buyer never receives the code, decided
  2 September 2026, so there is nothing else in this message they can check.
  A typed address is unrecoverable if it is wrong: nothing on our side can tell
  that the mail landed nowhere, there is no expiry to reclaim it and there are
  no refunds. So it is set in the gold the prices are set in rather than in the
  soft cream the delivery line uses, at the size a person reads without looking
  for it.

  Escaped, like everything else in this directory. It is an address somebody
  typed into a form.
--}}
<tr><td align="center" class="deliv gold" style="padding:15px 0 0 0;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:20px;letter-spacing:0.03em;color:#E4C46A;text-transform:uppercase;">Sent to</td></tr>
<tr><td align="center" class="deliv gold goldLinks" style="padding:3px 6px 0 6px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:20px;letter-spacing:0.03em;color:#E4C46A;">{{ $line->gift->recipient_email }}</td></tr>
@elseif ($line->product_key->deliveryWindow())
{{--
  **Omitted rather than blanked when there is no window.** A Viewing Room pass
  is bought and open in the same moment, so a delivery promise on it is not a
  missing value, it is a sentence that should not be in the document. This
  rendered "DELIVERY WITHIN IMMEDIATELY" before the enum learned to say null.
--}}
<tr><td align="center" class="deliv" style="padding:15px 0 0 0;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:20px;letter-spacing:0.03em;color:#F1EEE5;text-transform:uppercase;">Delivery within {{ $line->product_key->deliveryWindow() }}</td></tr>
@endif
                    </table>
                  </td>
                </tr>
              </table>
              </td></tr>
              @endforeach

              {{--
                The total, and only when there is more than one line to total.

                On a single line order it would restate the number in the box
                immediately above it, which reads like a second charge.
              --}}
              @if (count($lines) > 1)
              <tr><td align="center" class="gold" style="padding:22px 24px 0 24px;font-family:'Cinzel', 'Cormorant Garamond', Georgia, 'Times New Roman', Times, serif;font-size:20px;line-height:24px;letter-spacing:0.03em;color:#E4C46A;text-transform:uppercase;">Total&nbsp;&nbsp;{{ $symbol }}{{ \App\Support\Money::toMajor($total) }}</td></tr>
              @endif

              @if ($holdsAPass)
              {{--
                **The pass, said once the money has gone through.** A pass is
                open the moment it is paid for and nothing else in this
                document says so, because the delivery line is omitted for it.
                The button is the one `gift-to-redeem` draws, including the VML
                half Outlook on Windows needs, so a buyer in any client has
                something to press.

                **The password sentence names a separate email and carries no
                link.** The invitation is its own message so that a receipt
                support resends never re-issues a credential. The link email is
                skipped when a live link already exists, so the sentence also
                points to the sign in page for a new one and stays true then.
                Escaped like everything else here.
              --}}
              <tr><td align="center" class="white body" style="padding:30px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:22px;letter-spacing:0.03em;color:#FFFFFF;">Your Viewing Room pass is yours for good. Come back to the films whenever you like, on any device.</td></tr>

              <tr><td align="center" style="padding:20px 24px 0 24px;">
              <!--[if mso]>
              <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $viewingRoomUrl }}" style="height:49px;v-text-anchor:middle;width:280px;" arcsize="20%" stroke="f" fillcolor="#E4C46A">
                <w:anchorlock/>
                <center style="color:#101B2E;font-family:Georgia, 'Times New Roman', Times, serif;font-size:17px;font-weight:normal;letter-spacing:0.08em;">ENTER THE VIEWING ROOM</center>
              </v:roundrect>
              <![endif]-->
              <!--[if !mso]><!-->
              <table role="presentation" class="btn" width="280" cellpadding="0" cellspacing="0" border="0" align="center" style="width:280px;border-collapse:separate;">
                <tr><td align="center" bgcolor="#E4C46A" style="background-color:#E4C46A;border-radius:10px;padding:14px 18px;"><a href="{{ $viewingRoomUrl }}" style="display:block;font-family:'Cinzel', 'Cormorant Garamond', Georgia, 'Times New Roman', Times, serif;font-size:17px;line-height:21px;letter-spacing:0.08em;color:#101B2E;text-decoration:none;text-transform:uppercase;">Enter the Viewing Room</a></td></tr>
              </table>
              <!--<![endif]-->
              </td></tr>

              @if ($needsPassword)
              <tr><td align="center" class="white body" style="padding:22px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:22px;letter-spacing:0.03em;color:#FFFFFF;">You still need to choose a password. A separate email from us carries the link to set one, or you can ask for a new one from the sign in page at any time.</td></tr>
              @endif
              @endif
@endsection
