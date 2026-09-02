<x-filament-widgets::widget>
    <x-filament::section heading="Offer & Joining" collapsible collapsed>
        @php $offers = $this->getOffers(); $joining = $this->getJoining(); $risks = $this->getRisks(); @endphp

        <div class="mb-3 grid grid-cols-4 gap-2">
            <x-recruitment.kpi-card label="Offers" :value="$offers['generated']" />
            <x-recruitment.kpi-card label="Accept Rate" :value="$offers['acceptance_percent'] !== null ? $offers['acceptance_percent'].'%' : '—'" />
            <x-recruitment.kpi-card label="Joined" :value="$joining['joined']" />
            <x-recruitment.kpi-card label="Selection→Joining" :value="$joining['joining_percent'] !== null ? $joining['joining_percent'].'%' : '—'" />
        </div>

        <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">
            Offers pending {{ $offers['pending'] }} &middot; rejected {{ $offers['rejected'] }} &middot; expired {{ $offers['expired'] }}
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
