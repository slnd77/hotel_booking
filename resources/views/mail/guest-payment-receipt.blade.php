<x-mail::message>
# Payment received

Dear {{ $guestName }},

We have received your payment for booking **{{ $bookingRef }}** ({{ $checkIn }} – {{ $checkOut }}).

<x-mail::table>
| | |
|:--|:--|
@if ($receiptNumber)
| Receipt no. | {{ $receiptNumber }} |
@endif
| Amount | {{ $paymentAmount }} |
| Mode | {{ $paymentMode }} |
| Date | {{ $paidAt }} |
| Booking total | {{ $total }} |
@if ($outstanding)
| Balance due | {{ $outstanding }} |
@endif
</x-mail::table>

Thanks,<br>
{{ $propertyName }}
</x-mail::message>
