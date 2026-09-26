<x-filament-panels::page>
    <x-filament::section heading="Test against a real record" description="Nothing is sent, created or changed. The result shows exactly what the rule would do right now.">
        <form wire:submit="run" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <label class="flex-1">
                <span class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">Record</span>
                <x-filament::input.wrapper :valid="! $errors->has('subjectId')">
                    <x-filament::input.select wire:model="subjectId" id="dry-run-subject">
                        <option value="">Choose a record…</option>
                        @foreach ($this->subjectOptions() as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                @error('subjectId') <span class="mt-1 block text-sm text-danger-600 dark:text-danger-400">{{ $message }}</span> @enderror
            </label>
            <x-filament::button type="submit" icon="heroicon-o-beaker">Run dry run</x-filament::button>
        </form>
    </x-filament::section>

    @if ($report)
        <x-filament::section>
            <x-slot name="heading">
                @if ($report['would_run'])
                    <span class="text-success-600 dark:text-success-400">Would run</span>
                @else
                    <span class="text-gray-600 dark:text-gray-300">Would not run</span>
                @endif
            </x-slot>
            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
                <div><dt class="text-gray-500 dark:text-gray-400">Trigger</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $report['trigger'] }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Scope ({{ $report['scope'] }})</dt><dd class="font-medium {{ $report['scope_matches'] ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">{{ $report['scope_matches'] ? '✓ In scope' : '✗ Out of scope' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Runs</dt><dd class="font-medium text-gray-950 dark:text-white">{{ $report['runs_at'] }}</dd></div>
            </dl>
            @foreach ($report['limits'] as $limit)
                <p class="mt-3 text-sm text-warning-600 dark:text-warning-400">{{ $limit }}</p>
            @endforeach
        </x-filament::section>

        <x-filament::section heading="Conditions">
            @if (empty($report['conditions']['results']))
                <p class="text-sm text-gray-500 dark:text-gray-400">No conditions — always continues.</p>
            @else
                <ul class="space-y-1.5 text-sm">
                    @foreach ($report['conditions']['results'] as $row)
                        <li class="flex items-start gap-2" style="padding-left: {{ $row['depth'] * 1.25 }}rem">
                            <span class="{{ $row['passed'] ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">{{ $row['passed'] ? '✓' : '✗' }}</span>
                            <span class="text-gray-950 dark:text-white">{{ $row['label'] }} {{ $row['operator'] }} {{ $row['expected'] }}</span>
                            <span class="text-gray-500 dark:text-gray-400">(actual: {{ $row['actual'] }})</span>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-3 text-sm font-medium {{ $report['conditions']['passed'] ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                    {{ $report['conditions']['passed'] ? 'Conditions met' : 'Conditions not met' }}
                </p>
            @endif
        </x-filament::section>

        <x-filament::section heading="Would execute">
            <ol class="list-decimal space-y-1.5 pl-5 text-sm text-gray-950 dark:text-white">
                @foreach ($report['actions'] as $action)
                    <li>
                        {{ $action['description'] }}
                        @foreach ($action['errors'] as $error)
                            <span class="block text-danger-600 dark:text-danger-400">{{ $error }}</span>
                        @endforeach
                    </li>
                @endforeach
            </ol>
        </x-filament::section>

        @if (! empty($report['escalation']))
            <x-filament::section heading="Escalation">
                <ul class="space-y-1.5 text-sm text-gray-950 dark:text-white">
                    @foreach ($report['escalation'] as $step)
                        <li>Step {{ $step['step'] }}: after {{ $step['after'] }} → {{ $step['target'] }} —
                            <span class="{{ $step['recipient'] ? '' : 'text-danger-600 dark:text-danger-400' }}">{{ $step['recipient'] ?? 'nobody active to receive it' }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
