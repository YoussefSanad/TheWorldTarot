{{--
  Viewing Room set-password invitation. Issue #106. Link is in the href only;
  markup, so escaped.

  **The welcome email since 24 November 2026, #135.** Revived from disuse (#108
  had folded it into the receipt) with new copy, a draft for the client. The
  same document for a first and a repeat buyer: only the href differs, set or
  reset, which `SetPasswordLink` decides from the minted `PasswordLink`.
--}}

@extends('mail.html.frame')

@section('title', 'Welcome to the Viewing Room')

@section('preview')Set a password for your next visit. The link works once.@endsection

@section('heading')
                Welcome To<br>The Viewing Room
@endsection

@section('body')
              <tr><td align="center" class="white body" style="padding:18px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:22px;letter-spacing:0.03em;color:#FFFFFF;">{{ \App\Support\Greeting::line($name) }}</td></tr>

              <tr><td align="center" class="white body" style="padding:10px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:22px;letter-spacing:0.03em;color:#FFFFFF;">Welcome to the Viewing Room. You already have lifetime access, so the films are yours to come back to whenever you like.</td></tr>

              <tr><td align="center" class="white body" style="padding:10px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:22px;letter-spacing:0.03em;color:#FFFFFF;">For future visits, set a password with this secure link. You can then sign in on this device or any other.</td></tr>

              <tr><td align="center" style="padding:20px 24px 0 24px;">
              <!--[if mso]>
              <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:49px;v-text-anchor:middle;width:280px;" arcsize="20%" stroke="f" fillcolor="#E4C46A">
                <w:anchorlock/>
                <center style="color:#101B2E;font-family:Georgia, 'Times New Roman', Times, serif;font-size:17px;font-weight:normal;letter-spacing:0.08em;">SET YOUR PASSWORD</center>
              </v:roundrect>
              <![endif]-->
              <!--[if !mso]><!-->
              <table role="presentation" class="btn" width="280" cellpadding="0" cellspacing="0" border="0" align="center" style="width:280px;border-collapse:separate;">
                <tr><td align="center" bgcolor="#E4C46A" style="background-color:#E4C46A;border-radius:10px;padding:14px 18px;"><a href="{{ $url }}" style="display:block;font-family:'Cinzel', 'Cormorant Garamond', Georgia, 'Times New Roman', Times, serif;font-size:17px;line-height:21px;letter-spacing:0.08em;color:#101B2E;text-decoration:none;text-transform:uppercase;">Set your password</a></td></tr>
              </table>
              <!--<![endif]-->
              </td></tr>

              <tr><td align="center" class="soft body" style="padding:22px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:14px;line-height:19px;letter-spacing:0.03em;font-style:italic;color:#F1EEE5;">This link works for {{ $minutes }} minutes, and only once.</td></tr>

              <tr><td align="center" class="soft body" style="padding:10px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:14px;line-height:19px;letter-spacing:0.03em;font-style:italic;color:#F1EEE5;">After that, use &ldquo;Forgotten your password?&rdquo; on the sign in page to get a new one.</td></tr>
@endsection
