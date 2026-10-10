<x-mail::message>
# Passkey {{ $change }}

Hi {{ $userName }},

The passkey **{{ $passkeyName }}** was {{ $change }} {{ $change === 'added' ? 'to' : 'from' }} your account on {{ $changedAt }}.

@if ($ipAddress)
- IP address: {{ $ipAddress }}
@endif
@if ($device)
- Browser: {{ $device }}
@endif

If this wasn't you, change your password now and check your passkeys under Profile Settings → Two-factor.

Thanks,<br>
{{ $appName }}
</x-mail::message>
