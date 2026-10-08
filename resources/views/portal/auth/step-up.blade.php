<x-portal.layout title="Verify it's you">
    <div class="mx-auto w-full max-w-md">
        <x-portal.card title="Verify it's you" description="For this step we email a one-time code to your portal email address.">
            <form method="POST" action="{{ route('portal.step-up.send') }}" class="flex flex-col gap-2">
                @csrf
                <button type="submit" class="rounded-lg border border-line px-4 py-2 text-sm font-semibold hover:opacity-90">Email me a code</button>
            </form>
            <form method="POST" action="{{ route('portal.step-up.verify') }}" class="mt-4 flex flex-col gap-4">
                @csrf
                <label class="flex flex-col gap-1 text-sm font-medium">
                    6-digit code
                    <input type="text" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" required class="@include('portal.partials.input-class')">
                </label>
                <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-ink hover:opacity-90">Verify</button>
            </form>
        </x-portal.card>
    </div>
</x-portal.layout>
