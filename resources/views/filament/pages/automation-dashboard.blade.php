<x-filament-panels::page>
    @php
        $health = $this->getHealth();
        $analytics = $this->getAnalytics();
        $color = ['healthy' => 'success', 'warning' => 'warning', 'failed' => 'danger'][$health['status']];
    @endphp

    <x-filament::section>
        <x-slot name="heading">
            Automation health: <x-filament::badge :color="$color" class="ml-1 inline-flex">{{ ucfirst($health['status']) }}</x-filament::badge>
        </x-slot>
        <x-slot name="description">
            Last 7 days · {{ $health['backlog'] }} overdue run(s)@if ($health['queued_jobs'] !== null) · {{ $health['queued_jobs'] }} job(s) waiting on the automation queue @endif
        </x-slot>

        @if (empty($health['signals']))
            <p class="text-sm text-gray-600 dark:text-gray-300">No problems detected.</p>
        @else
            <ul class="space-y-1.5 text-sm">
                @foreach ($health['signals'] as $signal)
                    <li class="flex items-start gap-2">
                        <x-filament::badge :color="$signal['severity'] === 'failed' ? 'danger' : 'warning'" size="sm">{{ ucfirst($signal['severity']) }}</x-filament::badge>
                        <span class="text-gray-800 dark:text-gray-200">{{ $signal['message'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    <x-filament::section heading="Rules (last 30 days)">
        @if ($analytics['by_rule']->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-300">No rule has acted yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-gray-500 dark:text-gray-400">
                        <tr><th class="py-2 pr-4 font-medium">Rule</th><th class="py-2 pr-4 font-medium">Runs that acted</th><th class="py-2 pr-4 font-medium">Failed</th><th class="py-2 font-medium">Failure rate</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($analytics['by_rule'] as $row)
                            <tr class="text-gray-800 dark:text-gray-200">
                                <td class="py-2 pr-4">{{ $row['name'] }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $row['executions'] }}</td>
                                <td class="py-2 pr-4 tabular-nums">{{ $row['failed'] }}</td>
                                <td class="py-2 tabular-nums">{{ $row['failure_rate'] !== null ? $row['failure_rate'].'%' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">These figures describe what automation did. They are not evidence that automation caused a hiring outcome.</p>
    </x-filament::section>
</x-filament-panels::page>
