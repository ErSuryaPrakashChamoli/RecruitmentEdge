<x-filament-widgets::widget>
    <x-filament::section heading="Interview Analytics" collapsible collapsed>
        @php $a = $this->getAnalytics(); @endphp

        <div class="mb-4 grid grid-cols-3 gap-2">
            <x-recruitment.kpi-card
                label="Completion"
                :value="$a['completion_percent'] !== null ? $a['completion_percent'].'%' : '—'"
            />
            <x-recruitment.kpi-card
                label="No-show"
                :value="$a['no_show_percent'] !== null ? $a['no_show_percent'].'%' : '—'"
                :color="($a['no_show_percent'] ?? 0) > 20 ? 'danger' : 'default'"
            />
            <x-recruitment.kpi-card
                label="Selection"
                :value="$a['selection_percent'] !== null ? $a['selection_percent'].'%' : '—'"
            />
        </div>

        <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
            {{ $a['scheduled'] }} scheduled &middot; {{ $a['completed'] }} completed &middot; {{ $a['no_show'] }} no-show &middot; {{ $a['cancelled'] }} cancelled &middot; {{ $a['rescheduled'] }} rescheduled &middot; {{ $a['feedback_pending'] }} feedback pending
        </p>

        @if (($a['by_interviewer'] ?? collect())->isNotEmpty())
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-4">Interviewer</th>
                            <th class="py-2 pr-3 text-right">Scheduled</th>
                            <th class="py-2 pr-3 text-right">No-show %</th>
                            <th class="py-2 text-right">Selected</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($a['by_interviewer'] as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 font-medium">{{ $row['interviewer'] }}</td>
                                <td class="py-2 pr-3 text-right">{{ $row['scheduled'] }}</td>
                                <td class="py-2 pr-3 text-right">{{ $row['no_show_percent'] !== null ? $row['no_show_percent'].'%' : '—' }}</td>
                                <td class="py-2 text-right">{{ $row['selected'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
