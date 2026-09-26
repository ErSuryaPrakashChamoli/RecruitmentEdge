<x-filament-panels::page>
    @php
        $report = $this->report();
        $period = $report['period'];
        $metrics = $report['metrics'];
        $joining = $metrics['joining'];
        $offers = $metrics['offers'];
        $timeToHire = $metrics['time_to_hire'];
        $stages = $metrics['time_in_stage'];
        $sources = $metrics['source_to_join'];
        $interviews = $metrics['interview_evidence'];
        $status = $metrics['status_observations'];
        $headline = fn (array $metric) => $metric['value'] !== null ? $metric['value'].($metric['unit'] === '%' ? '%' : '') : '—';
    @endphp

    <p class="text-sm text-gray-600 dark:text-gray-300">
        Outcomes are recorded by deterministic rules from joining records, offer history and the hiring snapshot taken at each join — never by AI and never from the pipeline stage alone.
        Figures are observational: they describe what happened, not why. Anything that could not be observed is shown as such and never counted as a failure.
        A rate is withheld when fewer than {{ config('outcomes.sample.insufficient_below', 3) }} outcomes support it.
        <span class="block mt-1 text-xs text-gray-500 dark:text-gray-400">Last outcome recorded: {{ $report['freshness'] ?? 'none yet' }} · outcomes are evaluated daily.</span>
    </p>

    <x-filament::section heading="Filters" description="The period applies to when each outcome happened (the joining date for hires). Requisition, department, designation and location narrow to requisitions you can see.">
        {{ $this->form }}
    </x-filament::section>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([['Completed hires', $joining['completed_hires'], 'Joining record marked Joined'], ['Join rate', $headline($joining), $joining['sample_size'].' final joining outcome(s)'], ['Offer acceptance', $headline($offers), $offers['sample_size'].' decided offer(s)'], ['Median time to hire', $timeToHire['value'] !== null ? $timeToHire['value'].' days' : '—', $timeToHire['sample_size'].' measured join(s)']] as [$label, $value, $hint])
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $value }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
            </div>
        @endforeach
    </div>

    <x-filament::section :heading="$joining['label']">
        <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            @foreach (['joined' => 'Joined', 'no_show' => 'No-show', 'dropout' => 'Dropped out', 'pending' => 'Pending (not final)'] as $key => $label)
                <div><p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p><p class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ $joining['counts'][$key] }}</p></div>
            @endforeach
        </div>
        @include('filament.outcomes.metric-meta', ['metric' => $joining, 'period' => $period])
    </x-filament::section>

    <x-filament::section :heading="$offers['label']">
        <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-6">
            @foreach (['released' => 'Released', 'offer_accepted' => 'Accepted', 'offer_rejected' => 'Rejected', 'offer_expired' => 'Expired', 'offer_withdrawn' => 'Withdrawn', 'awaiting_decision' => 'Awaiting decision'] as $key => $label)
                <div><p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p><p class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ $offers['counts'][$key] }}</p></div>
            @endforeach
        </div>
        @include('filament.outcomes.metric-meta', ['metric' => $offers, 'period' => $period])
    </x-filament::section>

    <x-filament::section :heading="$timeToHire['label']">
        <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            @foreach (['value' => 'Median', 'average' => 'Average', 'min' => 'Fastest', 'max' => 'Slowest'] as $key => $label)
                <div><p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p><p class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ $timeToHire['band']->isSufficient() && $timeToHire[$key] !== null ? $timeToHire[$key].' days' : '—' }}</p></div>
            @endforeach
        </div>
        @include('filament.outcomes.metric-meta', ['metric' => $timeToHire, 'period' => $period])
    </x-filament::section>

    <x-filament::section :heading="$stages['label']">
        @if ($stages['stages'] === [])
            <p class="text-sm text-gray-600 dark:text-gray-300">No stage history recorded for hires in this period.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr><th class="py-2 pr-3 font-medium">Stage</th><th class="py-2 pr-3 text-right font-medium">Average days</th><th class="py-2 pr-3 text-right font-medium">Hires</th><th class="py-2 font-medium">History</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($stages['stages'] as $row)
                            <tr class="text-gray-800 dark:text-gray-200" wire:key="stage-{{ $row['stage'] }}">
                                <td class="py-2 pr-3">{{ $row['label'] }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $row['band']->isSufficient() ? $row['average_days'] : '—' }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $row['sample_size'] }}</td>
                                <td class="py-2"><x-filament::badge :color="$row['band']->color()" size="sm">{{ $row['band']->label() }}</x-filament::badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @include('filament.outcomes.metric-meta', ['metric' => $stages, 'period' => $period])
    </x-filament::section>

    <x-filament::section :heading="$sources['label']">
        @if ($sources['sources'] === [])
            <p class="text-sm text-gray-600 dark:text-gray-300">No final joining outcomes in this period.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr><th class="py-2 pr-3 font-medium">Source</th><th class="py-2 pr-3 text-right font-medium">Joined</th><th class="py-2 pr-3 text-right font-medium">No-show</th><th class="py-2 pr-3 text-right font-medium">Dropout</th><th class="py-2 pr-3 text-right font-medium">Join rate</th><th class="py-2 font-medium">Sample</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($sources['sources'] as $row)
                            <tr class="text-gray-800 dark:text-gray-200">
                                <td class="py-2 pr-3">{{ $row['source'] }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $row['joined'] }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $row['no_show'] }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $row['dropout'] }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">{{ $row['rate'] !== null ? $row['rate'].'%' : '—' }}</td>
                                <td class="py-2">{{ $row['sample_size'] }} <x-filament::badge :color="$row['band']->color()" size="sm" class="ms-1 inline-flex">{{ $row['band']->label() }}</x-filament::badge></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        @include('filament.outcomes.metric-meta', ['metric' => $sources, 'period' => $period])
    </x-filament::section>

    <x-filament::section :heading="$interviews['label']">
        <div class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            <div><p class="text-xs text-gray-500 dark:text-gray-400">Hires</p><p class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ $interviews['hires'] }}</p></div>
            <div><p class="text-xs text-gray-500 dark:text-gray-400">Average rounds</p><p class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ $interviews['band']->isSufficient() ? ($interviews['average_rounds'] ?? '—') : '—' }}</p></div>
            <div><p class="text-xs text-gray-500 dark:text-gray-400">Average feedback score</p><p class="text-lg font-semibold tabular-nums text-gray-950 dark:text-white">{{ $interviews['value'] ?? '—' }}</p></div>
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400">Recommendations recorded</p>
                <p class="text-sm text-gray-800 dark:text-gray-200">{{ collect($interviews['recommendations'])->map(fn ($count, $key) => str_replace('_', ' ', $key).': '.$count)->implode(' · ') ?: '—' }}</p>
            </div>
        </div>
        @include('filament.outcomes.metric-meta', ['metric' => $interviews, 'period' => $period])
    </x-filament::section>

    <x-filament::section :heading="$status['label']" description="Going forward from Phase 8.2 only. Observed active is not confirmed retention; observed inactive is not confirmed as an exit.">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <tr><th class="py-2 pr-3 font-medium">Checkpoint</th><th class="py-2 pr-3 text-right font-medium">Observed active</th><th class="py-2 pr-3 text-right font-medium">Observed inactive</th><th class="py-2 pr-3 text-right font-medium">Separated before</th><th class="py-2 pr-3 text-right font-medium">Not observed</th><th class="py-2 pr-3 text-right font-medium">Not yet due</th><th class="py-2 pr-3 text-right font-medium">Awaiting check</th><th class="py-2 pr-3 text-right font-medium">Active of observed</th><th class="py-2 font-medium">Sample</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($status['checkpoints'] as $row)
                        <tr class="text-gray-800 dark:text-gray-200">
                            <td class="py-2 pr-3">{{ $row['label'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['active'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['inactive'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['separated'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['not_observed'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['not_yet_due'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['awaiting_evaluation'] }}</td>
                            <td class="py-2 pr-3 text-right tabular-nums">{{ $row['active_rate'] !== null ? $row['active_rate'].'%' : '—' }}</td>
                            <td class="py-2">{{ $row['sample_size'] }} <x-filament::badge :color="$row['band']->color()" size="sm" class="ms-1 inline-flex">{{ $row['band']->label() }}</x-filament::badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @include('filament.outcomes.metric-meta', ['metric' => $status, 'period' => $period])
    </x-filament::section>

    <x-filament::section heading="Not observed — no source data" description="These post-hire outcomes are not recorded anywhere in the application, so they are never calculated or estimated.">
        <ul class="list-disc space-y-1 ps-5 text-sm text-gray-700 dark:text-gray-300">
            @foreach ($report['unavailable'] as $key => $description)
                <li wire:key="unavailable-{{ $key }}">{{ $description }}</li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-panels::page>
