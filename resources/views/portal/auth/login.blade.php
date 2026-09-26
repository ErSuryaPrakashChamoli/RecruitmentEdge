<x-portal.layout title="Sign in">
    <div class="mx-auto w-full max-w-md">
        <x-portal.card title="Sign in to your candidate portal" description="Follow your applications, confirm interviews and share documents.">
            <form method="POST" action="{{ route('portal.login.store') }}" class="flex flex-col gap-4">
                @csrf
                <label class="flex flex-col gap-1 text-sm font-medium">
                    Email
                    <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus class="@include('portal.partials.input-class')">
                </label>
                <label class="flex flex-col gap-1 text-sm font-medium">
                    Password
                    <input type="password" name="password" required autocomplete="current-password" class="@include('portal.partials.input-class')">
                </label>
                <label class="flex items-center gap-2 text-sm text-ink-muted">
                    <input type="checkbox" name="remember" value="1" class="rounded border-line"> Keep me signed in
                </label>
                <button type="submit" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-brand-ink hover:opacity-90">Sign in</button>
                <a href="{{ route('portal.password.forgot') }}" class="text-center text-sm text-brand hover:underline">First time here, or forgot your password?</a>
            </form>
        </x-portal.card>
    </div>
</x-portal.layout>
