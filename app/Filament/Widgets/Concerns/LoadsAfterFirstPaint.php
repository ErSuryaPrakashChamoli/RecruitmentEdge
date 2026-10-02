<?php

namespace App\Filament\Widgets\Concerns;

use Illuminate\Support\Arr;

/**
 * Phase 8.9 (P89-PERF-015, ED-09): a Command Center widget below the day's numbers loads after the
 * page's first paint. `lazy.bundle` = `on-load` starts every such widget as soon as the page has
 * loaded (not when scrolled into view) and bundles them into one request, not one request each —
 * so the dashboard still fills in a single pass, without holding the first paint until the
 * deepest analytics are computed.
 */
trait LoadsAfterFirstPaint
{
    /**
     * @return array<string, mixed>
     */
    public static function getDefaultProperties(): array
    {
        return [...Arr::except(parent::getDefaultProperties(), 'lazy'), 'lazy.bundle' => 'on-load'];
    }
}
