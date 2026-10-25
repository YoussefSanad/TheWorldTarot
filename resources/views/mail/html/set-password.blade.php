{{-- Viewing Room set-password invitation. Issue #106. Link is in the href only; markup, so escaped. --}}

@extends('mail.html.frame')

@section('title', 'Your Viewing Room pass is ready')

@section('preview')Choose a password. The link works once.@endsection

@section('heading')
                Your Pass<br>Is Ready
@endsection

@section('body')
              <tr><td align="center" class="white body" style="padding:18px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:22px;letter-spacing:0.03em;color:#FFFFFF;">{{ \App\Support\Greeting::line($name) }}</td></tr>

              <tr><td align="center" class="white body" style="padding:10px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:16px;line-height:22px;letter-spacing:0.03em;color:#FFFFFF;">Your Viewing Room pass is ready. Choose a password here and the films are yours to come back to whenever you like, on any device:</td></tr>

              <tr><td align="center" style="padding:20px 24px 0 24px;">
              <!--[if mso]>
              <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:49px;v-text-anchor:middle;width:280px;" arcsize="20%" stroke="f" fillcolor="#E4C46A">
                <w:anchorlock/>
                <center style="color:#101B2E;font-family:Georgia, 'Times New Roman', Times, serif;font-size:17px;font-weight:normal;letter-spacing:0.08em;">SET YOUR PASSWORD</center>
              </v:roundrect>
              <![endif]-->
              <!--[if !mso]><!-->
              <table role="presentation" class="btn" width="280" cellpadding="0" cellspacing="0" border="0" align="center" style="width:280px;border-collapse:separate;">
                <tr><td align="center" bgcolor="#E4C46A" style="background-color:#E4C46A;border-radius:10px;padding:14px 18px;"><a href="{{ $url }}" style="display:block;font-family:'Cinzel', 'Cormorant Garamond', Georgia, 'Times New Roman', Times, serif;font-size:17px;line-height:21px;letter-spacing:0.08em;color:#101B2E;text-decoration:none;text-transform:uppercase;">Set Your Password</a></td></tr>
              </table>
              <!--<![endif]-->
              </td></tr>

              <tr><td align="center" class="soft body" style="padding:22px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:14px;line-height:19px;letter-spacing:0.03em;font-style:italic;color:#F1EEE5;">This link works for {{ $minutes }} minutes, and only once.</td></tr>

              <tr><td align="center" class="soft body" style="padding:10px 24px 0 24px;font-family:'Gill Sans', 'Gill Sans MT', 'GillSans', Calibri, 'Trebuchet MS', 'Segoe UI', Candara, Arial, sans-serif;font-size:14px;line-height:19px;letter-spacing:0.03em;font-style:italic;color:#F1EEE5;">If it expires before you get to it, you can ask for a new one from the sign in page at any time.</td></tr>
@endsection
