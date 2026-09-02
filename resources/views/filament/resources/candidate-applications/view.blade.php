<x-filament-panels::page>
    @php
        $record = $this->getRecord();
        $candidate = $record->candidate;
    @endphp

    {{-- Header --}}
    <div class="rounded-xl border border-gray-100 bg-white p-5 shadow-sm dark:border-white/5 dark:bg-gray-900">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-center gap-4">
                <x-recruitment.avatar-initials :name="$candidate->full_name" size="md" />
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">{{ $candidate->full_name }}</h2>
                        <x-filament::badge :color="$record->current_stage->color()">{{ $record->current_stage->label() }}</x-filament::badge>
                        <x-filament::badge :color="$record->priority->color()" size="xs">{{ $record->priority->label() }}</x-filament::badge>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $record->application_code }} &middot; {{ $record->requisition->designation?->name ?? $record->requisition->code }}
                    </p>
                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                        Recruiter: {{ $record->recruiter->fullName() }} &middot; Source: {{ $candidate->source?->name ?? '—' }}
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                @if ($candidate->mobile)
                    <x-filament::icon-button icon="heroicon-o-phone" label="Call" tooltip="Call" tag="a" :href="'tel:'.$candidate->mobile" />
                    <x-filament::icon-button
                        icon="heroicon-o-chat-bubble-left-right"
                        label="WhatsApp"
                        tooltip="WhatsApp"
                        tag="a"
                        :href="'https://wa.me/'.preg_replace('/\D/', '', $candidate->mobile)"
                        target="_blank"
                    />
                @endif
                @if ($candidate->email)
                    <x-filament::icon-button icon="heroicon-o-envelope" label="Email" tooltip="Email" tag="a" :href="'mailto:'.$candidate->email" />
                @endif

                @if ($this->scheduleInterviewAction()->isVisible())
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-calendar-days" wire:click="mountAction('scheduleInterview')">
                        Schedule Interview
                    </x-filament::button>
                @endif
                @if ($this->addFollowupAction()->isVisible())
                    <x-filament::button size="sm" color="gray" icon="heroicon-o-bell-alert" wire:click="mountAction('addFollowup')">
                        Add Follow-up
                    </x-filament::button>
                @endif
            </div>
        </div>

        {{-- Journey --}}
        <div class="mt-5 overflow-x-auto pb-1">
            <div class="flex min-w-max items-center">
                @foreach ($this->getJourneySteps() as $step)
                    <div class="flex items-center">
                        <div class="flex flex-col items-center gap-1">
                            <span @class([
                                'flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold',
                                'bg-emerald-500 text-white' => $step['state'] === 'completed',
                                'bg-blue-600 text-white ring-4 ring-blue-100 dark:ring-blue-500/20' => $step['state'] === 'current',
                                'bg-rose-500 text-white' => $step['state'] === 'terminal',
                                'bg-gray-200 text-gray-500 dark:bg-white/10 dark:text-gray-400' => $step['state'] === 'upcoming',
                            ])>
                                @if ($step['state'] === 'completed')
                                    <x-filament::icon icon="heroicon-o-check" class="h-3.5 w-3.5" />
                                @else
                                    &nbsp;
                                @endif
                            </span>
                            <span @class([
                                'w-16 shrink-0 text-center text-[10px] leading-tight',
                                'text-gray-700 dark:text-gray-300' => in_array($step['state'], ['completed', 'current', 'terminal']),
                                'text-gray-400 dark:text-gray-600' => $step['state'] === 'upcoming',
                                'font-semibold' => $step['state'] === 'current',
                            ])>
                                {{ $step['label'] }}
                            </span>
                        </div>

                        @if (! $loop->last)
                            <span class="mx-1 h-px w-6 shrink-0 bg-gray-200 dark:bg-white/10"></span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Next action --}}
        @if ($record->next_followup_at)
            <div class="mt-4 flex items-center justify-between gap-3 rounded-lg bg-amber-50 px-3 py-2 dark:bg-amber-500/10">
                <p class="text-sm text-amber-800 dark:text-amber-300">
                    <span class="font-semibold">Next action:</span> Follow up {{ $record->next_followup_at->diffForHumans() }}
                    ({{ $record->next_followup_at->format('d M Y, h:i A') }})
                </p>
                <x-filament::button size="xs" color="warning" outlined wire:click="mountAction('updateNextFollowup')">
                    Update
                </x-filament::button>
            </div>
        @endif
    </div>

    {{-- Timeline --}}
    <x-filament::section heading="Recruitment Timeline" description="Every event on this application, most recent first" class="mt-6">
        @php $timeline = $this->getTimeline(); @endphp

        @if ($timeline->isEmpty())
            <x-recruitment.empty-state
                icon="heroicon-o-clock"
                heading="No activity yet"
                description="Events will appear here as the candidate moves through the pipeline."
            />
        @else
            <div class="space-y-0">
                @foreach ($timeline as $event)
                    <div class="flex gap-3 pb-4 last:pb-0">
                        <div class="flex flex-col items-center">
                            <span @class([
                                'flex h-7 w-7 shrink-0 items-center justify-center rounded-full',
                                'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' => $event['color'] === 'success',
                                'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400' => $event['color'] === 'warning',
                                'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400' => $event['color'] === 'danger',
                                'bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400' => $event['color'] === 'info',
                                'bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400' => $event['color'] === 'gray',
                            ])>
                                <x-filament::icon :icon="$event['icon']" class="h-3.5 w-3.5" />
                            </span>
                            @if (! $loop->last)
                                <span class="mt-1 w-px flex-1 bg-gray-100 dark:bg-white/5"></span>
                            @endif
                        </div>

                        <div class="min-w-0 flex-1 pb-1">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                                <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $event['title'] }}</p>
                                <p class="text-xs text-gray-400 dark:text-gray-500">{{ $event['at']->format('d M Y, h:i A') }}</p>
                            </div>
                            @if ($event['subtitle'])
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $event['subtitle'] }}</p>
                            @endif
                            @if ($event['meta'])
                                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $event['meta'] }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <div class="mt-6">
        {{ $this->content }}
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
