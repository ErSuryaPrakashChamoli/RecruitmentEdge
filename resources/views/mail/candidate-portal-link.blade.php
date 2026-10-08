<x-mail::message>
# Hello {{ $candidateName }},

@if ($isInvitation)
You have been invited to the {{ $organisationName }} candidate portal, where you can follow your applications, confirm interviews and share documents.
@else
We received a request to set your {{ $organisationName }} portal password.
@endif

<x-mail::button :url="$url">
Set your password
</x-mail::button>

This link expires in 48 hours and can only be used once. If you didn't expect this email, you can ignore it.

Thanks,<br>
{{ $organisationName }}
</x-mail::message>
