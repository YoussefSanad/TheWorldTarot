{{--
  The querent's own message, as a document. Issue #85.

  **The last plain-text message in the gifting flow.** The buyer's receipt has
  been designed since #53 and the recipient's present since #81, so until today
  the one message going to the person with the least standing to check anything
  was the one drawn in nothing: she has no account, no order and no lookup, and
  `App\Mail\ReadingOnItsWay`'s docblock says this message is the only durable
  record that a clock ever started. A frame she can go and see on the site the
  domain at the foot of it names is the same argument here that it was there.

  It is the same frame the receipt and the present are drawn in,
  `mail.html.frame`, in classes that frame already had. **The text part is
  unchanged and still sends**, for the two reasons `OrderPaid::content()`
  gives: a client that will not render markup still has to be able to read
  this, and a message offering no alternative part scores worse with the
  filters that decide whether any of it arrives.

  ## The promise is this document's alone, and it is not computed here

  **The one surface allowed to make it**, B8 (#74), and the mailable's docblock
  names the three that may not and why. The sentence arrives here already
  written, from `ReadingOnItsWay::promise()`, which asks `Reading::dueAt()`.
  **Nothing about a delivery window is worked out in this view**: a second
  expression of due is what `docs/adr/0006-one-screen-per-question.md` refuses
  a column over, and a document growing its own would go stale the day a window
  moved while the panel beside it stayed right.

  ## Two things this document must not carry

  **No price and no total.** She did not pay for this and may never learn what
  was paid. The receipt sets the price in gold at 24px inside the card this
  card is otherwise copied from, so it is the row most easily carried across
  and the one that would hurt most: a price on a present is a social injury
  nobody reports as a bug.

  **No signature.** `GiftToRedeem` told her who the present came from, and that
  is the message that had to. Repeating it here is a second place to get a
  stranger's business wrong, on the message furthest from the person who typed
  it.

  **This directory is markup and escapes.** Her name and her own typed question
  both reach this document, and the question is the longest free text in it.
  `MailBodyTest` enforces it by path.
--}}

@extends('mail.html.frame')

{{--
  **`$readingName` and never `$reading`, which is not a preference.** Laravel
  merges a mailable's public properties over its `with:` array, and this
  mailable's is `$reading`, the model. A view naming that key prints the whole
  row as JSON where the reading should be named, and it fails quietly: the
  message still sends and still reads as prose. The text part beside this one
  carries the same warning, having shipped with the defect.

  **One echoed expression rather than `@if`/`@else`**, and the receipt's title
  is where that was learned: Blade's directive pattern is anchored with `\B`,
  so a directive with a word character immediately before its `@` is left in
  the document as typed, and `way@endsection` would not compile either. An
  interpolation ends the line instead. `MailBodyTest` scans for the shape.
--}}
@section('title'){{ 'Your '.$readingName.' is on its way' }}@endsection

{{--
  The inbox preview, and the promise is what belongs in it: the subject says a
  reading is on its way and this says when, which is the one thing a person
  who has just asked actually wants from the envelope.

  The fallback is the closing line rather than a blank, for the reason
  `ReadingOnItsWay::promise()` returns null at all: a reading with no window is
  not a missing promise, it is a message that should not be making one.
--}}
@section('preview'){{ $promise ?? 'There is nothing more to do. It will arrive at this address.' }}@endsection

{{--
  **The receipt's heading, and here it is literally true.** `OrderPaid` carries
  "Your Reading Is On Its Way" because all three supplied designs were reading
  confirmations, and it has had to learn two exceptions since: a Viewing Room
  pass is already open, and a gifted line is not on its way to the buyer at
  all. This message is the one the sentence was always about.
--}}
@section('heading')
                Your Reading<br>Is On Its Way
@endsection

@section('body')
              {{--
                Greeting, and she may not have given a name. The redemption
                form asks for one and does not require it, so
                `Greeting::line(null)` is a real answer here rather than a
                broken fixture, exactly as it is on the receipt.
              --}}
              <tr><td align="center" class="white body" style="padding:18px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:22px;letter-spacing:0.03em;color:#FFFFFF;">{{ \App\Support\Greeting::line($querentName) }}</td></tr>

              {{--
                **The same claim the text part opens with, without the name in
                it.** That part says "You have asked for THE IN-DEPTH READING."
                because it has nowhere else to put the name. This document has
                the card one row down, and a line naming the reading
                immediately above a box naming the reading reads as a stutter.
              --}}
              <tr><td align="center" class="white body" style="padding:10px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:22px;letter-spacing:0.03em;color:#FFFFFF;">You have asked for a reading, and it is being written for you.</td></tr>

              {{--
                The gold box, and one of them, because one question is one
                reading. `docs/adr/0006-one-screen-per-question.md` is why that
                is a fact about the domain rather than about this view.

                **The receipt's card with the price row taken out**, which is
                the same card the present is given in and for the same reason.
                Name, subtitle, rule, then her own words where the receipt
                reads a buyer's question back.
              --}}
              <tr><td align="center" style="padding:47px 10px 0 10px;">

              <table role="presentation" class="card" width="355" cellpadding="0" cellspacing="0" border="0" align="center" style="width:355px;border:2px solid #E4C46A;border-radius:10px;border-collapse:separate;">
                <tr>
                  <td class="card-pad" style="padding:16px 22px 28px 22px;">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
<tr><td align="center" class="prod" style="padding:0;font-family:'Cinzel', 'Cormorant Garamond', Georgia, 'Times New Roman', Times, serif;font-size:24px;line-height:28px;letter-spacing:-0.01em;color:#FFFFFF;text-transform:uppercase;">{{ $readingName }}</td></tr>
<tr><td align="center" class="sub" style="padding:3px 0 0 0;font-family:'Cinzel', 'Cormorant Garamond', Georgia, 'Times New Roman', Times, serif;font-size:18px;line-height:22px;letter-spacing:-0.01em;color:#E4C46A;text-transform:uppercase;">{{ $subtitle }}</td></tr>
<tr><td style="height:14px;line-height:14px;font-size:0;">&nbsp;</td></tr>
<tr><td align="center" style="padding:0;font-size:0;line-height:0;"><img src="{{ $assets }}/card-rule.png" srcset="{{ $assets }}/card-rule@2x.png 2x" width="287" height="3" alt="" border="0" class="crule" style="display:block;border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;width:287px;height:3px;"></td></tr>
@if ($question)
{{--
  **Her own words, read back to her**, in the slot the receipt reads a buyer's
  question back in and set the same way: quoted, italic, cream rather than
  white.

  A reading is written by hand against exactly this sentence, and she typed it
  into a redemption form minutes ago with nothing to check it against
  afterwards: no account, no order, no lookup. This document is the only copy
  of it she will ever hold.

  **Optional, because the column is.** A reading with no question is possible,
  per the migration that landed the table, and a pair of empty quotes is not
  what a person who asked nothing should be shown.

  It is the value in this document most likely to carry a `<`, which is why the
  escaping this directory enforces is not theoretical here either.
--}}
<tr><td align="center" class="soft" style="padding:15px 6px 0 6px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:14px;line-height:19px;letter-spacing:0.03em;font-style:italic;color:#F1EEE5;">&ldquo;{{ $question }}&rdquo;</td></tr>
@endif
@if ($promise)
{{--
  **The promise, in the row the receipt makes its own in**, which is the whole
  structural argument for the card being here: the sentence a reader looks for
  is where they last saw one.

  **Not uppercased, and that is the one thing this row does differently.** The
  receipt sets "DELIVERY WITHIN 24 HOURS", which is a label. This is a sentence
  carrying a date and a time, and shouting a date makes it harder to read
  rather than more prominent.

  **`appleLinks`, because iOS and Gmail turn dates and times into links** and
  paint them in brand blue on a black panel. The class restates the cream this
  row is already set in, which is what the frame keeps it for.

  Omitted rather than blanked where there is no window, per
  `ReadingOnItsWay::promise()`: nothing giftable reaches that branch today and
  it is kept for the reason `Reading::dueAt()` keeps its own.
--}}
<tr><td align="center" class="deliv appleLinks" style="padding:15px 6px 0 6px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:20px;letter-spacing:0.03em;color:#F1EEE5;">{{ $promise }}</td></tr>
@endif
                    </table>
                  </td>
                </tr>
              </table>
              </td></tr>

              {{--
                **What she has to do next, which is nothing.** The text part
                closes on this sentence and so does this one. She has no
                account to check and no order to look up, so a message that
                stopped at the promise would leave the obvious next question,
                "where do I go to get it", with no answer anywhere.
              --}}
              <tr><td align="center" class="soft" style="padding:22px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:14px;line-height:19px;letter-spacing:0.03em;font-style:italic;color:#F1EEE5;">There is nothing more to do. It will arrive at this address.</td></tr>
@endsection
