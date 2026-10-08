<x-filament-widgets::widget>
    <x-filament::section heading="SLA / Turn-Around-Time" collapsible collapsed>
        @php $tth = $this->getTimeToHire(); @endphp

        <div class="mb-4 flex items-center justify-between rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2">
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">Median Time to Hire</p>
                <p class="text-lg font-semibold">{{ $tth['median_days'] !== null ? $tth['median_days'].' days' : ($tth['status'] === 'insufficient_sample' ? 'Too few hires' : '—') }}</p>
            </div>
            <div class="text-right">
                <p class="text-xs text-gray-500 dark:text-gray-400">Target {{ $tth['target_days'] }}d &middot; n = {{ $tth['sample_size'] }}</p>
                <p @class([
                    'text-sm font-medium',
                    'text-emerald-600 dark:text-emerald-400' => $tth['status'] === 'on_track',
                    'text-rose-600 dark:text-rose-400' => $tth['status'] === 'needs_attention',
                    'text-gray-400' => in_array($tth['status'], ['no_data', 'insufficient_sample'], true),
                ])>
                    {{ match ($tth['status']) { 'on_track' => 'On Track', 'needs_attention' => 'Needs Attention', 'insufficient_sample' => 'Too Few Hires', default => 'No Data' } }}
                </p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 pr-4">Stage</th>
                        <th class="py-2 pr-3 text-right">Median</th>
                        <th class="py-2 pr-3 text-right">Target</th>
                        <th class="py-2 pr-3 text-right" title="Share of legs completed within target (higher is better)">Within target</th>
                        <th class="py-2 text-right">Breaches</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->getRows() as $row)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-4 font-medium">{{ $row['label'] }}</td>
                            <td class="py-2 pr-3 text-right">{{ $row['median_days'] !== null ? $row['median_days'].'d' : '—' }}</td>
                            <td class="py-2 pr-3 text-right text-gray-500 dark:text-gray-400">{{ $row['target_days'] }}d</td>
                            <td class="py-2 pr-3 text-right">
                                @if ($row['compliance_percent'] !== null)
                                    <span @class(['font-medium', 'text-rose-600 dark:text-rose-400' => $row['compliance_percent'] < 80])>{{ $row['compliance_percent'] }}%</span>
                                @else
                                    &mdash;
                                @endif
                            </td>
                            <td class="py-2 text-right">{{ $row['breaches'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
