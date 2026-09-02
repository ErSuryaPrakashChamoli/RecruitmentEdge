@props(['label', 'value', 'icon' => null, 'color' => 'default', 'trend' => null, 'trendLabel' => null, 'sparkline' => null, 'progress' => null])
@php
    // Literal Tailwind palette names on purpose, not text-success-*/bg-success-* utility classes:
    // those aren't compiled by the panel theme's arbitrary-class scanning (.ai/rules/providers-filament.md)
    // unless the literal class string is present in a scanned file — every widget in this app uses
    // the literal palette equivalents (emerald/amber/rose/blue) for the same reason.
    //
    // Each card is fully tinted (not a white card with a colored accent) — matching the reference
    // pattern the user supplied: every stat card carries its own pastel background, a soft icon
    // "bubble" sitting on top of it, and a light matching border. `default` gets a violet tint
    // rather than plain gray so a genuinely neutral metric (e.g. Avg. Time to Hire) still reads as
    // colorful, not washed out — violet carries no status meaning elsewhere in the app, so this is
    // purely decorative, never a stand-in for success/warning/danger/info.
    $cardClasses = match ($color) {
        'success' => 'bg-emerald-50 border-emerald-100 dark:bg-emerald-500/10 dark:border-emerald-500/20',
        'warning' => 'bg-amber-50 border-amber-100 dark:bg-amber-500/10 dark:border-amber-500/20',
        'danger' => 'bg-rose-50 border-rose-100 dark:bg-rose-500/10 dark:border-rose-500/20',
        'info' => 'bg-blue-50 border-blue-100 dark:bg-blue-500/10 dark:border-blue-500/20',
        default => 'bg-violet-50 border-violet-100 dark:bg-violet-500/10 dark:border-violet-500/20',
    };

    $iconClasses = match ($color) {
        'success' => 'bg-white/70 text-emerald-600 dark:bg-white/10 dark:text-emerald-400',
        'warning' => 'bg-white/70 text-amber-600 dark:bg-white/10 dark:text-amber-400',
        'danger' => 'bg-white/70 text-rose-600 dark:bg-white/10 dark:text-rose-400',
        'info' => 'bg-white/70 text-blue-600 dark:bg-white/10 dark:text-blue-400',
        default => 'bg-white/70 text-violet-600 dark:bg-white/10 dark:text-violet-400',
    };

    $strokeClasses = match ($color) {
        'success' => 'stroke-emerald-500',
        'warning' => 'stroke-amber-500',
        'danger' => 'stroke-rose-500',
        'info' => 'stroke-blue-500',
        default => 'stroke-violet-500',
    };

    $barClasses = match ($color) {
        'success' => 'bg-emerald-500',
        'warning' => 'bg-amber-500',
        'danger' => 'bg-rose-500',
        'info' => 'bg-blue-500',
        default => 'bg-violet-500',
    };

    $trendClasses = match (true) {
        $trend === null || $trend == 0 => 'text-gray-400 dark:text-gray-500',
        $trend > 0 => 'text-emerald-600 dark:text-emerald-400',
        default => 'text-rose-600 dark:text-rose-400',
    };

    // Sparkline: normalize the trend-series into a small inline polyline (no charting library —
    // this is a handful of points, an SVG dependency would be overkill).
    if ($sparkline !== null && count($sparkline) >= 2) {
        $sparkMin = min($sparkline);
        $sparkMax = max($sparkline);
        $sparkRange = max($sparkMax - $sparkMin, 1);
        $sparkWidth = 100;
        $sparkHeight = 28;
        $sparkStep = $sparkWidth / (count($sparkline) - 1);
        $sparkPoints = collect($sparkline)
            ->values()
            ->map(fn ($point, $index) => round($index * $sparkStep, 1).','.round($sparkHeight - (($point - $sparkMin) / $sparkRange) * $sparkHeight, 1))
            ->implode(' ');
    } else {
        $sparkPoints = null;
    }

    if ($progress !== null && ($progress['target'] ?? 0) > 0) {
        $progressPercent = min(100, round($progress['current'] / $progress['target'] * 100));
    } else {
        $progressPercent = null;
    }
@endphp

<div {{ $attributes->class([
    'group rounded-xl border p-4 shadow-sm transition-shadow hover:shadow-md',
    $cardClasses,
]) }}>
    <div class="flex items-start justify-between gap-2">
        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
        @if ($icon)
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg {{ $iconClasses }}">
                <x-filament::icon :icon="$icon" class="h-4 w-4" />
            </span>
        @endif
    </div>

    <div class="mt-1.5 flex items-end justify-between gap-2">
        <p class="text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">{{ $value }}</p>

        @if ($sparkPoints)
            <svg viewBox="0 0 100 28" preserveAspectRatio="none" class="h-7 w-16 shrink-0">
                <polyline
                    points="{{ $sparkPoints }}"
                    fill="none"
                    class="{{ $strokeClasses }}"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </svg>
        @endif
    </div>

    @if ($progressPercent !== null)
        <div class="mt-2">
            <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                <div class="h-full rounded-full {{ $barClasses }}" style="width: {{ $progressPercent }}%"></div>
            </div>
            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ $progress['current'] }} / {{ $progress['target'] }} ({{ $progressPercent }}%)</p>
        </div>
    @elseif ($trend !== null || $trendLabel)
        <p class="mt-1.5 flex items-center gap-1 text-xs {{ $trendClasses }}">
            @if ($trend !== null && $trend != 0)
                <x-filament::icon
                    :icon="$trend > 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down'"
                    class="h-3.5 w-3.5 shrink-0"
                />
                <span class="font-medium">{{ $trend > 0 ? '+' : '' }}{{ $trend }}%</span>
            @endif
            @if ($trendLabel)
                <span class="truncate text-gray-400 dark:text-gray-500">{{ $trendLabel }}</span>
            @endif
        </p>
    @endif
</div>
