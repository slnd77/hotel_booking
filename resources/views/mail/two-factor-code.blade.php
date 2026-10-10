<x-mail::message>
# Your sign-in code

Use this code to finish signing in to {{ $appName }}:

<x-mail::panel>
**{{ $code }}**
</x-mail::panel>

It expires in {{ $minutes }} minutes. If you didn't try to sign in, change your password.

Thanks,<br>
{{ $appName }}
</x-mail::message>
