<x-filament-panels::page>
    <x-filament::section heading="Filters" description="The period applies to every report; requisition, department and source narrow Cost per Hire and Time to Hire only. Days are business days in {{ \App\Services\Metrics\MetricPeriod::timezone() }}.">
        {{ $this->form }}
    </x-filament::section>

    <x-filament::section heading="Hiring Funnel" description="Of the applications created in the period, how many have reached each stage so far (a stage counts once reached, including by moving past it). Joined = joining record marked Joined.">
        @if ($this->canExport())
            <x-slot name="afterHeader">
                <x-filament::button size="xs" color="gray" outlined wire:click="exportFunnel">
                    Export CSV
                </x-filament::button>
            </x-slot>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 pr-4">Stage</th>
                        <th class="py-2 pr-4 text-right">Reached</th>
                        <th class="py-2 text-right">% of applications</th>
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

    <x-filament::section heading="Source ROI" description="Applications created in the period by the candidate's current source, how far they got, and the source's spend on your requisitions">
        @if ($this->canExport())
            <x-slot name="afterHeader">
                <x-filament::button size="xs" color="gray" outlined wire:click="exportSourceRoi">
                    Export CSV
                </x-filament::button>
            </x-slot>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 pr-4">Source</th>
                        <th class="py-2 pr-4 text-right">Spend</th>
                        <th class="py-2 pr-4 text-right">Applications</th>
                        <th class="py-2 pr-4 text-right">Connected</th>
                        <th class="py-2 pr-4 text-right">Interested</th>
                        <th class="py-2 pr-4 text-right">Interviewed</th>
                        <th class="py-2 pr-4 text-right">Selected</th>
                        <th class="py-2 pr-4 text-right">Offers</th>
                        <th class="py-2 pr-4 text-right">Joined</th>
                        <th class="py-2 pr-4 text-right">Conversion %</th>
                        <th class="py-2 pr-4 text-right">Cost / Interview</th>
                        <th class="py-2 pr-4 text-right">Cost / Selection</th>
                        <th class="py-2 text-right">Cost / Join</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->getSourceAnalytics() as $row)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-4 font-medium">{{ $row['source_name'] }}</td>
                            <td class="py-2 pr-4 text-right">₹{{ number_format($row['spend'], 2) }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['sourced'] }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['connected'] }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['interested'] }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['interviewed'] }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['selected'] }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['offers'] }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['joined'] }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['conversion_percent'] !== null ? $row['conversion_percent'].'%' : '—' }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['cost_per_interview'] !== null ? '₹'.number_format($row['cost_per_interview'], 2) : '—' }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['cost_per_selection'] !== null ? '₹'.number_format($row['cost_per_selection'], 2) : '—' }}</td>
                            <td class="py-2 text-right">{{ $row['cost_per_join'] !== null ? '₹'.number_format($row['cost_per_join'], 2) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <div class="grid gap-6 md:grid-cols-2">
        <x-filament::section heading="Time to Hire & Cost per Hire">
            @php
                $timeToHire = $this->getTimeToHire();
                $costPerHire = $this->getCostPerHire();
            @endphp
            <div class="grid grid-cols-2 gap-4">
                <x-recruitment.metric-card :result="$timeToHire" />
                <x-recruitment.metric-card :result="$costPerHire" />
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $timeToHire->basisLine() }} &middot; {{ $costPerHire->basisLine() }}</p>
        </x-filament::section>
    </div>

    <x-filament::section heading="Vacancy Ageing">
        @if ($this->canExport())
            <x-slot name="afterHeader">
                <x-filament::button size="xs" color="gray" outlined wire:click="exportVacancyAgeing">
                    Export CSV
                </x-filament::button>
            </x-slot>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2 pr-4">Requisition</th>
                        <th class="py-2 pr-4">Designation</th>
                        <th class="py-2 pr-4">Priority</th>
                        <th class="py-2 pr-4 text-right">Ageing (days)</th>
                        <th class="py-2 text-right">Overdue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->getVacancyAgeing() as $row)
                        @php $priority = $this->priorityLabel($row['priority'] ?? null); @endphp
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-4 font-medium">{{ $row['requisition']->code }}</td>
                            <td class="py-2 pr-4">{{ $row['requisition']->designation?->name }}</td>
                            <td class="py-2 pr-4">{{ $priority ?? '—' }}</td>
                            <td class="py-2 pr-4 text-right">{{ $row['ageing_days'] }}</td>
                            <td class="py-2 text-right">
                                @if ($row['is_overdue'])
                                    <span class="font-medium text-rose-600 dark:text-rose-400">Yes</span>
                                @else
                                    No
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-recruitment.empty-state
                                    icon="heroicon-o-briefcase"
                                    heading="No open or on-hold requisitions"
                                />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
