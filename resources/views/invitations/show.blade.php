<x-portal.layout title="Invitation" section="Invitation" :home="$loginUrl">
    <div class="mx-auto flex w-full max-w-md flex-col gap-6">
        <x-portal.card :title="'Join '.$tenantName" :description="'This invitation was sent to '.$maskedEmail.'.'">
            @if ($matches)
                <form method="POST" action="{{ route('invitations.accept') }}" class="flex flex-col gap-4">
                    @csrf
                    <p class="text-sm text-ink-muted">You're signed in with the invited address. Joining gives you the access {{ $tenantName }} chose for you.</p>
                    <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-ink hover:opacity-90">Join {{ $tenantName }}</button>
                </form>
            @elseif ($signedIn)
                <div class="flex flex-col gap-4">
                    <p class="text-sm text-ink-muted">You're signed in with a different account. Sign out, then open the link from the invitation email again.</p>
                    <form method="POST" action="{{ route('filament.admin.auth.logout') }}">
                        @csrf
                        <button type="submit" class="w-full rounded-lg border border-line px-4 py-2 text-sm font-semibold hover:bg-surface-muted">Sign out</button>
                    </form>
                </div>
            @else
                <div class="flex flex-col gap-3">
                    <p class="text-sm text-ink-muted">Already use {{ config('app.name') }} with this address? Sign in to accept.</p>
                    <form method="POST" action="{{ route('invitations.accept') }}">
                        @csrf
                        <button type="submit" class="w-full rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-ink hover:opacity-90">Sign in to accept</button>
                    </form>
                </div>
            @endif
        </x-portal.card>

        @unless ($signedIn)
            <x-portal.card title="New here? Create your account" description="Your sign-in email is the invited address.">
                <form method="POST" action="{{ route('invitations.register') }}" class="flex flex-col gap-4">
                    @csrf
                    <label class="flex flex-col gap-1 text-sm font-medium">
                        Your name
                        <input type="text" name="name" value="{{ old('name', $name) }}" required maxlength="255" autocomplete="name" class="@include('portal.partials.input-class')">
                    </label>
                    <label class="flex flex-col gap-1 text-sm font-medium">
                        Password
                        <input type="password" name="password" required autocomplete="new-password" class="@include('portal.partials.input-class')">
                        <span class="text-xs font-normal text-ink-muted">At least 12 characters with upper and lower case letters, a number and a symbol.</span>
                    </label>
                    <label class="flex flex-col gap-1 text-sm font-medium">
                        Confirm password
                        <input type="password" name="password_confirmation" required autocomplete="new-password" class="@include('portal.partials.input-class')">
                    </label>
                    <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-ink hover:opacity-90">Create account and join</button>
                </form>
            </x-portal.card>
        @endunless
    </div>
</x-portal.layout>
