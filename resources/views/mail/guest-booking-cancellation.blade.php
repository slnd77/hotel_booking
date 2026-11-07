<x-mail::message>
# Your booking is cancelled

Dear {{ $guestName }},

Your booking **{{ $bookingRef }}** at {{ $propertyName }} for {{ $checkIn }} – {{ $checkOut }} has been cancelled.

If you didn't ask for this, or have paid for the stay, please contact us.

Thanks,<br>
{{ $propertyName }}
</x-mail::message>
