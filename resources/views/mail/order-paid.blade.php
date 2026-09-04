{{-- Text, not HTML. Escaping here corrupts, so every value is echoed raw. #45 --}}
{!! \App\Support\Greeting::line($name) !!}

Thank you, your payment has gone through.

@foreach ($lines as $line)
- {!! $line->displayName() !!}{!! $line->quantity > 1 ? ' x'.$line->quantity : '' !!}
@if ($line->question)
  You asked: {!! $line->question !!}
@endif
@if ($line->gift)
  Sent to: {!! $line->gift->recipient_email !!}
@endif
@endforeach

Total: {!! $currency !!} {!! \App\Support\Money::toMajor($total) !!}

@if ($awaitsReading)
Readings are written by hand and sent to you as a PDF, so please allow a
little time.
@endif
@if ($holdsAGift)
Each gift above has been emailed to the address shown with it, carrying a link
and a code for asking their own question. You do not receive the code: it is
theirs to spend. That address is the one thing here worth checking, because it
is the one thing nothing else can check for you.
@endif
