@props(['name', 'subtitle' => null])
<div class="flex items-center gap-2 py-0.5">
    <x-recruitment.avatar-initials :name="$name" />
    <div class="min-w-0">
        <p class="truncate font-medium text-gray-950 dark:text-white">{{ $name }}</p>
        @if ($subtitle)
            <p class="truncate text-xs text-gray-500 dark:text-gray-400">{{ $subtitle }}</p>
        @endif
    </div>
</div>
