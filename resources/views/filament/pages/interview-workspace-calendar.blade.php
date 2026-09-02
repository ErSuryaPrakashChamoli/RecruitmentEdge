<div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
    <div class="lg:col-span-3">
        <div class="mb-3 flex items-center justify-between gap-2">
            <div class="flex items-center gap-1">
                <x-filament::icon-button icon="heroicon-o-chevron-left" label="Previous month" wire:click="previousMonth" />
                <x-filament::icon-button icon="heroicon-o-chevron-right" label="Next month" wire:click="nextMonth" />
            </div>
            <p class="text-base font-semibold">
                {{ \Illuminate\Support\Carbon::parse($month)->format('F Y') }}
            </p>
            <x-filament::button color="gray" outlined size="xs" wire:click="goToToday">
                Today
            </x-filament::button>
        </div>

        @php
            $interviewCounts = $this->getInterviewCountsInMonth();
            $today = now()->toDateString();
        @endphp

        <div class="overflow-hidden rounded-xl border border-gray-200 shadow-sm dark:border-white/10">
            <div class="grid grid-cols-7 bg-gray-900 dark:bg-black/40">
                @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dayLabel)
                    <div class="py-2 text-center text-xs font-semibold tracking-wide text-white/80 uppercase">
                        {{ $dayLabel }}
                    </div>
                @endforeach
            </div>

            <div class="grid grid-cols-7 gap-px bg-gray-100 dark:bg-white/10">
                @foreach ($this->getCalendarWeeks() as $week)
                    @foreach ($week as $day)
                        @if ($day === null)
                            <div class="min-h-16 bg-gray-50/60 dark:bg-white/5"></div>
                        @else
                            @php
                                $dateStr = $day->toDateString();
                                $isSelected = $dateStr === $selectedDate;
                                $isToday = $dateStr === $today;
                                $interviewCount = $interviewCounts->get($dateStr, 0);
                                $hasEvents = $interviewCount > 0;
                            @endphp
                            <button
                                type="button"
                                wire:click="selectDate('{{ $dateStr }}')"
                                @class([
                                    'min-h-16 space-y-1 p-1.5 text-left transition-colors',
                                    'bg-primary-50 dark:bg-primary-500/10' => $hasEvents && ! $isSelected,
                                    'bg-white dark:bg-gray-900' => ! $hasEvents && ! $isSelected,
                                    'ring-2 ring-inset ring-primary-500 bg-white dark:bg-gray-900' => $isSelected,
                                    'hover:bg-primary-100 dark:hover:bg-primary-500/20' => $hasEvents && ! $isSelected,
                                    'hover:bg-gray-50 dark:hover:bg-white/5' => ! $hasEvents && ! $isSelected,
                                ])
                            >
                                <span @class([
                                    'flex h-5 w-5 items-center justify-center rounded-full text-xs',
                                    'bg-primary-500 font-semibold text-white' => $isToday,
                                    'font-medium text-gray-700 dark:text-gray-300' => ! $isToday,
                                ])>
                                    {{ $day->day }}
                                </span>

                                @if ($interviewCount > 0)
                                    <span class="inline-flex w-fit items-center gap-1 rounded-full bg-blue-500 px-2 py-0.5 text-[10px] font-semibold text-white shadow-sm">
                                        <x-filament::icon icon="heroicon-m-video-camera" class="h-2.5 w-2.5" />
                                        {{ $interviewCount }} {{ Str::plural('Interview', $interviewCount) }}
                                    </span>
                                @endif
                            </button>
                        @endif
                    @endforeach
                @endforeach
            </div>
        </div>
    </div>

    <div class="lg:col-span-2">
        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <p class="text-sm font-semibold">
                Interviews on {{ \Illuminate\Support\Carbon::parse($selectedDate)->format('d M Y') }}
            </p>

            @php $interviews = $this->getInterviewsForSelectedDate(); @endphp

            <div class="mt-4 space-y-3">
                @if ($interviews->isEmpty())
                    <x-recruitment.empty-state compact heading="No interviews scheduled for this day" />
                @else
                    @foreach ($interviews as $interview)
                        @include('filament.pages.interview-card', ['interview' => $interview])
                    @endforeach
                @endif
            </div>
        </div>
    </div>
</div>
