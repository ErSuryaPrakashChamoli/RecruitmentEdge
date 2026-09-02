@props(['icon' => 'heroicon-o-inbox', 'heading', 'description' => null, 'compact' => false])

@if ($compact)
    <div {{ $attributes->class(['rounded-lg border border-dashed border-gray-300 py-4 text-center text-xs text-gray-500 dark:border-white/10 dark:text-gray-400']) }}>
        {{ $heading }}
        @if ($description)
            <span class="block text-gray-400 dark:text-gray-500">{{ $description }}</span>
        @endif
    </div>
@else
    <div {{ $attributes->class(['flex flex-col items-center justify-center gap-1 py-6 text-center']) }}>
        <x-filament::icon :icon="$icon" class="h-8 w-8 text-gray-300 dark:text-gray-600" />
        <p class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $heading }}</p>
        @if ($description)
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $description }}</p>
        @endif
    </div>
@endif
