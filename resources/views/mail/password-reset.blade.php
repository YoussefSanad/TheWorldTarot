{{-- Text, not HTML. Escaping here corrupts, so every value is echoed raw. #45 --}}
Hello {!! $name !!},

Someone asked to reset the password on your World Tarot account.

Choose a new one here:
{!! $url !!}

This link works for {!! $minutes !!} minutes, and only once.

If this was not you, nothing has changed and you can ignore this message.
