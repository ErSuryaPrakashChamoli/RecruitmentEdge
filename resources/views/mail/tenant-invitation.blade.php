<x-mail::message>
# Join {{ $tenantName }}

{{ $inviterName !== null ? $inviterName.' has invited you' : 'You have been invited' }} to join **{{ $tenantName }}** on {{ config('app.name') }}.

<x-mail::button :url="$url">
Accept the invitation
</x-mail::button>

If you already use {{ config('app.name') }} with this email address, sign in with your existing account to accept. Otherwise you can create your account from the link.

This link can be used once and expires on {{ $expiresAt }}. If you weren't expecting it, you can ignore this email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
