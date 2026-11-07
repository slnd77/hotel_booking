<x-mail::message>
# Your bill from {{ $outletName }}

Dear {{ $guestName }},

Please find attached bill **#{{ $billNumber }}** from {{ $outletName }} at {{ $propertyName }}, dated {{ $businessDate }}.

Thanks,<br>
{{ $propertyName }}
</x-mail::message>
