{{-- Text, not HTML. Escaping here corrupts, so every value is echoed raw. #45 --}}
A customer has disputed a payment with their bank.

Amount: {!! strtoupper($currency) !!} {!! \App\Support\Money::toMajor($amount) !!}
Reason given: {!! $reason !!}
Stripe reference: {!! $disputeId !!}

@if ($order)
Order #{!! $order->id !!}, placed {!! $order->created_at?->toDayDateTimeString() !!}.
@foreach ($order->lines as $line)
- {!! $line->displayName() !!}{!! $line->quantity > 1 ? ' x'.$line->quantity : '' !!}
@endforeach
@else
We could not match this to an order on this site.
@endif

Nothing here has changed. The order has not moved and nothing has been taken
back from the customer. Responding to the dispute is done in the Stripe
dashboard, and there is a deadline on it, so please look at it today.
