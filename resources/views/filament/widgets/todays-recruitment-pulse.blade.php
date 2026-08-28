<x-filament-widgets::widget>
    <x-filament::section heading="Today's Recruitment Pulse">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 pr-4">Metric</th>
                        <th class="py-2 pr-4 text-right">Today</th>
                        <th class="py-2 pr-4 text-right">MTD</th>
                        <th class="py-2 pr-4 text-right">Target</th>
                        <th class="py-2 text-right">Achievement</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->getRows() as $row)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-4 font-medium">{{ $row['metric']->label() }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['today'] }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['mtd'] }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['target'] ?: '—' }}</td>
                            <td class="py-2 text-right">
                                @if ($row['achievement'] !== null)
                                    <span @class([
                                        'font-medium',
                                        'text-success-600 dark:text-success-400' => $row['achievement'] >= 100,
                                        'text-warning-600 dark:text-warning-400' => $row['achievement'] < 100,
                                    ])>{{ $row['achievement'] }}%</span>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
