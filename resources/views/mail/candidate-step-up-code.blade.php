<x-mail::message>
# Hello {{ $candidateName }},

Your verification code is {{ $code }}

Enter it in the candidate portal to continue. It expires in {{ $validMinutes }} minutes and works once. Requesting a new code cancels this one.

If you didn't ask for a code, you can ignore this email — nobody can use it without also being signed in to your portal account.

Thanks,<br>
{{ $organisationName }}
</x-mail::message>
