@props(['name', 'size' => 'sm'])
@php
    $initials = collect(explode(' ', trim($name)))
        ->filter()
        ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->take(2)
        ->implode('');

    // A small fixed, literal-class palette (Tailwind-scan-safe — see kpi-stat.blade.php's same
    // comment) picked deterministically per name, so the same person always gets the same color.
    $palette = [
        'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-400',
        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
        'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
        'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-400',
        'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400',
        'bg-cyan-50 text-cyan-700 dark:bg-cyan-500/10 dark:text-cyan-400',
    ];
    $colorClasses = $palette[crc32($name) % count($palette)];

    $sizeClasses = $size === 'md' ? 'h-9 w-9 text-sm' : 'h-7 w-7 text-xs';
@endphp

<span {{ $attributes->class(["flex shrink-0 items-center justify-center rounded-full font-semibold {$sizeClasses} {$colorClasses}"]) }}>
    {{ $initials }}
</span>
