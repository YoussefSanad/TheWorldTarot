{{--
  The designed purchase confirmation, issue #53.

  **This directory is markup and escapes. Its sibling is text and does not.**
  Every other mail view in this application interpolates with `{!! !!}`, which
  is correct there and an injection here: a customer's own name and their own
  typed question both reach this document, and a text body has no tags to close
  while this one does. `MailBodyTest` enforces the split by path, so a view
  copied in from next door fails rather than shipping.

  **Built from three supplied files that differed in five strings**: the title,
  the product name, the subtitle, the price and the delivery line. Everything
  else was identical, so there is one view and those five come from the order.

  **Images are absolute, from `config('mail.assets.url')`.** An email client has
  no document base to resolve a relative path against. The files themselves are
  in `public/email/`, and that config key carries the reasoning.

  **Table layout, inline styles and the Outlook conditionals are not legacy.**
  Word renders Outlook on Windows, Gmail strips `<style>` from forwarded mail,
  and neither has ever supported flexbox. The `@media` block survives where it
  can and the inline styles are what everything else reads.

  Do not reformat this into a component tree. It is a document that has to
  render in clients from 2007.
--}}
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office" lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
{{-- Tell dark-mode clients this design is already dark, so they do not invert it. --}}
<meta name="color-scheme" content="dark">
<meta name="supported-color-schemes" content="dark">
{{--
  One of the five strings the three designs varied. It is per product where
  there is one product, and falls back to the subject line where an order
  carries several and no single reading names it.
--}}
<title>@if (count($lines) === 1)Your {{ $lines->first()->product?->name ?? $lines->first()->product_key->value }} is on its way@else Your order is confirmed @endif</title>
<!--[if mso]>
<noscript><xml><o:OfficeDocumentSettings>
  <o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch>
</o:OfficeDocumentSettings></xml></noscript>
<![endif]-->
{{-- Cinzel for Apple Mail / iOS. A <link> lets the client paint the fallback serif
     immediately; an @import here would block the whole email from rendering until
     Google Fonts answered. Hidden from Outlook, which ignores web fonts anyway. --}}
<!--[if !mso]><!-->
<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;500&display=swap" rel="stylesheet" type="text/css">
<!--<![endif]-->
<style type="text/css">
  :root { color-scheme: dark; supported-color-schemes: dark; }

  body,table,td,a { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
  table,td { mso-table-lspace:0pt; mso-table-rspace:0pt; }
  img { -ms-interpolation-mode:bicubic; border:0; height:auto; line-height:100%; outline:none; text-decoration:none; }
  body { margin:0 !important; padding:0 !important; width:100% !important; background-color:#FFFFFF !important; }

  /* Gmail/Apple/iOS dark mode: restate every colour so nothing gets recalculated. */
  @media (prefers-color-scheme: dark) {
    body, .canvas { background-color:#1A1A1A !important; }
    .bg { background-color:#000000 !important; }
    .gold, .gold a { color:#E4C46A !important; }
    .white { color:#FFFFFF !important; }
    .soft, .soft a { color:#F1EEE5 !important; }
    .vline { background-color:#E4C46A !important; }
  }
  /* Outlook.com dark mode rewrites colours behind [data-ogsc]/[data-ogsb]. */
  [data-ogsc] .canvas, [data-ogsb] .canvas { background-color:#1A1A1A !important; }
  [data-ogsc] .bg, [data-ogsb] .bg { background-color:#000000 !important; }
  [data-ogsc] .gold, [data-ogsc] .gold a { color:#E4C46A !important; }
  [data-ogsc] .white { color:#FFFFFF !important; }
  [data-ogsc] .soft, [data-ogsc] .soft a { color:#F1EEE5 !important; }
  [data-ogsb] .vline { background-color:#E4C46A !important; }

  a { color:#F1EEE5; }
  /* Stop iOS/Gmail auto-linking dates, addresses and numbers in brand blue. */
  .appleLinks a, .appleLinks a span { color:#F1EEE5 !important; text-decoration:none !important; }

  @media only screen and (max-width:600px) {
    /* A phone gets the panel edge to edge: the frame is a desktop idea, and a
       margin here is width the 88% card needs. Black on every canvas surface
       and no padding, after the dark blocks so it wins in dark mode too. */
    body, .canvas { background-color:#000000 !important; }
    [data-ogsc] .canvas, [data-ogsb] .canvas { background-color:#000000 !important; }
    .canvas-pad { padding:0 !important; }
    .wrap { width:100% !important; max-width:100% !important; }
    .band { width:100% !important; height:auto !important; }
    .orn  { width:100% !important; height:auto !important; }
    .gut-l { width:5.7% !important; }
    .gut-r { width:6.5% !important; }
    .vline { width:1px !important; }
    .card { width:88% !important; }
    .card-pad { padding:16px 14px 28px 14px !important; }
    .logo-top  { font-size:26px !important; line-height:30px !important; }
    .logo-main { font-size:31px !important; line-height:36px !important; }
    .head  { font-size:25px !important; line-height:29px !important; }
    .body  { font-size:15px !important; line-height:21px !important; }
    .prod  { font-size:20px !important; line-height:24px !important; }
    .sub   { font-size:15px !important; line-height:19px !important; }
    .price { font-size:21px !important; line-height:25px !important; }
    .deliv { font-size:14px !important; line-height:18px !important; }
    .rule  { width:78% !important; height:auto !important; }
    .crule { width:100% !important; height:auto !important; }
    .pad-1 { height:53px !important; }
  }
</style>
</head>
<body class="canvas" style="margin:0;padding:0;background-color:#FFFFFF;">

{{-- Inbox preview text, then whitespace so no body copy leaks into the preview. --}}
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">Thank you. We&#x27;ve received your order and payment.</div>
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</div>

<table role="presentation" class="canvas" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#FFFFFF" style="background-color:#FFFFFF;">
  <tr>
    {{--
      **The black is the frame, not the page.** Every surface in this document
      used to be #000000, so on a desktop window the design had no edge: the
      panel's black ran to the viewport on both sides and the gold bands
      floated in it. The canvas is the plain white an email client paints when
      a document sets nothing, and the panel is 600px of black sitting on it
      with room to breathe, which is the edge the bands were drawn to have.

      Padding on the td, not a margin: Outlook has never laid out margins on a
      table, and it does not read the `@media` block either, so it takes the
      desktop figure and is right to. A phone reads that block and takes the
      other answer: no padding, black canvas, panel to both edges.

      **Dark-mode clients get a neutral #1A1A1A canvas instead of the white**,
      restated in both dark blocks above. A light page behind a black panel is
      the point in daylight and a glare at night.
    --}}
    <td align="center" class="canvas-pad" style="padding:32px 20px;">

      <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
      <table role="presentation" class="wrap bg" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#000000" style="width:600px;max-width:600px;background-color:#000000;">

        {{-- ============ top flourishes + gold rule ============ --}}
        <tr>
          <td align="center" colspan="5" bgcolor="#000000" style="padding:0;font-size:0;line-height:0;">
            <img src="{{ $assets }}/band-top.png" srcset="{{ $assets }}/band-top@2x.png 2x" width="600" height="34" alt="" border="0" class="band" style="display:block;width:600px;height:34px;border:0;">
          </td>
        </tr>

        {{-- ============ breathing room, side flourishes ============ --}}
        <tr>
          <td class="gut-l" width="34" bgcolor="#000000" valign="top" style="width:34px;font-size:0;line-height:0;">
            <img src="{{ $assets }}/orn-tl.png" srcset="{{ $assets }}/orn-tl@2x.png 2x" width="34" height="84" alt="" border="0" class="orn" style="display:block;width:34px;height:84px;border:0;">
          </td>
          <td class="vline" width="2" bgcolor="#E4C46A" style="width:2px;background-color:#E4C46A;font-size:0;line-height:0;">&nbsp;</td>
          <td class="pad-1" bgcolor="#000000" style="height:86px;font-size:0;line-height:0;">&nbsp;</td>
          <td class="vline" width="2" bgcolor="#E4C46A" style="width:2px;background-color:#E4C46A;font-size:0;line-height:0;">&nbsp;</td>
          <td class="gut-r" width="39" bgcolor="#000000" valign="top" style="width:39px;font-size:0;line-height:0;">
            <img src="{{ $assets }}/orn-tr.png" srcset="{{ $assets }}/orn-tr@2x.png 2x" width="39" height="84" alt="" border="0" class="orn" style="display:block;width:39px;height:84px;border:0;">
          </td>
        </tr>

        {{-- ============ main content (this row grows with the copy) ============ --}}
        <tr>
          <td class="gut-l" width="34" bgcolor="#000000" style="width:34px;">&nbsp;</td>
          <td class="vline" width="2" bgcolor="#E4C46A" style="width:2px;background-color:#E4C46A;font-size:0;line-height:0;">&nbsp;</td>
          <td bgcolor="#000000" align="center" style="padding:0;background-color:#000000;">

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">

              {{-- logo lockup --}}
              <tr><td align="center" class="white logo-top" style="padding:4px 20px 0 20px;font-family:'Cinzel', 'Cormorant Garamond', Georgia, 'Times New Roman', Times, serif;font-size:30px;line-height:32px;letter-spacing:0.03em;color:#FFFFFF;text-transform:uppercase;">The</td></tr>
              <tr><td align="center" class="white logo-main" style="padding:0 20px 0 20px;font-family:'Cinzel', 'Cormorant Garamond', Georgia, 'Times New Roman', Times, serif;font-size:36px;line-height:40px;letter-spacing:0.03em;color:#FFFFFF;text-transform:uppercase;">World Tarot</td></tr>

              {{-- gold divider --}}
              <tr><td align="center" style="padding:25px 20px 0 20px;font-size:0;line-height:0;">
                <img src="{{ $assets }}/divider.png" srcset="{{ $assets }}/divider@2x.png 2x" width="405" height="20" alt="" border="0" class="rule" style="display:block;width:405px;height:20px;border:0;">
              </td></tr>

              {{-- heading --}}
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
              <tr><td align="center" class="gold head" style="padding:26px 20px 0 20px;font-family:'Cinzel', 'Cormorant Garamond', Georgia, 'Times New Roman', Times, serif;font-size:30px;line-height:32px;letter-spacing:0.03em;color:#E4C46A;text-transform:uppercase;">
                @if ($awaitsReading)Your Reading<br>Is On Its Way @else Your Order<br>Is Confirmed @endif
              </td></tr>

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
<tr><td align="center" class="prod" style="padding:0;font-family:'Cinzel', 'Cormorant Garamond', Georgia, 'Times New Roman', Times, serif;font-size:24px;line-height:28px;letter-spacing:-0.01em;color:#FFFFFF;text-transform:uppercase;">{{ $line->product?->name ?? $line->product_key->value }}@if ($line->quantity > 1)<span class="gold" style="color:#E4C46A;">&nbsp;&times;{{ $line->quantity }}</span>@endif</td></tr>
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
@if ($line->product_key->deliveryWindow())
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

              {{-- compass --}}
              <tr><td align="center" style="padding:27px 20px 0 20px;font-size:0;line-height:0;">
                <img src="{{ $assets }}/compass.png" srcset="{{ $assets }}/compass@2x.png 2x" width="68" height="72" alt="" border="0" style="display:block;width:68px;height:72px;border:0;">
              </td></tr>

              {{-- domain --}}
              <tr><td align="center" class="soft appleLinks" style="padding:0 20px 0 20px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:18px;line-height:19px;letter-spacing:0.03em;color:#F1EEE5;">
                {{-- Label read off the href, so staging cannot show a link saying one host and pointing at another. --}}
<a href="{{ $site }}" style="color:#F1EEE5;text-decoration:none;">{{ preg_replace('#^www\.#', '', (string) parse_url($site, PHP_URL_HOST)) }}</a>
              </td></tr>

              {{--
                **"Please do not reply" is the design agreeing with issue #47.**
                The confirmation leaves from noreply@ carrying no Reply-To, and
                this is the sentence saying so. It offers no alternative route
                on purpose: the obvious one was `/contact`, which the footer
                links and no route serves. Where a customer with a question
                should go is still open, CLAUDE.md section 8.
              --}}
              <tr><td align="center" class="soft" style="padding:34px 24px 13px 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:14px;line-height:18px;letter-spacing:0.03em;font-style:italic;color:#F1EEE5;">This is an automated message. Please do not reply.</td></tr>

            </table>

          </td>
          <td class="vline" width="2" bgcolor="#E4C46A" style="width:2px;background-color:#E4C46A;font-size:0;line-height:0;">&nbsp;</td>
          <td class="gut-r" width="39" bgcolor="#000000" style="width:39px;">&nbsp;</td>
        </tr>

        {{-- ============ legal links, side flourishes ============ --}}
        <tr>
          <td class="gut-l" width="34" bgcolor="#000000" valign="bottom" style="width:34px;font-size:0;line-height:0;">
            <img src="{{ $assets }}/orn-bl.png" srcset="{{ $assets }}/orn-bl@2x.png 2x" width="34" height="76" alt="" border="0" class="orn" style="display:block;width:34px;height:76px;border:0;">
          </td>
          <td class="vline" width="2" bgcolor="#E4C46A" style="width:2px;background-color:#E4C46A;font-size:0;line-height:0;">&nbsp;</td>
          <td bgcolor="#000000" align="center" class="soft" style="padding:16px 24px 39px 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:14px;line-height:18px;letter-spacing:0.03em;color:#F1EEE5;">
<a href="{{ $site }}/privacy-policy" style="color:#F1EEE5;text-decoration:underline;">Privacy Policy</a><span style="text-decoration:none;">&nbsp;&bull;&nbsp;</span><a href="{{ $site }}/terms-of-service" style="color:#F1EEE5;text-decoration:underline;">Terms of Service</a>
          </td>
          <td class="vline" width="2" bgcolor="#E4C46A" style="width:2px;background-color:#E4C46A;font-size:0;line-height:0;">&nbsp;</td>
          <td class="gut-r" width="39" bgcolor="#000000" valign="bottom" style="width:39px;font-size:0;line-height:0;">
            <img src="{{ $assets }}/orn-br.png" srcset="{{ $assets }}/orn-br@2x.png 2x" width="39" height="76" alt="" border="0" class="orn" style="display:block;width:39px;height:76px;border:0;">
          </td>
        </tr>

        {{-- ============ bottom gold rule + flourishes ============ --}}
        <tr>
          <td align="center" colspan="5" bgcolor="#000000" style="padding:0;font-size:0;line-height:0;">
            <img src="{{ $assets }}/band-bottom.png" srcset="{{ $assets }}/band-bottom@2x.png 2x" width="600" height="40" alt="" border="0" class="band" style="display:block;width:600px;height:40px;border:0;">
          </td>
        </tr>

      </table>
      <!--[if mso]></td></tr></table><![endif]-->

    </td>
  </tr>
</table>
</body>
</html>
