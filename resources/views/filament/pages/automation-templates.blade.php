<x-filament-panels::page>
    <p class="text-sm text-gray-600 dark:text-gray-300">
        Each template creates a <strong>draft</strong> rule you can adjust, test with a dry run and then activate. Templates never duplicate the messages candidates already receive automatically.
    </p>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($this->getTemplates() as $template)
            <x-filament::section :heading="$template['name']" :description="$template['category'].' · '.$template['trigger_label']" wire:key="template-{{ $template['key'] }}">
                <p class="text-sm text-gray-700 dark:text-gray-300">{{ $template['description'] }}</p>

                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                    <x-filament::badge color="gray">v{{ $template['version'] }}</x-filament::badge>
                    @if ($template['active'])
                        <x-filament::badge color="success">Active rule in use</x-filament::badge>
                    @elseif ($template['rules'] > 0)
                        <x-filament::badge color="warning">{{ $template['rules'] }} draft/paused rule(s)</x-filament::badge>
                    @endif
                    @if (! empty($template['replaces_alert']))
                        <x-filament::badge color="info">Replaces built-in alert when active</x-filament::badge>
                    @endif
                </div>

                @if ($this->canUse())
                    <x-slot name="footer">
                        <x-filament::button size="sm" icon="heroicon-o-plus" wire:click="useTemplate('{{ $template['key'] }}')">Use template</x-filament::button>
                    </x-slot>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
