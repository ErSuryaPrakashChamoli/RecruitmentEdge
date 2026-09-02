<x-filament-widgets::widget>
    <x-filament::section :heading="$this->isIndividualView() ? 'My Performance' : 'Recruiter Performance'" collapsible collapsed>
        <x-slot name="afterHeader">
            <a href="{{ $this->getLeaderboardUrl() }}" class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                View full leaderboard
            </a>
        </x-slot>

        @php $rows = $this->getRows(); @endphp

        @if ($rows->isEmpty())
            <x-recruitment.empty-state
                icon="heroicon-o-user-group"
                heading="No recruiter activity"
                description="Nothing to rank yet for this period."
            />
        @else
            <div class="space-y-2.5">
                @foreach ($rows as $index => $row)
                    @php
                        $score = $row['score'];
                        $status = match (true) {
                            $score === null => null,
                            $index === 0 && $score >= 70 => ['label' => 'Top Performer', 'classes' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'],
                            $score >= 70 => ['label' => 'On Track', 'classes' => 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-400'],
                            default => ['label' => 'Needs Attention', 'classes' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400'],
                        };
                        $barColor = match (true) {
                            $score === null => 'bg-gray-300 dark:bg-gray-600',
                            $score >= 70 => 'bg-emerald-500',
                            default => 'bg-amber-500',
                        };
                    @endphp
                    <div class="flex items-center gap-3">
                        <x-recruitment.avatar-initials :name="$row['recruiter']->fullName()" />

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center justify-between gap-2">
                                <span class="truncate text-sm font-medium text-gray-700 dark:text-gray-300">{{ $row['recruiter']->fullName() }}</span>
                                @if ($status)
                                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $status['classes'] }}">
                                        {{ $status['label'] }}
                                    </span>
                                @endif
                            </div>
                            <div class="mt-1 flex items-center gap-2">
                                <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-white/5">
                                    <span class="block h-full rounded-full {{ $barColor }}" style="width: {{ min(100, max(0, $score ?? 0)) }}%"></span>
                                </span>
                                <span class="w-10 shrink-0 text-right text-xs tabular-nums text-gray-500 dark:text-gray-400">{{ $score !== null ? $score : '—' }}</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
