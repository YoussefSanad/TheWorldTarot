{{-- Text, not HTML. Escaping here corrupts, so every value is echoed raw. #45 --}}
{{-- The welcome email since #135 (24 November 2026). Draft copy for the client. --}}
{!! \App\Support\Greeting::line($name) !!}

Welcome to the Viewing Room. You already have lifetime access, so the films
are yours to come back to whenever you like.

For future visits, set a password with this secure link. You can then sign in
on this device or any other:

{!! $url !!}

This link works for {!! $minutes !!} minutes, and only once. After that, use
"Forgotten your password?" on the sign in page to get a new one.
