<x-mail::message>
# {{ $heading }}

{{ $propertyName }} · {{ $period }}

@if (filled($description))
{{ $description }}
@endif

The report is attached.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
