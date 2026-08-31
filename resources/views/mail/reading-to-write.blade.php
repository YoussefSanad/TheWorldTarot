{{-- Text, not HTML. Escaping here corrupts, so every value is echoed raw. #45 --}}
Order #{!! $order->id !!} has been paid.

From: {!! $from !!}
Total: {!! $order->currency !!} {!! \App\Support\Money::toMajor($order->total_amount) !!}

@foreach ($order->lines as $line)
- {!! $line->product?->name ?? $line->product_key->value !!}{!! $line->quantity > 1 ? ' x'.$line->quantity : '' !!}
@if ($line->question)
  Their question: {!! $line->question !!}
@else
  No question was asked.
@endif
@endforeach

Mark it as fulfilled in the admin panel once the reading is on its way.
