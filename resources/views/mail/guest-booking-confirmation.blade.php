<x-mail::message>
# Your booking is confirmed

Dear {{ $guestName }},

Thank you for booking with {{ $propertyName }}. Your booking **{{ $bookingRef }}** is confirmed.

<x-mail::table>
| | |
|:--|:--|
| Check-in | {{ $checkIn }} |
| Check-out | {{ $checkOut }} ({{ $nights }} {{ $nights === 1 ? 'night' : 'nights' }}) |
@if ($rooms !== [])
| Rooms | {{ implode(', ', $rooms) }} |
@endif
| Total | {{ $total }} |
| Paid | {{ $paid }} |
@if ($outstanding)
| Balance due | {{ $outstanding }} |
@endif
</x-mail::table>

@if ($propertyAddress)
{{ $propertyName }}, {{ $propertyAddress }}
@endif

@if ($webCheckinUrl ?? null)
Save time at arrival: check in online with your details and ID before you come.

<x-mail::button :url="$webCheckinUrl">
Check in online
</x-mail::button>
@endif

We look forward to welcoming you.

Thanks,<br>
{{ $propertyName }}
</x-mail::message>
