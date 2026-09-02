<x-filament-widgets::widget>
    <x-filament::section heading="Follow-up Calendar" collapsible collapsed>
        <x-slot name="afterHeader">
            <div class="ms-auto flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                <span class="flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-blue-500"></span>
                    Interview
                </span>
                <span class="flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                    Joining
                </span>
            </div>
        </x-slot>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
            <div class="lg:col-span-3">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <div class="flex items-center gap-1">
                        <x-filament::icon-button
                            icon="heroicon-o-chevron-left"
                            label="Previous month"
                            wire:click="previousMonth"
                        />
                        <x-filament::icon-button
                            icon="heroicon-o-chevron-right"
                            label="Next month"
                            wire:click="nextMonth"
                        />
                    </div>
                    <p class="text-base font-semibold">
                        {{ \Illuminate\Support\Carbon::parse($this->month)->format('F Y') }}
                    </p>
                    <x-filament::button color="gray" outlined size="xs" wire:click="goToToday">
                        Today
                    </x-filament::button>
                </div>

                @php
                    $interviewCounts = $this->getInterviewCountsInMonth();
                    $joiningCounts = $this->getJoiningCountsInMonth();
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
                                        $isSelected = $dateStr === $this->selectedDate;
                                        $isToday = $dateStr === $today;
                                        $interviewCount = $interviewCounts->get($dateStr, 0);
                                        $joiningCount = $joiningCounts->get($dateStr, 0);
                                        $hasEvents = $interviewCount > 0 || $joiningCount > 0;
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

                                        <div class="flex flex-col gap-1">
                                            @if ($interviewCount > 0)
                                                <span class="inline-flex w-fit items-center gap-1 rounded-full bg-blue-500 px-2 py-0.5 text-[10px] font-semibold text-white shadow-sm">
                                                    <x-filament::icon icon="heroicon-m-video-camera" class="h-2.5 w-2.5" />
                                                    {{ $interviewCount }} {{ Str::plural('Interview', $interviewCount) }}
                                                </span>
                                            @endif
                                            @if ($joiningCount > 0)
                                                <span class="inline-flex w-fit items-center gap-1 rounded-full bg-emerald-500 px-2 py-0.5 text-[10px] font-semibold text-white shadow-sm">
                                                    <x-filament::icon icon="heroicon-m-flag" class="h-2.5 w-2.5" />
                                                    {{ $joiningCount }} {{ Str::plural('Joining', $joiningCount) }}
                                                </span>
                                            @endif
                                        </div>
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
                        Follow-ups on {{ \Illuminate\Support\Carbon::parse($this->selectedDate)->format('d M Y') }}
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Click any date on the calendar to see that day's interviews and joinings here.
                    </p>

                    @php
                        $interviews = $this->getInterviewsForSelectedDate();
                        $joinings = $this->getJoiningsForSelectedDate();
                    @endphp

                    <div class="mt-4">
                        <div class="mb-2 flex items-center justify-between">
                            <h4 class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                                Interviews
                            </h4>
                            <x-filament::badge color="info" size="xs">{{ $interviews->count() }}</x-filament::badge>
                        </div>

                        @if ($interviews->isEmpty())
                            <x-recruitment.empty-state compact heading="No interviews scheduled for this day" />
                        @else
                            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="bg-gray-50 text-left text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                                            <th class="px-3 py-2 font-medium">Candidate</th>
                                            <th class="px-3 py-2 font-medium">Position</th>
                                            <th class="px-3 py-2 font-medium">Time</th>
                                            <th class="px-3 py-2 font-medium">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($interviews as $interview)
                                            <tr class="border-t border-gray-100 dark:border-white/5">
                                                <td class="px-3 py-2 font-medium">
                                                    {{ $interview->candidateApplication->candidate->full_name }}
                                                </td>
                                                <td class="px-3 py-2 text-gray-500 dark:text-gray-400">
                                                    {{ $interview->candidateApplication->requisition->designation?->name ?? 'Unspecified role' }}
                                                </td>
                                                <td class="px-3 py-2 whitespace-nowrap">
                                                    {{ $interview->scheduled_at->format('h:i A') }}
                                                </td>
                                                <td class="px-3 py-2">
                                                    <x-filament::badge size="xs">{{ $interview->status->label() }}</x-filament::badge>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>

                    <div class="mt-4">
                        <div class="mb-2 flex items-center justify-between">
                            <h4 class="text-xs font-semibold uppercase text-gray-500 dark:text-gray-400">
                                Joinings
                            </h4>
                            <x-filament::badge color="success" size="xs">{{ $joinings->count() }}</x-filament::badge>
                        </div>

                        @if ($joinings->isEmpty())
                            <x-recruitment.empty-state compact heading="No joinings scheduled for this day" />
                        @else
                            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="bg-gray-50 text-left text-xs text-gray-500 dark:bg-white/5 dark:text-gray-400">
                                            <th class="px-3 py-2 font-medium">Candidate</th>
                                            <th class="px-3 py-2 font-medium">Position</th>
                                            <th class="px-3 py-2 font-medium">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($joinings as $joining)
                                            <tr class="border-t border-gray-100 dark:border-white/5">
                                                <td class="px-3 py-2 font-medium">
                                                    {{ $joining->candidateApplication->candidate->full_name }}
                                                </td>
                                                <td class="px-3 py-2 text-gray-500 dark:text-gray-400">
                                                    {{ $joining->candidateApplication->requisition->designation?->name ?? 'Unspecified role' }}
                                                </td>
                                                <td class="px-3 py-2">
                                                    <x-filament::badge size="xs">{{ $joining->status->label() }}</x-filament::badge>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
