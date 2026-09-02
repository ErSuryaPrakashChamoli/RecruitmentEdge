@props(['label', 'value', 'color' => 'default', 'tint' => false])
@php
    // Literal Tailwind palette names on purpose, not text-success-*/text-danger-* utility
    // classes: those aren't compiled by the panel theme's arbitrary-class scanning here (see
    // .ai/rules/providers-filament.md) — every other widget in this app already uses the literal
    // palette equivalents (emerald/amber/rose/blue) for the same reason.
    //
    // Always tinted (the `$tint` prop is kept only so existing call sites don't need editing, but
    // no longer gates anything) — matching the reference pattern the user supplied, where every
    // summary card carries its own pastel color rather than a neutral gray box. `default` uses
    // violet: decorative only, not a stand-in for any status color used elsewhere in the app.
    $textColor = match ($color) {
        'success' => 'text-emerald-700 dark:text-emerald-400',
        'warning' => 'text-amber-700 dark:text-amber-400',
        'danger' => 'text-rose-700 dark:text-rose-400',
        'info' => 'text-blue-700 dark:text-blue-400',
        default => 'text-violet-700 dark:text-violet-400',
    };

    $background = match ($color) {
        'success' => 'bg-emerald-50 dark:bg-emerald-500/10',
        'warning' => 'bg-amber-50 dark:bg-amber-500/10',
        'danger' => 'bg-rose-50 dark:bg-rose-500/10',
        'info' => 'bg-blue-50 dark:bg-blue-500/10',
        default => 'bg-violet-50 dark:bg-violet-500/10',
    };
@endphp

<div {{ $attributes->class([$background, 'rounded-lg py-2 text-center']) }}>
    <p class="text-base font-semibold {{ $textColor }}">{{ $value }}</p>
    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</p>
</div>
