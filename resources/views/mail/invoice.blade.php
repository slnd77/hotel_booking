<x-mail::message>
# {{ $isProforma ? 'Your proforma invoice' : 'Your invoice' }}

Dear {{ $guestName }},

Thank you for staying at {{ $propertyName }}. Please find attached the {{ $isProforma ? 'proforma invoice' : 'invoice' }} for your booking **{{ $bookingRef }}** ({{ $checkIn }} – {{ $checkOut }}).

Thanks,<br>
{{ $propertyName }}
</x-mail::message>
