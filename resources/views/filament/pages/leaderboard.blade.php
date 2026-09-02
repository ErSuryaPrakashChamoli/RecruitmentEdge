<x-filament-panels::page>
    @php $summary = $this->getSummary(); @endphp

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-recruitment.kpi-stat
            label="Recruiters Ranked"
            :value="$summary['total']"
            icon="heroicon-o-users"
            color="info"
            :trend-label="$summary['scored'].' with a score this period'"
        />
        <x-recruitment.kpi-stat
            label="Team Average Score"
            :value="$summary['average'] !== null ? number_format($summary['average'], 1) : '—'"
            icon="heroicon-o-chart-bar"
            color="success"
        />
        <x-recruitment.kpi-stat
            label="Top Performer"
            :value="$summary['topName'] ?? '—'"
            icon="heroicon-o-trophy"
            color="warning"
            :trend-label="$summary['topScore'] !== null ? 'Score '.$summary['topScore'] : 'No score yet this period'"
        />
    </div>

    {{ $this->content }}
</x-filament-panels::page>
