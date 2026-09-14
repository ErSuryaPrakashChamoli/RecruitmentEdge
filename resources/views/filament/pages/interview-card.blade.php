@php
    $candidate = $interview->candidateApplication->candidate;
    $meetingUrl = filled($interview->meeting_link)
        ? $interview->meeting_link
        : ($interview->mode->value === 'video_call' && filled($interview->location) && str_starts_with($interview->location, 'http') ? $interview->location : null);
    $feedbackPending = $interview->status->value === 'completed' && $interview->result === null;
    $isOpen = ! $interview->status->isTerminal();
@endphp

<div class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm dark:border-white/10 dark:bg-gray-900">
    <div class="flex items-start gap-2">
        <x-recruitment.avatar-initials :name="$candidate->full_name" />
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $candidate->full_name }}</p>
            <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                {{ $interview->candidateApplication->requisition?->designation?->name ?? 'Unspecified role' }} &middot; Round {{ $interview->round_number }}
            </p>
            <p class="mt-0.5 truncate text-xs text-gray-400 dark:text-gray-500">
                Interviewer: {{ $interview->interviewer?->fullName() ?? '—' }}
            </p>
        </div>
        <x-filament::badge :color="$interview->status->color()" size="xs">{{ $interview->status->label() }}</x-filament::badge>
    </div>

    <div class="mt-2 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
        <span>{{ $interview->scheduled_at->format('D, d M · h:i A') }}</span>
        <span>{{ $interview->mode->label() }}</span>
    </div>

    @if (filled($interview->location))
        <p class="mt-1 truncate text-xs text-gray-400 dark:text-gray-500">{{ $interview->location }}</p>
    @endif

    @if ($feedbackPending)
        <p class="mt-1.5 flex items-center gap-1 text-xs text-rose-600 dark:text-rose-400">
            <x-filament::icon icon="heroicon-o-exclamation-circle" class="h-3 w-3" />
            Feedback pending for {{ $interview->scheduled_at->diffForHumans(null, true) }}
        </p>
    @endif

    <div class="mt-2 flex flex-wrap gap-1">
        <a href="{{ $this->interviewEditUrl($interview) }}" class="rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-300 dark:hover:bg-white/20">
            View
        </a>
        @if ($meetingUrl)
            <a href="{{ $meetingUrl }}" target="_blank" rel="noopener" class="rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-600 hover:bg-blue-100 dark:bg-blue-500/10 dark:text-blue-400">
                Join Meeting
            </a>
        @endif
        @if ($interview->status->awaitsConfirmation())
            <button type="button" wire:click="mountAction('confirm', { record: {{ $interview->id }} })" class="rounded-md bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-600 hover:bg-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-400">
                Confirm
            </button>
        @endif
        @if ($isOpen)
            <button type="button" wire:click="mountAction('reschedule', { record: {{ $interview->id }} })" class="rounded-md bg-amber-50 px-2 py-1 text-xs font-medium text-amber-600 hover:bg-amber-100 dark:bg-amber-500/10 dark:text-amber-400">
                Reschedule
            </button>
        @endif
        @if ($isOpen && $interview->status->value !== 'hold')
            <button type="button" wire:click="mountAction('hold', { record: {{ $interview->id }} })" class="rounded-md bg-amber-50 px-2 py-1 text-xs font-medium text-amber-600 hover:bg-amber-100 dark:bg-amber-500/10 dark:text-amber-400">
                Hold
            </button>
        @endif
        @if ($isOpen || $feedbackPending)
            <button type="button" wire:click="mountAction('addFeedback', { interviewId: {{ $interview->id }} })" @class([
                'rounded-md px-2 py-1 text-xs font-medium',
                'bg-rose-50 text-rose-600 hover:bg-rose-100 dark:bg-rose-500/10 dark:text-rose-400' => $feedbackPending,
                'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-300 dark:hover:bg-white/20' => ! $feedbackPending,
            ])>
                Add Feedback
            </button>
        @endif
        @if ($isOpen)
            <button type="button" wire:click="mountAction('complete', { record: {{ $interview->id }} })" class="rounded-md bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-600 hover:bg-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-400">
                Complete
            </button>
            <button type="button" wire:click="mountAction('noShow', { record: {{ $interview->id }} })" class="rounded-md bg-rose-50 px-2 py-1 text-xs font-medium text-rose-600 hover:bg-rose-100 dark:bg-rose-500/10 dark:text-rose-400">
                No Show
            </button>
        @endif
    </div>
</div>
