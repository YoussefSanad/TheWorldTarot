{{--
  The designed password reset. Issue #106.

  Same frame and button as the gift mail. The text part still sends. The link
  is a credential: it is in the href only, never printed under the button.
  Markup, so everything is escaped.
--}}

@extends('mail.html.frame')

@section('title', 'Reset your password')

@section('preview')Choose a new password. The link works once.@endsection

@section('heading')
                Reset Your<br>Password
@endsection

@section('body')
              <tr><td align="center" class="white body" style="padding:18px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:22px;letter-spacing:0.03em;color:#FFFFFF;">{{ \App\Support\Greeting::line($name) }}</td></tr>

              <tr><td align="center" class="white body" style="padding:10px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:22px;letter-spacing:0.03em;color:#FFFFFF;">Someone asked to reset the password on your World Tarot account. Choose a new one here:</td></tr>

              <tr><td align="center" style="padding:20px 24px 0 24px;">
              <!--[if mso]>
              <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:49px;v-text-anchor:middle;width:280px;" arcsize="20%" stroke="f" fillcolor="#E4C46A">
                <w:anchorlock/>
                <center style="color:#101B2E;font-family:Georgia, 'Times New Roman', Times, serif;font-size:17px;font-weight:normal;letter-spacing:0.08em;">RESET PASSWORD</center>
              </v:roundrect>
              <![endif]-->
              <!--[if !mso]><!-->
              <table role="presentation" class="btn" width="280" cellpadding="0" cellspacing="0" border="0" align="center" style="width:280px;border-collapse:separate;">
                <tr><td align="center" bgcolor="#E4C46A" style="background-color:#E4C46A;border-radius:10px;padding:14px 18px;"><a href="{{ $url }}" style="display:block;font-family:'Cinzel', 'Cormorant Garamond', Georgia, 'Times New Roman', Times, serif;font-size:17px;line-height:21px;letter-spacing:0.08em;color:#101B2E;text-decoration:none;text-transform:uppercase;">Reset Password</a></td></tr>
              </table>
              <!--<![endif]-->
              </td></tr>

              <tr><td align="center" class="soft body" style="padding:22px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:14px;line-height:19px;letter-spacing:0.03em;font-style:italic;color:#F1EEE5;">This link works for {{ $minutes }} minutes, and only once.</td></tr>

              <tr><td align="center" class="soft body" style="padding:10px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:14px;line-height:19px;letter-spacing:0.03em;font-style:italic;color:#F1EEE5;">If this was not you, nothing has changed and you can ignore this message.</td></tr>
@endsection
