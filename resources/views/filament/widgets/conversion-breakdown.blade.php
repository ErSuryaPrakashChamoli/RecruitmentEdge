<x-filament-widgets::widget>
    <x-filament::section heading="Turn-up → Selection → Joining" collapsible collapsed>
        <x-slot name="afterHeader">
            <div class="flex gap-1">
                @foreach (['recruiter' => 'By Recruiter', 'requisition' => 'By Position', 'source' => 'By Source'] as $key => $label)
                    <button
                        type="button"
                        wire:click="setGroupBy('{{ $key }}')"
                        @class([
                            'px-2 py-1 text-xs font-medium rounded-md',
                            'bg-amber-600 text-white' => $groupBy === $key,
                            'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300' => $groupBy !== $key,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>
        </x-slot>

        @php $rows = $this->getRows(); @endphp

        @if ($rows->isEmpty())
            <x-recruitment.empty-state
                icon="heroicon-o-chart-bar"
                heading="No data for this period"
                description="Try a different period, or check back once applications start moving through the funnel."
            />
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-4">{{ ucfirst($groupBy === 'requisition' ? 'position' : $groupBy) }}</th>
                            <th class="py-2 pr-4 text-right">Turn-ups</th>
                            <th class="py-2 pr-4 text-right">Selections</th>
                            <th class="py-2 pr-4 text-right">Joined</th>
                            <th class="py-2 pr-4 text-right">Selection %</th>
                            <th class="py-2 text-right">Joining %</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4 font-medium">{{ $row['group'] }}</td>
                                <td class="py-2 pr-4 text-right">{{ $row['turnups'] }}</td>
                                <td class="py-2 pr-4 text-right">{{ $row['selections'] }}</td>
                                <td class="py-2 pr-4 text-right">{{ $row['joined'] }}</td>
                                <td class="py-2 pr-4 text-right">
                                    @if ($row['selection_ratio'] !== null)
                                        <span @class(['font-medium', 'text-amber-600 dark:text-amber-400' => $row['selection_ratio'] < 30])>{{ $row['selection_ratio'] }}%</span>
                                    @else
                                        &mdash;
                                    @endif
                                </td>
                                <td class="py-2 text-right">
                                    @if ($row['joining_ratio'] !== null)
                                        <span @class(['font-medium', 'text-amber-600 dark:text-amber-400' => $row['joining_ratio'] < 50])>{{ $row['joining_ratio'] }}%</span>
                                    @else
                                        &mdash;
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
