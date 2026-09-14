<x-filament-widgets::widget>
    <x-filament::section heading="Smart Recommendations" description="Database facts for the selected period, with an AI narration when AI is configured — the AI is never a source of numbers on its own" collapsible collapsed>
        <x-slot name="afterHeader">
            <x-filament::button size="sm" wire:click="generate" wire:loading.attr="disabled">
                Generate Insights
            </x-filament::button>
        </x-slot>

        <div wire:loading wire:target="generate" class="text-sm text-gray-500 dark:text-gray-400">
            Analyzing current recruitment data&hellip;
        </div>

        @if ($result === null)
            <p wire:loading.remove wire:target="generate" class="text-sm text-gray-500 dark:text-gray-400">
                Click "Generate Insights" to get a prioritized summary of what happened, what needs attention, and what to work on next.
            </p>
        @else
            @php $facts = $result['facts']; @endphp

            <div wire:loading.remove wire:target="generate" class="space-y-4">
                @if ($result['narrative'] !== null)
                    <div class="rounded-lg border border-gray-100 dark:border-white/5 p-3">
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">AI Recommendation</p>
                        <div class="prose prose-sm dark:prose-invert max-w-none">{!! nl2br(e($result['narrative'])) !!}</div>
                    </div>
                @elseif ($this->canNarrate() === false)
                    <p class="text-sm text-gray-500 dark:text-gray-400">AI narration is not available. Showing database facts only.</p>
                @endif

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Needs Attention</p>
                        <ul class="space-y-1 text-sm text-gray-600 dark:text-gray-300">
                            @foreach ($facts['alerts'] ?? [] as $alert)
                                <li>&bull; {{ $alert }}</li>
                            @endforeach
                            @foreach ($facts['pending_work'] ?? [] as $item)
                                <li>&bull; {{ $item['label'] }}: {{ $item['count'] }}</li>
                            @endforeach
                            @if (empty($facts['alerts']) && empty($facts['pending_work']))
                                <li>Nothing pending.</li>
                            @endif
                        </ul>
                    </div>

                    <div>
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Funnel ({{ $facts['period']['start'] ?? '' }} – {{ $facts['period']['end'] ?? '' }})</p>
                        <ul class="space-y-1 text-sm text-gray-600 dark:text-gray-300">
                            @foreach ($facts['funnel'] ?? [] as $row)
                                <li>&bull; {{ $row['stage'] }}: {{ $row['count'] }}@if ($row['conversion_from_sourced_percent'] !== null) ({{ $row['conversion_from_sourced_percent'] }}% of sourced)@endif</li>
                            @endforeach
                        </ul>
                    </div>

                    <div>
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Turn-up</p>
                        @php $turnUp = $facts['turn_up'] ?? null; @endphp
                        @if ($turnUp !== null)
                            <p class="text-sm text-gray-600 dark:text-gray-300">
                                {{ $turnUp['turnups'] }} of {{ $turnUp['lineups'] }} line-ups turned up{{ $turnUp['turnup_percent'] !== null ? ' ('.$turnUp['turnup_percent'].'%)' : '' }};
                                {{ $turnUp['no_shows'] }} no-show(s), {{ $turnUp['cancelled'] }} cancelled, {{ $turnUp['rescheduled'] }} rescheduled.
                            </p>
                        @endif
                    </div>

                    <div>
                        <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Positions at Risk</p>
                        <ul class="space-y-1 text-sm text-gray-600 dark:text-gray-300">
                            @forelse ($facts['positions_at_risk'] ?? [] as $position)
                                <li>&bull; {{ $position['requisition'] }} — {{ $position['remaining'] }} remaining, pipeline {{ $position['pipeline'] }}, open {{ $position['ageing_days'] }} days ({{ str_replace('_', ' ', $position['risk']) }})</li>
                            @empty
                                <li>No positions at risk.</li>
                            @endforelse
                        </ul>
                    </div>

                    @if (! empty($facts['recruiter_accountability']))
                        <div class="md:col-span-2">
                            <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-400">Recruiter Accountability</p>
                            <ul class="grid grid-cols-1 gap-1 text-sm text-gray-600 dark:text-gray-300 sm:grid-cols-2">
                                @foreach ($facts['recruiter_accountability'] as $row)
                                    <li>&bull; {{ $row['metric'] }}: {{ $row['actual'] }} / {{ $row['target'] ?? '—' }}@if ($row['achievement_percent'] !== null) ({{ $row['achievement_percent'] }}%)@endif</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
