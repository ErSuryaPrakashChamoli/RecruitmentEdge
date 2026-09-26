<x-portal.layout title="Set your password">
    <div class="mx-auto w-full max-w-md">
        <x-portal.card title="Set your password" description="Use at least 10 characters with upper- and lower-case letters and a number.">
            <form method="POST" action="{{ $action }}" class="flex flex-col gap-4">
                @csrf
                <p class="text-sm text-ink-muted">Signing in as <span class="font-medium text-ink">{{ $account->email }}</span></p>
                <label class="flex flex-col gap-1 text-sm font-medium">
                    New password
                    <input type="password" name="password" required autocomplete="new-password" class="@include('portal.partials.input-class')">
                </label>
                <label class="flex flex-col gap-1 text-sm font-medium">
                    Confirm password
                    <input type="password" name="password_confirmation" required autocomplete="new-password" class="@include('portal.partials.input-class')">
                </label>
                <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-ink hover:opacity-90">Save and continue</button>
            </form>
        </x-portal.card>
    </div>
</x-portal.layout>
