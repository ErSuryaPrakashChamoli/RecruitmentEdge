@php
    $employee = $node['employee'];
    $hasChildren = ! empty($node['children']);
@endphp

<div x-data="{ open: true }" class="{{ $depth > 0 ? 'ms-6 border-s border-gray-200 ps-4 dark:border-white/10' : '' }}">
    <div class="flex items-center justify-between gap-3 rounded-lg border border-gray-100 px-3 py-2 dark:border-white/5">
        <div class="flex min-w-0 items-center gap-2">
            @if ($hasChildren)
                <button type="button" x-on:click="open = ! open" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                    <x-filament::icon icon="heroicon-o-chevron-right" x-bind:class="open ? 'rotate-90' : ''" class="h-4 w-4 transition-transform" />
                </button>
            @else
                <span class="w-4"></span>
            @endif

            <span @class([
                'h-2 w-2 shrink-0 rounded-full',
                'bg-emerald-500' => $employee->status?->value === 'active',
                'bg-gray-300 dark:bg-gray-600' => $employee->status?->value !== 'active',
            ])></span>

            <div class="min-w-0">
                <p class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $employee->fullName() }}</p>
                <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                    {{ $employee->designation?->name ?? '—' }}
                    @if ($node['team_size'] > 0)
                        &middot; {{ $node['team_size'] }} {{ Str::plural('report', $node['team_size']) }}
                    @endif
                </p>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-1">
            <x-filament::icon-button
                icon="heroicon-o-user-group"
                label="Candidates"
                tooltip="Candidates"
                tag="a"
                :href="$this->candidatesUrl($employee->id)"
            />
            <x-filament::icon-button
                icon="heroicon-o-briefcase"
                label="Vacancies"
                tooltip="Vacancies"
                tag="a"
                :href="$this->vacanciesUrl($employee->id)"
            />
            <x-filament::icon-button
                icon="heroicon-o-chart-bar"
                label="Performance"
                tooltip="Performance"
                tag="a"
                :href="$this->performanceUrl($employee->id)"
            />
            @if ($this->canReassign())
                <x-filament::icon-button
                    icon="heroicon-o-arrows-right-left"
                    label="Reassign Manager"
                    tooltip="Reassign Manager"
                    wire:click="mountAction('reassignManager', { employeeId: {{ $employee->id }} })"
                />
            @endif
        </div>
    </div>

    @if ($hasChildren)
        <div x-show="open" class="mt-2 space-y-2">
            @foreach ($node['children'] as $child)
                @include('filament.pages.organization-hierarchy-node', ['node' => $child, 'depth' => $depth + 1])
            @endforeach
        </div>
    @endif
</div>
