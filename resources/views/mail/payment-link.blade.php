<x-mail::message>
# Payment request

{{ $propertyName }} has requested a payment of **{{ $amount }}** for your booking **{{ $bookingRef }}** ({{ $checkIn }} – {{ $checkOut }}).

<x-mail::button :url="$url">
Pay {{ $amount }}
</x-mail::button>

@if ($expiresAt)
This link is valid until {{ $expiresAt }}.
@endif

Thanks,<br>
{{ $propertyName }}
</x-mail::message>
