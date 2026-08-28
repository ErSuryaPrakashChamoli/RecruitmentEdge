<x-filament-panels::page>
    <x-filament::section heading="Period">
        {{ $this->form }}
    </x-filament::section>

    <x-filament::section heading="Hiring Funnel">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 pr-4">Stage</th>
                        <th class="py-2 pr-4 text-right">Count</th>
                        <th class="py-2 text-right">Conversion from Sourced</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->getFunnel() as $row)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-4 font-medium">{{ $row['stage']->label() }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['count'] }}</td>
                            <td class="py-2 text-right">{{ $row['conversion_from_sourced'] !== null ? $row['conversion_from_sourced'].'%' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Source Analytics">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 pr-4">Source</th>
                        <th class="py-2 pr-4 text-right">Sourced</th>
                        <th class="py-2 pr-4 text-right">Interviewed</th>
                        <th class="py-2 pr-4 text-right">Selected</th>
                        <th class="py-2 text-right">Joined</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->getSourceAnalytics() as $row)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-4 font-medium">{{ $row['source']->name }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['sourced'] }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['interviewed'] }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['selected'] }}</td>
                            <td class="py-2 text-right">{{ $row['joined'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <div class="grid gap-6 md:grid-cols-2">
        <x-filament::section heading="Time to Hire &amp; Cost per Hire">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Avg. Time to Hire</div>
                    <div class="text-2xl font-semibold">{{ $this->getAverageTimeToHire() !== null ? $this->getAverageTimeToHire().' days' : '—' }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Cost per Hire</div>
                    <div class="text-2xl font-semibold">{{ $this->getCostPerHire() !== null ? '₹'.number_format($this->getCostPerHire(), 2) : '—' }}</div>
                </div>
            </div>
        </x-filament::section>
    </div>

    <x-filament::section heading="Vacancy Ageing">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 pr-4">Requisition</th>
                        <th class="py-2 pr-4">Designation</th>
                        <th class="py-2 pr-4 text-right">Ageing (days)</th>
                        <th class="py-2 text-right">Overdue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->getVacancyAgeing() as $row)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-4 font-medium">{{ $row['requisition']->code }}</td>
                            <td class="py-2 pr-4">{{ $row['requisition']->designation?->name }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['ageing_days'] }}</td>
                            <td class="py-2 text-right">
                                @if ($row['is_overdue'])
                                    <span class="font-medium text-danger-600 dark:text-danger-400">Yes</span>
                                @else
                                    No
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-4 text-center text-gray-500 dark:text-gray-400">No open or on-hold requisitions.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
