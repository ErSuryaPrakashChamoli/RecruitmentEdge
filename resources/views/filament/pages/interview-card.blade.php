@php
    $candidate = $interview->candidateApplication->candidate;
    $isVideoWithLink = $interview->mode->value === 'video_call' && filled($interview->location) && str_starts_with($interview->location, 'http');
    $feedbackPending = $interview->status->value === 'completed' && $interview->result === null;
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
        <span>{{ $interview->scheduled_at->format('h:i A') }}</span>
        <span>{{ $interview->mode->label() }}</span>
    </div>

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
        @if ($isVideoWithLink)
            <a href="{{ $interview->location }}" target="_blank" class="rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-600 hover:bg-blue-100 dark:bg-blue-500/10 dark:text-blue-400">
                Join Meeting
            </a>
        @endif
        @if ($interview->status->value === 'scheduled')
            <button type="button" wire:click="mountAction('confirm', { record: {{ $interview->id }} })" class="rounded-md bg-emerald-50 px-2 py-1 text-xs font-medium text-emerald-600 hover:bg-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-400">
                Confirm
            </button>
        @endif
        @if (! $interview->status->isTerminal())
            <button type="button" wire:click="mountAction('reschedule', { record: {{ $interview->id }} })" class="rounded-md bg-amber-50 px-2 py-1 text-xs font-medium text-amber-600 hover:bg-amber-100 dark:bg-amber-500/10 dark:text-amber-400">
                Reschedule
            </button>
        @endif
        @if ($feedbackPending)
            <button type="button" wire:click="mountAction('addFeedback', { interviewId: {{ $interview->id }} })" class="rounded-md bg-rose-50 px-2 py-1 text-xs font-medium text-rose-600 hover:bg-rose-100 dark:bg-rose-500/10 dark:text-rose-400">
                Add Feedback
            </button>
        @endif
    </div>
</div>
