<x-mail::message>
# Check in online

Dear {{ $guestName }},

Save time at arrival: fill in your details, upload your ID and sign your registration card for booking **{{ $bookingRef }}** (arriving {{ $checkIn }}) before you come.

<x-mail::button :url="$url">
Check in online
</x-mail::button>

Thanks,<br>
{{ $propertyName }}
</x-mail::message>
