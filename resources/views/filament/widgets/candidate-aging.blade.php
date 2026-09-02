<x-filament-widgets::widget>
    <x-filament::section heading="Candidate Aging" description="Active candidates by days since last activity" collapsible collapsed>
        @php $rows = $this->getRows(); @endphp

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 pr-4">Stage</th>
                        <th class="py-2 pr-3 text-right">0-2d</th>
                        <th class="py-2 pr-3 text-right">3-5d</th>
                        <th class="py-2 pr-3 text-right">6-10d</th>
                        <th class="py-2 text-right">10d+</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-4 font-medium">{{ $row['stage']->label() }}</td>
                            <td class="py-2 pr-3 text-right">{{ $row['buckets']['0_2'] }}</td>
                            <td class="py-2 pr-3 text-right">{{ $row['buckets']['3_5'] }}</td>
                            <td class="py-2 pr-3 text-right text-amber-600 dark:text-amber-400">{{ $row['buckets']['6_10'] }}</td>
                            <td class="py-2 text-right text-rose-600 dark:text-rose-400 font-semibold">{{ $row['buckets']['10_plus'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
