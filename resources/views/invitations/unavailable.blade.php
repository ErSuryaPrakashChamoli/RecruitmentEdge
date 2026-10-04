<x-portal.layout title="Invitation" section="Invitation" :home="$loginUrl">
    <div class="mx-auto w-full max-w-md">
        <x-portal.card title="This invitation can't be used" description="The link is not valid, has expired, or was already used. Ask the organisation to send you a new invitation.">
            <a href="{{ $loginUrl }}" class="text-sm text-brand hover:underline">Go to the sign-in page</a>
        </x-portal.card>
    </div>
</x-portal.layout>
