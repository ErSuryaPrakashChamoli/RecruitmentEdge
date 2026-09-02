<x-filament-panels::page>
    <div
        x-data="{ previewDark: false }"
        class="space-y-6"
    >
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div>
                <p class="text-sm font-medium text-gray-950 dark:text-white">Compare all 8 themes</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">Previewing here never changes your active theme — only "Apply Theme" does.</p>
            </div>
            <div class="flex items-center gap-1 rounded-lg border border-gray-200 p-1 dark:border-white/10" role="radiogroup" aria-label="Preview mode">
                <button
                    type="button"
                    x-on:click="previewDark = false"
                    x-bind:class="! previewDark ? 'bg-gray-100 dark:bg-white/10 text-gray-950 dark:text-white' : 'text-gray-500 dark:text-gray-400'"
                    class="rounded-md px-3 py-1 text-xs font-medium transition-colors"
                >
                    Preview Light
                </button>
                <button
                    type="button"
                    x-on:click="previewDark = true"
                    x-bind:class="previewDark ? 'bg-gray-100 dark:bg-white/10 text-gray-950 dark:text-white' : 'text-gray-500 dark:text-gray-400'"
                    class="rounded-md px-3 py-1 text-xs font-medium transition-colors"
                >
                    Preview Dark
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->getThemes() as $theme)
                @php $shades = $theme->previewShades(); @endphp
                <div class="flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900 {{ $this->getCurrentTheme() === $theme ? 'ring-2 ring-primary-500' : '' }}">
                    {{-- Isolated mock preview: colors come from inline custom properties scoped to
                         this div only, never from the real :root[data-app-theme] cascade. --}}
                    <div
                        x-bind:class="previewDark ? 'dark' : ''"
                        style="--pv-500: {{ $shades[500] }}; --pv-600: {{ $shades[600] }}; --pv-800: {{ $shades[800] }}; --pv-900: {{ $shades[900] }};"
                    >
                        <div class="flex h-32 gap-0 bg-gray-50 p-3 dark:bg-gray-950">
                            <div class="flex w-9 shrink-0 flex-col gap-1.5 rounded-l-lg p-2" style="background: linear-gradient(165deg, var(--pv-900), #05070d)">
                                <span class="h-2 w-2 rounded-full" style="background: var(--pv-500)"></span>
                                <span class="mt-2 h-1.5 w-full rounded-full bg-white/30"></span>
                                <span class="h-1.5 w-full rounded-full bg-white/15"></span>
                                <span class="h-1.5 w-3/4 rounded-full bg-white/15"></span>
                            </div>
                            <div class="flex flex-1 flex-col overflow-hidden rounded-r-lg border border-l-0 border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
                                <div class="h-7 shrink-0" style="background: linear-gradient(135deg, var(--pv-600), var(--pv-800))"></div>
                                <div class="flex-1 space-y-1.5 p-2">
                                    <span class="block h-2 w-4/5 rounded-full bg-gray-200 dark:bg-white/10"></span>
                                    <span class="block h-2 w-3/5 rounded-full bg-gray-200 dark:bg-white/10"></span>
                                    <span class="mt-1.5 inline-block rounded-full px-2 py-0.5 text-[9px] font-semibold text-white" style="background: var(--pv-600)">Sample</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-1 flex-col gap-3 p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $theme->number() }}. {{ $theme->label() }}</p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $theme->description() }}</p>
                            </div>
                            @if ($this->getCurrentTheme() === $theme)
                                <x-filament::badge color="success" size="xs">Active</x-filament::badge>
                            @endif
                        </div>

                        <div class="flex items-center gap-1">
                            @foreach ([100, 300, 500, 700, 900] as $shade)
                                <span class="h-4 w-4 rounded-full ring-1 ring-black/5" style="background: {{ $shades[$shade] }}"></span>
                            @endforeach
                        </div>

                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            <span class="font-medium text-gray-700 dark:text-gray-300">Best for:</span>
                            {{ $theme->bestFor() }}
                        </p>

                        <div class="mt-auto pt-1">
                            <x-filament::button
                                size="sm"
                                :disabled="$this->getCurrentTheme() === $theme"
                                wire:click="applyTheme('{{ $theme->value }}')"
                                class="w-full justify-center"
                            >
                                {{ $this->getCurrentTheme() === $theme ? 'Currently Active' : 'Apply Theme' }}
                            </x-filament::button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
