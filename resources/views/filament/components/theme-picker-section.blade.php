@php
    $themes = \App\Enums\AppTheme::cases();
    $current = \App\Enums\AppTheme::fromValueOrDefault(auth()->user()?->theme)->value;
@endphp

<div
    x-data="{
        appTheme: @js($current),
        setTheme(value) {
            this.appTheme = value
            document.documentElement.setAttribute('data-app-theme', value)
            $wire.setThemePreference(value)
        },
    }"
    role="radiogroup"
    aria-label="Brand theme"
    class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4"
>
    @foreach ($themes as $theme)
        <button
            type="button"
            role="radio"
            x-on:click="setTheme('{{ $theme->value }}')"
            x-bind:aria-checked="appTheme === '{{ $theme->value }}' ? 'true' : 'false'"
            x-bind:class="appTheme === '{{ $theme->value }}' ? 'border-primary-500 ring-1 ring-primary-500' : 'border-gray-200 dark:border-white/10 hover:border-gray-300 dark:hover:border-white/20'"
            class="flex items-start gap-3 rounded-xl border bg-white p-4 text-start transition-colors dark:bg-gray-900"
        >
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full {{ $theme->swatchClass() }} shadow-inner">
                <x-filament::icon
                    icon="heroicon-m-check"
                    x-show="appTheme === '{{ $theme->value }}'"
                    x-cloak
                    class="h-5 w-5 text-white"
                />
            </span>
            <span class="min-w-0">
                <span class="block text-sm font-medium text-gray-950 dark:text-white">{{ $theme->number() }}. {{ $theme->label() }}</span>
                <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ $theme->description() }}</span>
            </span>
        </button>
    @endforeach
</div>
