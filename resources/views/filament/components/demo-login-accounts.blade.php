@php
    use Database\Seeders\Demo\DemoCatalog;

    $password = (string) config('demo.password');
@endphp

<div class="mt-2 rounded-xl border border-amber-200 bg-amber-50/70 p-4 dark:border-amber-500/20 dark:bg-amber-500/5">
    <div class="flex items-center gap-2">
        <x-filament::badge color="warning">Demo</x-filament::badge>
        <p class="text-sm font-semibold text-gray-950 dark:text-white">Sign in as</p>
    </div>

    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
        Every demo account uses the password <span class="font-mono font-semibold text-gray-900 dark:text-gray-100">{{ $password }}</span>. Pick a role to fill in the form.
    </p>

    <div class="mt-3 space-y-2">
        @foreach (DemoCatalog::LOGINS as $login)
            @php
                $email = DemoCatalog::personEmail($login['person']);
                $person = DemoCatalog::PEOPLE[$login['person']];
            @endphp

            <button
                type="button"
                x-on:click="$wire.$set('data.email', @js($email), false); $wire.$set('data.password', @js($password))"
                class="flex w-full items-start justify-between gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2 text-start transition hover:border-primary-500 hover:bg-primary-50 dark:border-white/10 dark:bg-gray-900 dark:hover:bg-white/5"
            >
                <span>
                    <span class="block text-sm font-semibold text-gray-950 dark:text-white">{{ $login['role'] }} · {{ $person['first'] }} {{ $person['last'] }}</span>
                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $login['summary'] }}</span>
                </span>
                <span class="shrink-0 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $email }}</span>
            </button>
        @endforeach
    </div>
</div>
