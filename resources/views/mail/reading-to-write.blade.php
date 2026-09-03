{{-- Text, not HTML. Escaping here corrupts, so every value is echoed raw. #45 --}}
Order #{!! $order->id !!} has been paid.

From: {!! $from !!}
Total: {!! $order->currency !!} {!! \App\Support\Money::toMajor($order->total_amount) !!}

@foreach ($readings as $reading)
- {!! $reading->orderLine->product?->name ?? $reading->orderLine->product_key->value !!}{!! $reading->orderLine->quantity > 1 ? ' x'.$reading->orderLine->quantity : '' !!}
@if ($reading->question)
  Their question: {!! $reading->question !!}
@else
  No question was asked.
@endif
@endforeach

{{-- Was "Mark it as fulfilled in the admin panel", which stopped being true
     in B4 (#70) when delivery moved onto the Readings screen and became one
     stamp per reading rather than one per order. Corrected 3 September 2026,
     in the words the panel's own confirmation uses. --}}
Mark each one delivered on the Readings screen once you have sent it. The order
finishes itself when everything on it has gone.
