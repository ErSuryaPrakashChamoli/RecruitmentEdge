@props([
    'events',
    'emptyHeading' => 'No activity yet',
    'emptyDescription' => 'Events will appear here as the candidate moves through the pipeline.',
])

{{-- Shared rendering of CandidateTimelineService entries (Candidate 360 and application view). --}}
@if ($events->isEmpty())
    <x-recruitment.empty-state icon="heroicon-o-clock" :heading="$emptyHeading" :description="$emptyDescription" />
@else
    <div class="space-y-0">
        @foreach ($events as $event)
            <div class="flex gap-3 pb-4 last:pb-0">
                <div class="flex flex-col items-center">
                    <span @class([
                        'flex h-7 w-7 shrink-0 items-center justify-center rounded-full',
                        'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400' => $event['color'] === 'success',
                        'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400' => $event['color'] === 'warning',
                        'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400' => $event['color'] === 'danger',
                        'bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400' => $event['color'] === 'info',
                        'bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400' => $event['color'] === 'primary',
                        'bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400' => ! in_array($event['color'], ['success', 'warning', 'danger', 'info', 'primary'], true),
                    ])>
                        <x-filament::icon :icon="$event['icon']" class="h-3.5 w-3.5" />
                    </span>
                    @if (! $loop->last)
                        <span class="mt-1 w-px flex-1 bg-gray-100 dark:bg-white/5"></span>
                    @endif
                </div>

                <div class="min-w-0 flex-1 pb-1">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                        <p class="text-sm font-medium text-gray-950 dark:text-white">
                            {{ $event['title'] }}
                            @if (($event['visibility'] ?? 'internal') === 'candidate')
                                <x-filament::badge size="sm" color="info" class="ms-1 inline-flex">Visible to candidate</x-filament::badge>
                            @endif
                        </p>
                        <p class="text-xs text-gray-400 dark:text-gray-500">{{ $event['at']->format('d M Y, h:i A') }}</p>
                    </div>
                    @if ($event['subtitle'])
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $event['subtitle'] }}</p>
                    @endif
                    @if ($event['meta'])
                        <p class="mt-0.5 whitespace-pre-line text-xs text-gray-500 dark:text-gray-400">{{ $event['meta'] }}</p>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
@endif
