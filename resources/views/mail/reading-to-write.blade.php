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

Mark it as fulfilled in the admin panel once the reading is on its way.
