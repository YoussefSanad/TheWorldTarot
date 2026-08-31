{{-- Text, not HTML. Escaping here corrupts, so every value is echoed raw. #45 --}}
{!! \App\Support\Greeting::line($name) !!}

Your Viewing Room pass is ready. Choose a password here and the films are yours
to come back to whenever you like, on any device:

{!! $url !!}

This link works for {!! $minutes !!} minutes, and only once.

If it expires before you get to it, you can ask for a new one from the sign in
page at any time.
