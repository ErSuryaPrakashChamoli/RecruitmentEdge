@php
    $staleDays = $application->last_activity_at ? now()->diffInDays($application->last_activity_at) : null;
    $staleness = match (true) {
        $staleDays === null => null,
        $staleDays > 10 => 'bg-rose-500',
        $staleDays > 5 => 'bg-amber-500',
        default => null,
    };
@endphp

<div class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-gray-900">
    <div class="flex items-start gap-2">
        <x-recruitment.avatar-initials :name="$application->candidate->full_name" />
        <div class="min-w-0 flex-1">
            <div class="flex items-center justify-between gap-2">
                <a
                    href="{{ \App\Filament\Resources\CandidateApplications\CandidateApplicationResource::getUrl('view', ['record' => $application]) }}"
                    class="truncate text-sm font-medium text-gray-950 hover:underline dark:text-white"
                >
                    {{ $application->candidate->full_name }}
                </a>
                @if ($staleness)
                    <span class="h-2 w-2 shrink-0 rounded-full {{ $staleness }}" title="No activity in {{ $staleDays }} days"></span>
                @endif
            </div>
            <p class="truncate text-xs text-gray-400 dark:text-gray-500">{{ $application->application_code }}</p>
            <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">
                {{ $application->requisition->code }} &middot; {{ $application->recruiter->fullName() }}
            </p>
        </div>
    </div>

    <div class="mt-2 flex items-center justify-between">
        <x-filament::badge :color="$application->priority->color()" size="xs">
            {{ $application->priority->label() }}
        </x-filament::badge>
        @if ($application->stage_age_days !== null)
            <span class="text-xs text-gray-400 dark:text-gray-500">{{ $application->stage_age_days }}d in stage</span>
        @endif
    </div>

    @if ($application->next_followup)
        <p class="mt-1.5 flex items-center gap-1 text-xs text-amber-600 dark:text-amber-400">
            <x-filament::icon icon="heroicon-o-bell-alert" class="h-3 w-3" />
            Follow up {{ \Illuminate\Support\Carbon::parse($application->next_followup)->diffForHumans() }}
        </p>
    @endif

    <button
        type="button"
        wire:click="mountAction('moveApplication', { applicationId: {{ $application->id }} })"
        wire:sort:ignore
        class="mt-2 w-full rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-300 dark:hover:bg-white/20"
    >
        Move to…
    </button>
</div>
