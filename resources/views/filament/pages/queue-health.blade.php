@php($health = $this->getHealth())
<x-filament-panels::page>
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Checked {{ \Illuminate\Support\Carbon::parse($health['checked_at'])->diffForHumans() }}.
        Platform administrators are alerted automatically (once an hour per problem) when anything below needs attention.
    </p>

    <x-filament::section heading="Needs attention">
        @forelse ($health['problems'] as $problem)
            <p class="py-1 text-sm text-danger-600 dark:text-danger-400">{{ $problem }}</p>
        @empty
            <p class="text-sm text-success-600 dark:text-success-400">Nothing — queues are moving and the scheduler is running.</p>
        @endforelse
    </x-filament::section>

    <x-filament::section heading="Queues">
        <div class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($health['queues'] as $queue)
                <div class="flex flex-wrap items-center justify-between gap-3 py-2">
                    <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $queue['queue'] }}</p>
                    <div class="flex items-center gap-2">
                        <x-filament::badge color="gray">{{ $queue['depth'] }} waiting</x-filament::badge>
                        <x-filament::badge :color="($queue['oldest_minutes'] ?? 0) > \App\Services\QueueHealthService::OLDEST_JOB_ALERT_MINUTES ? 'danger' : 'gray'">
                            {{ $queue['oldest_minutes'] === null ? 'empty' : 'oldest '.$queue['oldest_minutes'].' min' }}
                        </x-filament::badge>
                    </div>
                </div>
            @endforeach
        </div>
        @if ($health['paused_providers'] !== [])
            <p class="mt-3 text-sm text-warning-600 dark:text-warning-400">Paused after repeated failures: {{ implode(', ', $health['paused_providers']) }}. Their messages stay queued and are sent when the provider recovers.</p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Stuck work (over {{ \App\Services\QueueHealthService::STUCK_ALERT_MINUTES }} minutes)">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach ($health['stuck'] as $kind => $count)
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Str::of($kind)->replace('_', ' ')->ucfirst() }}</p>
                    <p class="text-lg font-semibold {{ $count > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-gray-950 dark:text-white' }}">{{ $count }}</p>
                </div>
            @endforeach
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">reliability:sweep re-queues held messages and fails interrupted sends and AI actions every five minutes.</p>
    </x-filament::section>

    <x-filament::section heading="Failed jobs ({{ $health['failed_jobs']['last_hour'] }} in the last hour, {{ $health['failed_jobs']['total'] }} kept)">
        <div class="divide-y divide-gray-100 dark:divide-white/5">
            @forelse ($health['failed_jobs']['recent'] as $failed)
                <div class="flex flex-wrap items-center justify-between gap-3 py-2">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-950 dark:text-white">{{ class_basename($failed['job']) }} <span class="text-xs text-gray-500">on {{ $failed['queue'] }} · {{ $failed['failed_at'] }}</span></p>
                        <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $failed['error'] }}</p>
                    </div>
                    <x-filament::button size="xs" color="gray" wire:click="mountAction('retryFailedJob', { uuid: '{{ $failed['uuid'] }}' })">Retry</x-filament::button>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">No failed jobs.</p>
            @endforelse
        </div>
        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Kept for {{ intdiv(config('queue.failed.retention_hours'), 24) }} days, then pruned. Exception text is redacted when stored.</p>
    </x-filament::section>

    <x-filament::section heading="Scheduler">
        <p class="mb-2 text-sm text-gray-500 dark:text-gray-400">
            Last task: {{ $health['scheduler']['last_tick'] ? \Illuminate\Support\Carbon::parse($health['scheduler']['last_tick'])->diffForHumans() : 'none recorded yet' }}
        </p>
        <div class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($health['scheduler']['tasks'] as $task)
                <div class="flex flex-wrap items-center justify-between gap-3 py-2">
                    <p class="text-sm text-gray-950 dark:text-white">{{ $task['task'] }} <span class="text-xs text-gray-500">{{ $task['expression'] }}</span></p>
                    <x-filament::badge :color="match ($task['outcome']) { 'finished' => 'success', 'failed' => 'danger', 'skipped' => 'warning', default => 'gray' }">
                        {{ $task['outcome'] ? ucfirst($task['outcome']).' '.\Illuminate\Support\Carbon::parse($task['at'])->diffForHumans() : 'Not run yet' }}
                    </x-filament::badge>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
