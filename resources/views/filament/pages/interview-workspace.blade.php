<x-filament-panels::page>
    {{-- Today's Interview Panel --}}
    @php $summary = $this->getTodaySummary(); @endphp
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
        <x-recruitment.kpi-card label="Interviews Today" :value="$summary['today']" />
        <x-recruitment.kpi-card label="Confirmed" :value="$summary['confirmed']" color="success" tint />
        <button
            type="button"
            wire:click="setView('unconfirmed')"
            title="Show all upcoming interviews awaiting confirmation"
            @class([
                'rounded-lg text-center transition hover:opacity-80',
                'ring-2 ring-amber-400' => $activeView === 'unconfirmed',
            ])
        >
            <x-recruitment.kpi-card label="Pending Confirmation" :value="$summary['pending_confirmation']" color="warning" tint />
        </button>
        <x-recruitment.kpi-card label="No Show" :value="$summary['no_show']" color="danger" tint />
        <x-recruitment.kpi-card label="Feedback Pending" :value="$summary['feedback_pending']" color="danger" tint />
    </div>

    {{-- View switcher --}}
    <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
        <div class="flex flex-wrap gap-1 rounded-lg bg-gray-100 p-1 dark:bg-white/5">
            @foreach (['today' => 'Today', 'tomorrow' => 'Tomorrow', 'day' => 'Day', 'week' => 'Week', 'unconfirmed' => 'Unconfirmed', 'calendar' => 'Calendar'] as $key => $label)
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
                    @if ($key === 'unconfirmed' && $summary['pending_confirmation'] > 0)
                        <span class="ms-1 rounded-full bg-amber-500 px-1.5 text-[10px] font-semibold text-white">{{ $summary['pending_confirmation'] }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        <a href="{{ \App\Filament\Resources\Interviews\InterviewResource::getUrl('index') }}" class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
            Full list &rarr;
        </a>
    </div>

    {{-- Day / week navigation --}}
    @if ($activeView === 'day')
        <div class="mt-4 flex items-center justify-between gap-2">
            <div class="flex items-center gap-1">
                <x-filament::icon-button icon="heroicon-o-chevron-left" label="Previous day" wire:click="previousDay" />
                <x-filament::icon-button icon="heroicon-o-chevron-right" label="Next day" wire:click="nextDay" />
            </div>
            <p class="text-base font-semibold">{{ \Illuminate\Support\Carbon::parse($selectedDate)->format('l, d M Y') }}</p>
            <x-filament::button color="gray" outlined size="xs" wire:click="openDay('{{ now()->toDateString() }}')">
                Today
            </x-filament::button>
        </div>
    @elseif ($activeView === 'week')
        @php $weekDays = $this->getWeekDays(); @endphp
        <div class="mt-4 flex items-center justify-between gap-2">
            <div class="flex items-center gap-1">
                <x-filament::icon-button icon="heroicon-o-chevron-left" label="Previous week" wire:click="previousWeek" />
                <x-filament::icon-button icon="heroicon-o-chevron-right" label="Next week" wire:click="nextWeek" />
            </div>
            <p class="text-base font-semibold">{{ $weekDays[0]->format('d M') }} – {{ $weekDays[6]->format('d M Y') }}</p>
            <x-filament::button color="gray" outlined size="xs" wire:click="goToCurrentWeek">
                This week
            </x-filament::button>
        </div>
    @endif

    <div class="mt-4">
        @if ($activeView === 'calendar')
            @include('filament.pages.interview-workspace-calendar')
        @else
            @php $interviews = $this->getInterviewsForActiveView(); @endphp

            @if ($activeView === 'week')
                @php $interviewsByDay = $interviews->groupBy(fn ($interview) => $interview->scheduled_at->toDateString()); @endphp
                <div class="space-y-4">
                    @foreach ($weekDays as $weekDay)
                        @php $dayInterviews = $interviewsByDay->get($weekDay->toDateString(), collect()); @endphp
                        <div>
                            <div class="mb-2 flex items-center justify-between gap-2">
                                <button type="button" wire:click="openDay('{{ $weekDay->toDateString() }}')" @class([
                                    'text-sm font-semibold hover:underline',
                                    'text-primary-600 dark:text-primary-400' => $weekDay->isToday(),
                                    'text-gray-950 dark:text-white' => ! $weekDay->isToday(),
                                ])>
                                    {{ $weekDay->format('l, d M') }}
                                </button>
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $dayInterviews->count() }} {{ Str::plural('interview', $dayInterviews->count()) }}</span>
                            </div>

                            @if ($dayInterviews->isEmpty())
                                <p class="rounded-lg border border-dashed border-gray-200 px-3 py-2 text-xs text-gray-400 dark:border-white/10 dark:text-gray-500">No interviews</p>
                            @else
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    @foreach ($dayInterviews as $interview)
                                        @include('filament.pages.interview-card', ['interview' => $interview])
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @elseif ($interviews->isEmpty())
                <x-recruitment.empty-state
                    icon="heroicon-o-calendar-days"
                    heading="No interviews scheduled"
                    :description="match ($activeView) {
                        'today' => 'You have no interviews scheduled today.',
                        'tomorrow' => 'No interviews scheduled for tomorrow.',
                        'unconfirmed' => 'Every upcoming interview has been confirmed.',
                        default => 'No interviews scheduled for this day.',
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

    @include('filament.pages.interview-workspace-load')

    <x-filament-actions::modals />
</x-filament-panels::page>
