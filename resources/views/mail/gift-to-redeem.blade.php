{{-- Text, not HTML. Escaping here corrupts, so every value is echoed raw. #45 --}}
{!! \App\Support\Greeting::line(null) !!}

{!! $signature !!} has bought you a reading: {!! $reading !!}.
@if ($note)

Their message to you:

{!! $note !!}
@endif

Open it here, and you can ask your own question:

{!! $link !!}

Or go to that page and type this in:

{!! $code !!}

There is no hurry. A gift code does not expire.
