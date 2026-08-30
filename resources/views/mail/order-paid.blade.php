{{-- Text, not HTML. Escaping here corrupts, so every value is echoed raw. #45 --}}
Hello {!! $name !!},

Thank you, your payment has gone through.

@foreach ($lines as $line)
- {!! $line->product?->name ?? $line->product_key->value !!}{!! $line->quantity > 1 ? ' x'.$line->quantity : '' !!}
@if ($line->question)
  You asked: {!! $line->question !!}
@endif
@endforeach

Total: {!! $currency !!} {!! \App\Support\Money::toMajor($total) !!}

Readings are written by hand and sent to you as a PDF, so please allow a
little time.
