@php $load = $this->getInterviewerLoad(); @endphp

<div class="mt-6 rounded-xl border border-gray-200 p-4 dark:border-white/10">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm font-semibold">Interviewer Load &middot; {{ $load['label'] }}</p>
        <p class="text-xs text-gray-500 dark:text-gray-400">Daily capacity: {{ $load['capacity'] }} per interviewer</p>
    </div>

    @if ($load['rows']->isEmpty())
        <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">No interviews booked for this period.</p>
    @else
        <div class="mt-3 space-y-2">
            @foreach ($load['rows'] as $row)
                <div class="flex items-center gap-3">
                    <p class="w-40 truncate text-xs font-medium text-gray-700 dark:text-gray-300">{{ $row['name'] }}</p>
                    <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                        <div
                            @class([
                                'h-2 rounded-full',
                                'bg-rose-500' => $row['overbooked'],
                                'bg-amber-500' => ! $row['overbooked'] && $row['peak'] === $load['capacity'],
                                'bg-emerald-500' => ! $row['overbooked'] && $row['peak'] < $load['capacity'],
                            ])
                            style="width: {{ min(100, (int) round($row['peak'] / $load['capacity'] * 100)) }}%"
                        ></div>
                    </div>
                    <p class="w-24 text-right text-xs text-gray-500 dark:text-gray-400">
                        {{ $row['total'] }} {{ Str::plural('interview', $row['total']) }}
                    </p>
                    @if ($row['overbooked'])
                        <x-filament::badge color="danger" size="xs">Over-booked</x-filament::badge>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
