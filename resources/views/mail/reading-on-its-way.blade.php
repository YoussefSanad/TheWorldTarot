{{-- Text, not HTML. Escaping here corrupts, so every value is echoed raw. #45 --}}
{!! \App\Support\Greeting::line($querentName) !!}

You have asked for {!! $readingName !!}.
@if ($question)

Your question:

{!! $question !!}
@endif
@if ($promise)

{!! $promise !!}
@endif

There is nothing more to do. It will arrive at this address.
