<div
    x-data
    @keydown.window.prevent.cmd.k="$wire.open()"
    @keydown.window.prevent.ctrl.k="$wire.open()"
    @keydown.window.escape="$wire.close()"
>
    @if ($isOpen)
        <div class="fixed inset-0 z-[100] flex items-start justify-center bg-gray-950/50 pt-24" wire:click.self="close">
            <div class="w-full max-w-xl overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl dark:border-white/10 dark:bg-gray-900">
                <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-3 dark:border-white/5">
                    <x-filament::icon icon="heroicon-o-magnifying-glass" class="h-5 w-5 text-gray-400" />
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="Search candidates, applications, or run a command..."
                        autofocus
                        class="w-full border-0 bg-transparent text-sm text-gray-950 placeholder:text-gray-400 focus:outline-none focus:ring-0 dark:text-white"
                    />
                    <kbd class="rounded border border-gray-200 px-1.5 py-0.5 text-xs text-gray-400 dark:border-white/10">Esc</kbd>
                </div>

                <div class="max-h-96 overflow-y-auto py-2">
                    @if (filled($search) && count($results) > 0)
                        <p class="px-4 pb-1 pt-2 text-xs font-semibold uppercase text-gray-400">Search Results</p>
                        @foreach ($results as $result)
                            <a
                                href="{{ $result['url'] }}"
                                class="flex items-center justify-between px-4 py-2 text-sm hover:bg-gray-50 dark:hover:bg-white/5"
                            >
                                <span class="text-gray-700 dark:text-gray-300">{{ $result['title'] }}</span>
                                <span class="text-xs text-gray-400">{{ $result['group'] }}</span>
                            </a>
                        @endforeach
                    @endif

                    <p class="px-4 pb-1 pt-2 text-xs font-semibold uppercase text-gray-400">Actions</p>
                    @forelse ($commands as $command)
                        <a
                            href="{{ $command['url'] }}"
                            class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5"
                        >
                            <x-filament::icon icon="heroicon-o-bolt" class="h-4 w-4 text-gray-400" />
                            {{ $command['label'] }}
                        </a>
                    @empty
                        <p class="px-4 py-6 text-center text-sm text-gray-400">No matching commands.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</div>
