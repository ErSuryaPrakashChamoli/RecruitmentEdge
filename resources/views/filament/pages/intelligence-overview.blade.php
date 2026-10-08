<x-filament-panels::page>
    @php
        $requisitions = $this->requisitions();
        $distribution = $this->distribution($requisitions);
        $risks = $this->topRisks();
    @endphp

    <p class="text-sm text-gray-600 dark:text-gray-300">
        Every Hiring Health status and risk below is calculated from recorded data by named rules and can be traced to its evidence. AI is
        <strong>{{ $this->aiConfigured() ? 'available' : 'not configured' }}</strong>
        — {{ $this->aiConfigured() ? 'it is only used when someone asks for suggestions or summaries.' : 'everything here works without it.' }}
    </p>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @foreach ([\App\Enums\HealthStatus::Critical, \App\Enums\HealthStatus::AtRisk, \App\Enums\HealthStatus::Watch, \App\Enums\HealthStatus::Healthy, \App\Enums\HealthStatus::InsufficientData] as $status)
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $status->label() }}</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $distribution[$status->value] }}</p>
            </div>
        @endforeach
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
            <p class="text-xs text-gray-500 dark:text-gray-400">Not computed yet</p>
            <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $distribution['not_computed'] }}</p>
        </div>
    </div>

    <x-filament::section heading="Open requisitions" description="Worst health first. Open a requisition for its Role DNA, Talent Signals, Rediscovery and evidence.">
        @if ($requisitions->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-300">No open requisitions in your scope.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <tr><th class="py-2 pr-3 font-medium">Requisition</th><th class="py-2 pr-3 font-medium">Hiring Health</th><th class="py-2 pr-3 font-medium">Breaches</th><th class="py-2 pr-3 font-medium">Open risks</th><th class="py-2 pr-3 font-medium">Role DNA</th><th class="py-2 font-medium">Computed</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($requisitions as $requisition)
                            <tr class="text-gray-800 dark:text-gray-200" wire:key="req-{{ $requisition->id }}">
                                <td class="py-2 pr-3">
                                    <a href="{{ $this->intelligenceUrl($requisition) }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $requisition->code }}</a>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $requisition->designation?->name }}</span>
                                </td>
                                <td class="py-2 pr-3">
                                    @if ($requisition->intel_health)
                                        <x-filament::badge :color="$requisition->intel_health->status->color()" size="sm">{{ $requisition->intel_health->status->label() }}</x-filament::badge>
                                    @else
                                        <span class="text-xs text-gray-500 dark:text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-3 tabular-nums">{{ $requisition->intel_health?->breach_count ?? '—' }}</td>
                                <td class="py-2 pr-3 tabular-nums">{{ $requisition->intel_risks }}</td>
                                <td class="py-2 pr-3">
                                    @if ($requisition->intel_dna)
                                        v{{ $requisition->intel_dna->current_version }} · {{ $requisition->intel_dna->status->label() }}
                                    @else
                                        <span class="text-xs text-gray-500 dark:text-gray-400">not built</span>
                                    @endif
                                </td>
                                <td class="py-2 text-xs text-gray-500 dark:text-gray-400">
                                    @if ($requisition->intel_health)
                                        {{ $requisition->intel_health->computed_at->diffForHumans() }}@unless ($this->isFresh($requisition->intel_health)) · <span class="text-warning-600 dark:text-warning-400">stale</span>@endunless
                                    @else
                                        never
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section heading="Highest-severity risks" :description="$risks->isEmpty() ? 'No high or critical risks open.' : 'Warnings with evidence — never decisions.'">
        @if ($risks->isNotEmpty())
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($risks as $risk)
                    <li class="py-2.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-filament::badge :color="$risk->severity->color()" size="sm">{{ $risk->severity->label() }}</x-filament::badge>
                            <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $risk->title }}</span>
                        </div>
                        <p class="mt-0.5 text-sm text-gray-700 dark:text-gray-300">→ {{ $risk->recommended_action }}</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-panels::page>
