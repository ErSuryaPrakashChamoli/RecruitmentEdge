<x-filament-widgets::widget>
    <x-filament::section heading="Action Center" description="Real pending work, pulled live from the database" collapsible collapsed>
        @php $work = $this->getPendingWork(); @endphp

        @if ($work->isEmpty())
            <x-recruitment.empty-state
                icon="heroicon-o-check-circle"
                heading="All caught up"
                description="Nothing pending — the queue is clear."
            />
        @else
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                @foreach ($work as $item)
                    <a
                        href="{{ $item['url'] }}"
                        class="group flex items-center gap-3 rounded-lg border border-gray-100 px-3 py-2.5 text-sm transition-colors hover:border-gray-200 hover:bg-gray-50 dark:border-white/5 dark:hover:border-white/10 dark:hover:bg-white/5"
                    >
                        <span @class([
                            'h-2 w-2 shrink-0 rounded-full',
                            'bg-rose-500' => $item['priority'] === 'critical',
                            'bg-amber-500' => $item['priority'] !== 'critical',
                        ])></span>

                        <span class="min-w-0 flex-1 truncate font-medium text-gray-700 dark:text-gray-300">{{ $item['label'] }}</span>

                        <span @class([
                            'shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums',
                            'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400' => $item['priority'] === 'critical',
                            'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400' => $item['priority'] !== 'critical',
                        ])>{{ $item['count'] }}</span>

                        <x-filament::icon icon="heroicon-o-chevron-right" class="h-3.5 w-3.5 shrink-0 text-gray-300 transition-transform group-hover:translate-x-0.5 dark:text-gray-600" />
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
