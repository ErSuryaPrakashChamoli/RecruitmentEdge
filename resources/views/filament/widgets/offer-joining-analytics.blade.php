<x-filament-widgets::widget>
    <x-filament::section heading="Offer & Joining" collapsible collapsed>
        @php
            $offers = $this->getOffers();
            $joining = $this->getJoining();
            $risks = $this->getRisks();
            $percent = fn ($value) => $value !== null ? $value.'%' : '—';
        @endphp

        <div class="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
            <x-recruitment.kpi-card label="Offers" :value="$offers['generated']" />
            <x-recruitment.kpi-card label="Released" :value="$offers['released']" />
            <x-recruitment.metric-card :result="$this->getMetric('offer.acceptance_rate')" label="Offer Acceptance" />
            <x-recruitment.metric-card :result="$this->getMetric('offer.decided_acceptance_rate')" label="Accepted vs Declined" />
            @if ($this->canSeeCompensation())
                <x-recruitment.kpi-card label="Avg Offered CTC" :value="$offers['average_offered_ctc'] !== null ? '₹'.number_format($offers['average_offered_ctc']) : '—'" :title="'Withheld below '.config('metrics.compensation_min_group').' offers'" />
            @endif
            <x-recruitment.kpi-card label="Avg Days Selection → Offer" :value="$offers['average_days_selection_to_offer'] ?? '—'" />
            <x-recruitment.metric-card :result="$this->getMetric('joining.join_rate')" label="Join Rate" />
            <x-recruitment.metric-card :result="$this->getMetric('joining.offer_to_join')" label="Offer Accepted → Joined" />
        </div>

        <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
            Joined {{ $joining['joined'] }} &middot; no-show {{ $joining['no_show'] }} &middot; dropout {{ $joining['dropout'] }} &middot; offers awaiting decision {{ $offers['pending'] }} &middot; rejected {{ $offers['rejected'] }} &middot; expired {{ $offers['expired'] }} &middot; withdrawn {{ $offers['withdrawn'] }}
            &mdash; Joining today {{ $joining['today'] }}, tomorrow {{ $joining['tomorrow'] }}, next 7 days {{ $joining['next_7_days'] }}
        </p>

        @if ($risks->isNotEmpty())
            <div class="space-y-1">
                @foreach ($risks as $row)
                    <div class="flex items-center justify-between text-sm">
                        <span>{{ $row['risk'] === 'red' ? '🔴' : '🟡' }} {{ $row['joining']->candidateApplication?->candidate?->full_name ?? 'Unknown candidate' }}</span>
                        <span class="text-gray-500 dark:text-gray-400">{{ $row['joining']->expected_doj?->toFormattedDateString() }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <x-recruitment.empty-state
                icon="heroicon-o-check-circle"
                heading="No joinings at risk"
                description="All upcoming joinings are on track."
            />
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
