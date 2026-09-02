<x-filament-panels::page>
    {{-- Today's Interview Panel --}}
    @php $summary = $this->getTodaySummary(); @endphp
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
        <x-recruitment.kpi-card label="Interviews Today" :value="$summary['today']" />
        <x-recruitment.kpi-card label="Confirmed" :value="$summary['confirmed']" color="success" tint />
        <x-recruitment.kpi-card label="Pending Confirmation" :value="$summary['pending_confirmation']" color="warning" tint />
        <x-recruitment.kpi-card label="No Show" :value="$summary['no_show']" color="danger" tint />
        <x-recruitment.kpi-card label="Feedback Pending" :value="$summary['feedback_pending']" color="danger" tint />
    </div>

    {{-- View switcher --}}
    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <div class="flex gap-1 rounded-lg bg-gray-100 p-1 dark:bg-white/5">
            @foreach (['today' => 'Today', 'tomorrow' => 'Tomorrow', 'week' => 'This Week', 'calendar' => 'Calendar'] as $key => $label)
                <button
                    type="button"
                    wire:click="setView('{{ $key }}')"
                    @class([
                        'rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
                        'bg-white text-gray-950 shadow-sm dark:bg-gray-800 dark:text-white' => $activeView === $key,
                        'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $activeView !== $key,
                    ])
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <a href="{{ \App\Filament\Resources\Interviews\InterviewResource::getUrl('index') }}" class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
            Full list &rarr;
        </a>
    </div>

    <div class="mt-4">
        @if ($activeView === 'calendar')
            @include('filament.pages.interview-workspace-calendar')
        @else
            @php $interviews = $this->getInterviewsForActiveView(); @endphp

            @if ($interviews->isEmpty())
                <x-recruitment.empty-state
                    icon="heroicon-o-calendar-days"
                    heading="No interviews scheduled"
                    :description="match ($activeView) {
                        'today' => 'You have no interviews scheduled today.',
                        'tomorrow' => 'No interviews scheduled for tomorrow.',
                        default => 'No interviews scheduled this week.',
                    }"
                />
            @else
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($interviews as $interview)
                        @include('filament.pages.interview-card', ['interview' => $interview])
                    @endforeach
                </div>
            @endif
        @endif
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
