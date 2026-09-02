<x-filament-widgets::widget>
    <x-filament::section heading="Hiring Requirement Health" collapsible collapsed>
        @php $rows = $this->getRows(); $summary = $this->getSummary(); @endphp

        <div class="mb-4 grid grid-cols-4 gap-2">
            <x-recruitment.kpi-card label="Open Positions" :value="$summary['total']" />
            <x-recruitment.kpi-card label="On Track" :value="$summary['on_track']" color="success" tint />
            <x-recruitment.kpi-card label="At Risk" :value="$summary['at_risk']" color="warning" tint />
            <x-recruitment.kpi-card label="Critical" :value="$summary['critical']" color="danger" tint />
        </div>

        @if ($rows->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No open or on-hold positions.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-4">Position</th>
                            <th class="py-2 pr-3 text-right">Required</th>
                            <th class="py-2 pr-3 text-right">Filled</th>
                            <th class="py-2 pr-3 text-right">Remaining</th>
                            <th class="py-2 pr-3 text-right">Fulfilment</th>
                            <th class="py-2 pr-3 text-right">Pipeline</th>
                            <th class="py-2 pr-3 text-right">Days Open</th>
                            <th class="py-2 text-right">Risk</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="py-2 pr-4">
                                    <a href="{{ $row['url'] }}" class="font-medium hover:underline">{{ $row['requisition']->code }}</a>
                                </td>
                                <td class="py-2 pr-3 text-right">{{ $row['required'] }}</td>
                                <td class="py-2 pr-3 text-right">{{ $row['filled'] }}</td>
                                <td class="py-2 pr-3 text-right">{{ $row['remaining'] }}</td>
                                <td class="py-2 pr-3 text-right">{{ $row['fulfilment_percent'] }}%</td>
                                <td class="py-2 pr-3 text-right">{{ $row['pipeline'] }}</td>
                                <td class="py-2 pr-3 text-right">{{ $row['ageing_days'] }}</td>
                                <td class="py-2 text-right">
                                    <x-filament::badge :color="match ($row['risk']) { 'critical' => 'danger', 'at_risk' => 'warning', default => 'success' }">
                                        {{ match ($row['risk']) { 'critical' => 'Critical', 'at_risk' => 'At Risk', default => 'On Track' } }}
                                    </x-filament::badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
