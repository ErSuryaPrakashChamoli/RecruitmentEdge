<x-portal.layout title="Set your password">
    <div class="mx-auto w-full max-w-md">
        <x-portal.card title="Get a password link" description="Enter the email your recruiter has on file. If it has portal access, we'll email you a link to set your password.">
            <form method="POST" action="{{ route('portal.password.email') }}" class="flex flex-col gap-4">
                @csrf
                <label class="flex flex-col gap-1 text-sm font-medium">
                    Email
                    <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" class="@include('portal.partials.input-class')">
                </label>
                <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-ink hover:opacity-90">Email me a link</button>
                <a href="{{ route('portal.login') }}" class="text-center text-sm text-brand hover:underline">Back to sign in</a>
            </form>
        </x-portal.card>
    </div>
</x-portal.layout>
