<x-mail::message>
# Welcome, {{ $name }}

You now have access to {{ config('app.name') }}. Set your password to sign in.

<x-mail::button :url="$url">
Set your password
</x-mail::button>

This link can be used once and expires in {{ $validMinutes }} minutes. If it has expired, use "Forgot password?" on the sign-in page.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
