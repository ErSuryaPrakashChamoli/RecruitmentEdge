{{-- Definition, population, period, sample size and unknown handling for one outcome metric. --}}
<dl class="mt-3 grid gap-x-4 gap-y-1 text-xs text-gray-600 sm:grid-cols-[8rem_1fr] dark:text-gray-400">
    <dt class="font-medium text-gray-700 dark:text-gray-300">Definition</dt>
    <dd>{{ $metric['definition'] }}</dd>
    <dt class="font-medium text-gray-700 dark:text-gray-300">Population</dt>
    <dd>{{ $metric['population'] }} ({{ $period['from'] }} – {{ $period['to'] }})</dd>
    <dt class="font-medium text-gray-700 dark:text-gray-300">Sample size</dt>
    <dd>
        {{ $metric['sample_size'] }}
        <x-filament::badge :color="$metric['band']->color()" size="sm" class="ms-1 inline-flex">{{ $metric['band']->label() }}</x-filament::badge>
    </dd>
    <dt class="font-medium text-gray-700 dark:text-gray-300">Not observed</dt>
    <dd>{{ $metric['unknown'] }} — {{ $metric['unknown_handling'] }}</dd>
</dl>
