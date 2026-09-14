<x-filament-widgets::widget>
    @php
        $periodLabel = $this->getPeriodLabel();
        $isTodayOnly = $this->isTodayOnly();
    @endphp

    <x-filament::section heading="Today's Recruitment Pulse" :description="'Target vs actual for ' . $periodLabel . ' across every recruiter in scope'" collapsible collapsed>
        <div class="space-y-3">
            @foreach ($this->getRows() as $row)
                @php
                    $achievement = $row['achievement'];
                    $barColor = match (true) {
                        $achievement === null => 'bg-gray-300 dark:bg-gray-600',
                        $achievement >= 100 => 'bg-emerald-500',
                        $achievement >= 70 => 'bg-amber-500',
                        default => 'bg-rose-500',
                    };
                @endphp
                <div class="grid grid-cols-1 items-center gap-2 sm:grid-cols-12 sm:gap-4">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300 sm:col-span-3">{{ $row['label'] }}</p>

                    <div class="flex flex-wrap items-center gap-3 text-xs text-gray-500 dark:text-gray-400 sm:col-span-4">
                        @unless ($isTodayOnly)
                            <span>Today <span class="font-semibold text-gray-900 dark:text-white">{{ $row['today'] }}</span></span>
                        @endunless
                        <span>{{ $isTodayOnly ? 'Actual' : $periodLabel }} <span class="font-semibold text-gray-900 dark:text-white">{{ $row['actual'] }}</span></span>
                        <span>Target <span class="font-semibold text-gray-900 dark:text-white">{{ $row['target'] ?? '—' }}</span></span>
                    </div>

                    <div class="sm:col-span-4">
                        <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                            <div class="h-full rounded-full {{ $barColor }} transition-all" style="width: {{ $achievement !== null ? min(100, max(0, $achievement)) : 0 }}%"></div>
                        </div>
                    </div>

                    <p class="text-right text-xs font-semibold tabular-nums text-gray-700 dark:text-gray-300 sm:col-span-1">
                        {{ $achievement !== null ? $achievement.'%' : '—' }}
                    </p>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
