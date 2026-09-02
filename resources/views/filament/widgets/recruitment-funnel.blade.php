<x-filament-widgets::widget>
    <x-filament::section heading="Recruitment Funnel" description="Applications through to Joining, with conversion % from Sourced and drop-off between stages" collapsible collapsed>
        @php $rows = $this->getRows(); $max = $rows->max('count') ?: 1; $total = $rows->count(); @endphp

        @if ($rows->isEmpty())
            <x-recruitment.empty-state
                icon="heroicon-o-funnel"
                heading="No applications in this period"
                description="Try a different period, or check that candidates are being sourced."
            />
        @else
            <div>
                @foreach ($rows as $index => $row)
                    <a href="{{ $row['url'] }}" class="group block">
                        <div class="mb-1 flex items-center justify-between text-sm">
                            <span class="font-medium text-gray-700 group-hover:text-primary-600 group-hover:underline dark:text-gray-300 dark:group-hover:text-primary-400">
                                {{ $row['stage']->label() }}
                            </span>
                            <span class="tabular-nums text-gray-500 dark:text-gray-400">
                                <span class="font-semibold text-gray-900 dark:text-white">{{ $row['count'] }}</span>
                                @if ($row['conversion_from_sourced'] !== null)
                                    <span class="text-gray-400 dark:text-gray-500">({{ $row['conversion_from_sourced'] }}% of sourced)</span>
                                @endif
                                @if ($row['drop_off_percent'] !== null && $row['drop_off_percent'] > 0)
                                    <span class="font-medium text-rose-600 dark:text-rose-400">-{{ $row['drop_off_percent'] }}%</span>
                                @endif
                            </span>
                        </div>
                        <div class="h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                            <div
                                class="h-full rounded-full bg-gradient-to-r from-blue-600 to-indigo-500 transition-all"
                                style="width: {{ $max > 0 ? round($row['count'] / $max * 100, 1) : 0 }}%"
                            ></div>
                        </div>
                    </a>

                    @if (! $loop->last)
                        <div class="flex justify-center py-1">
                            <x-filament::icon icon="heroicon-o-chevron-down" class="h-3 w-3 text-gray-300 dark:text-gray-700" />
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
